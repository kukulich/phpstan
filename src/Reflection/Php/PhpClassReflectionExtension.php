<?php declare(strict_types = 1);

namespace PHPStan\Reflection\Php;

use PHPStan\Broker\Broker;
use PHPStan\PhpDoc\PhpDocBlock;
use PHPStan\Reflection\BrokerAwareClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\MixedType;
use PHPStan\Type\TypehintHelper;

class PhpClassReflectionExtension
	implements PropertiesClassReflectionExtension, MethodsClassReflectionExtension, BrokerAwareClassReflectionExtension
{

	/** @var \PHPStan\Reflection\Php\PhpMethodReflectionFactory */
	private $methodReflectionFactory;

	/** @var \PHPStan\Type\FileTypeMapper */
	private $fileTypeMapper;

	/** @var \PHPStan\Broker\Broker */
	private $broker;

	/** @var \PHPStan\Reflection\PropertyReflection[][] */
	private $properties = [];

	/** @var \PHPStan\Reflection\MethodReflection[][] */
	private $methods = [];

	public function __construct(
		PhpMethodReflectionFactory $methodReflectionFactory,
		FileTypeMapper $fileTypeMapper
	)
	{
		$this->methodReflectionFactory = $methodReflectionFactory;
		$this->fileTypeMapper = $fileTypeMapper;
	}

	public function setBroker(Broker $broker)
	{
		$this->broker = $broker;
	}

	public function hasProperty(ClassReflection $classReflection, string $propertyName): bool
	{
		return $classReflection->hasProperty($propertyName);
	}

	public function getProperty(ClassReflection $classReflection, string $propertyName): PropertyReflection
	{
		if (!isset($this->properties[$classReflection->getName()])) {
			$this->properties[$classReflection->getName()] = $this->createProperties($classReflection);
		}

		return $this->properties[$classReflection->getName()][$propertyName];
	}

	/**
	 * @param \PHPStan\Reflection\ClassReflection $classReflection
	 * @return \PHPStan\Reflection\PropertyReflection[]
	 */
	private function createProperties(ClassReflection $classReflection): array
	{
		$properties = [];
		foreach ($classReflection->getProperties() as $propertyReflection) {
			$propertyName = $propertyReflection->getName();
			$declaringClassReflection = $this->broker->getClass($propertyReflection->getDeclaringClass()->getName());
			if ($propertyReflection->getDocComment() === false) {
				$type = new MixedType();
			} elseif (!$declaringClassReflection->isAnonymous() && !$declaringClassReflection->isInternal()) {
				/** @var string $fileName */
				$fileName = $declaringClassReflection->getFileName();
				$phpDocBlock = PhpDocBlock::resolvePhpDocBlockForProperty(
					$this->broker,
					$propertyReflection->getDocComment(),
					$declaringClassReflection->getName(),
					$propertyName,
					$fileName
				);
				$typeMap = $this->fileTypeMapper->getTypeMap($phpDocBlock->getFile());
				$typeString = $this->getPropertyAnnotationTypeString($phpDocBlock->getDocComment());
				if (isset($typeMap[$typeString])) {
					$type = $typeMap[$typeString];
				} else {
					$type = new MixedType();
				}
			} else {
				$type = new MixedType();
			}

			$properties[$propertyName] = new PhpPropertyReflection(
				$declaringClassReflection,
				$type,
				$propertyReflection
			);
		}

		return $properties;
	}

	/**
	 * @param string $phpDoc
	 * @return string|null
	 */
	private function getPropertyAnnotationTypeString(string $phpDoc)
	{
		$count = preg_match_all('#@var\s+' . FileTypeMapper::TYPE_PATTERN . '#', $phpDoc, $matches);
		if ($count !== 1) {
			return null;
		}

		return $matches[1][0];
	}

	public function hasMethod(ClassReflection $classReflection, string $methodName): bool
	{
		return $this->findMethod($classReflection, $methodName) !== null;
	}

	public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
	{
		$method = $this->findMethod($classReflection, $methodName);

		if ($method === null) {
			throw new \InvalidArgumentException(sprintf('Method %s::%s doesn\'t exist', $classReflection->getName(), $methodName));
		}

		return $method;
	}

	/**
	 * @param \PHPStan\Reflection\ClassReflection $classReflection
	 * @param string $methodName
	 * @return \PHPStan\Reflection\MethodReflection|null
	 */
	private function findMethod(ClassReflection $classReflection, string $methodName)
	{
		$className = $classReflection->getName();

		if (!array_key_exists($className, $this->methods)) {
			$this->methods[$className] = $this->createMethods($classReflection);
		}

		if (array_key_exists($methodName, $this->methods[$className])) {
			return $this->methods[$className][$methodName];
		}

		$lowercasedMethodName = strtolower($methodName);
		foreach ($this->methods[$className] as $currentMethodName => $currentMethod) {
			if ($lowercasedMethodName === strtolower($currentMethodName)) {
				$this->methods[$className][$methodName] = $currentMethod;
				return $currentMethod;
			}
		}

		return null;
	}

	/**
	 * @param \PHPStan\Reflection\ClassReflection $classReflection
	 * @return \PHPStan\Reflection\MethodReflection[]
	 */
	private function createMethods(ClassReflection $classReflection): array
	{
		$methods = [];
		$reflectionMethods = $classReflection->getMethods();
		if ($classReflection->getName() === \Closure::class || $classReflection->isSubclassOf(\Closure::class)) {
			$hasInvokeMethod = false;
			foreach ($reflectionMethods as $reflectionMethod) {
				if ($reflectionMethod->getName() === '__invoke') {
					$hasInvokeMethod = true;
					break;
				}
			}
			if (!$hasInvokeMethod) {
				$reflectionMethods[] = $classReflection->getMethod('__invoke');
			}
		}
		foreach ($reflectionMethods as $methodReflection) {
			$declaringClass = $this->broker->getClass($methodReflection->getDeclaringClass()->getName());

			$phpDocParameterTypes = [];
			$phpDocReturnType = null;
			if (!$declaringClass->isAnonymous() && !$declaringClass->isInternal()) {
				if ($methodReflection->getDocComment() !== false) {
					/** @var string $fileName */
					$fileName = $declaringClass->getFileName();
					$phpDocBlock = PhpDocBlock::resolvePhpDocBlockForMethod(
						$this->broker,
						$methodReflection->getDocComment(),
						$declaringClass->getName(),
						$methodReflection->getName(),
						$fileName
					);
					$typeMap = $this->fileTypeMapper->getTypeMap($phpDocBlock->getFile());
					$phpDocParameterTypes = TypehintHelper::getParameterTypesFromPhpDoc(
						$typeMap,
						array_map(function (\ReflectionParameter $parameterReflection): string {
							return $parameterReflection->getName();
						}, $methodReflection->getParameters()),
						$phpDocBlock->getDocComment()
					);
					$phpDocReturnType = TypehintHelper::getReturnTypeFromPhpDoc($typeMap, $phpDocBlock->getDocComment());
				}
			}

			$methods[$methodReflection->getName()] = $this->methodReflectionFactory->create(
				$declaringClass,
				$methodReflection,
				$phpDocParameterTypes,
				$phpDocReturnType
			);
		}

		foreach ($classReflection->getTraits() as $traitReflection) {
			foreach ($traitReflection->getTraitAliases() as $methodNameAlias => $methodInfo) {
				list(, $methodName) = explode('::', $methodInfo);
				$methods[$methodNameAlias] = $methods[$methodName];
			}
		}

		return $methods;
	}

}
