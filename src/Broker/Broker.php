<?php declare(strict_types = 1);

namespace PHPStan\Broker;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\BrokerAwareClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflectionFactory;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\TypehintHelper;
use ReflectionClass;

class Broker
{

	/** @var \PHPStan\Reflection\PropertiesClassReflectionExtension[] */
	private $propertiesClassReflectionExtensions;

	/** @var \PHPStan\Reflection\MethodsClassReflectionExtension[] */
	private $methodsClassReflectionExtensions;

	/** @var \PHPStan\Type\DynamicMethodReturnTypeExtension[] */
	private $dynamicMethodReturnTypeExtensions = [];

	/** @var \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[] */
	private $dynamicStaticMethodReturnTypeExtensions = [];

	/** @var \PHPStan\Reflection\ClassReflection[]|null[] */
	private $classReflections = [];

	/** @var \PHPStan\Reflection\FunctionReflectionFactory */
	private $functionReflectionFactory;

	/** @var \PHPStan\Type\FileTypeMapper */
	private $fileTypeMapper;

	/** @var \PHPStan\Reflection\FunctionReflection[] */
	private $functionReflections = [];

	/** @var null|self */
	private static $instance;

	/** @var \Roave\BetterReflection\Reflector\ClassReflector|null */
	private $classReflector;

	/** @var \Roave\BetterReflection\Reflector\FunctionReflector|null */
	private $functionReflector;

	/**
	 * @param \PHPStan\Reflection\PropertiesClassReflectionExtension[] $propertiesClassReflectionExtensions
	 * @param \PHPStan\Reflection\MethodsClassReflectionExtension[] $methodsClassReflectionExtensions
	 * @param \PHPStan\Type\DynamicMethodReturnTypeExtension[] $dynamicMethodReturnTypeExtensions
	 * @param \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[] $dynamicStaticMethodReturnTypeExtensions
	 * @param \PHPStan\Reflection\FunctionReflectionFactory $functionReflectionFactory
	 * @param \PHPStan\Type\FileTypeMapper $fileTypeMapper
	 */
	public function __construct(
		array $propertiesClassReflectionExtensions,
		array $methodsClassReflectionExtensions,
		array $dynamicMethodReturnTypeExtensions,
		array $dynamicStaticMethodReturnTypeExtensions,
		FunctionReflectionFactory $functionReflectionFactory,
		FileTypeMapper $fileTypeMapper
	)
	{
		$this->propertiesClassReflectionExtensions = $propertiesClassReflectionExtensions;
		$this->methodsClassReflectionExtensions = $methodsClassReflectionExtensions;
		foreach (array_merge($propertiesClassReflectionExtensions, $methodsClassReflectionExtensions, $dynamicMethodReturnTypeExtensions) as $extension) {
			if ($extension instanceof BrokerAwareClassReflectionExtension) {
				$extension->setBroker($this);
			}
		}

		foreach ($dynamicMethodReturnTypeExtensions as $dynamicMethodReturnTypeExtension) {
			$this->dynamicMethodReturnTypeExtensions[$dynamicMethodReturnTypeExtension->getClass()][] = $dynamicMethodReturnTypeExtension;
		}

		foreach ($dynamicStaticMethodReturnTypeExtensions as $dynamicStaticMethodReturnTypeExtension) {
			$this->dynamicStaticMethodReturnTypeExtensions[$dynamicStaticMethodReturnTypeExtension->getClass()][] = $dynamicStaticMethodReturnTypeExtension;
		}

		$this->functionReflectionFactory = $functionReflectionFactory;
		$this->fileTypeMapper = $fileTypeMapper;

		self::$instance = $this;
	}

	public static function getInstance(): self
	{
		assert(self::$instance !== null);
		return self::$instance;
	}

	public function setClassReflector(\Roave\BetterReflection\Reflector\ClassReflector $classReflector)
	{
		$this->classReflector = $classReflector;
	}

	public function setFunctionReflector(\Roave\BetterReflection\Reflector\FunctionReflector $functionReflector)
	{
		$this->functionReflector = $functionReflector;
	}

	/**
	 * @param string $className
	 * @return \PHPStan\Type\DynamicMethodReturnTypeExtension[]
	 */
	public function getDynamicMethodReturnTypeExtensionsForClass(string $className): array
	{
		return $this->getDynamicExtensionsForType($this->dynamicMethodReturnTypeExtensions, $className);
	}

	/**
	 * @param string $className
	 * @return \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[]
	 */
	public function getDynamicStaticMethodReturnTypeExtensionsForClass(string $className): array
	{
		return $this->getDynamicExtensionsForType($this->dynamicStaticMethodReturnTypeExtensions, $className);
	}

	/**
	 * @param \PHPStan\Type\DynamicMethodReturnTypeExtension[]|\PHPStan\Type\DynamicStaticMethodReturnTypeExtension[] $extensions
	 * @param string $className
	 * @return mixed[]
	 */
	private function getDynamicExtensionsForType(array $extensions, string $className): array
	{
		$extensionsForClass = [];
		$class = $this->getClass($className);
		foreach (array_merge([$className], $class->getParentClassesNames(), $class->getInterfaceNames()) as $extensionClassName) {
			if (!isset($extensions[$extensionClassName])) {
				continue;
			}

			$extensionsForClass = array_merge($extensionsForClass, $extensions[$extensionClassName]);
		}

		return $extensionsForClass;
	}

	public function getAnonymousClass(\PhpParser\Node\Stmt\ClassLike $node, string $file): \PHPStan\Reflection\ClassReflection
	{
		/** @var \Roave\BetterReflection\Reflector\ClassReflector $classReflector */
		$classReflector = $this->classReflector;
		$reflectionClass = \Roave\BetterReflection\Reflection\ReflectionClass::createFromNode(
			$classReflector,
			$node,
			new \Roave\BetterReflection\SourceLocator\Located\LocatedSource(file_get_contents($file), $file)
		);

		$className = $reflectionClass->getName();
		/** @var \PHPStan\Reflection\ClassReflection $classReflection */
		$classReflection = $this->getClassFromReflection($reflectionClass, $className);
		$this->classReflections[$className] = $classReflection;
		return $classReflection;
	}

	public function getClass(string $className): \PHPStan\Reflection\ClassReflection
	{
		$class = $this->findClass($className);
		if ($class === null) {
			throw new \PHPStan\Broker\ClassNotFoundException($className);
		}

		return $class;
	}

	/**
	 * @param string $className
	 * @return \PHPStan\Reflection\ClassReflection|null
	 */
	private function findClass(string $className)
	{
		if (!array_key_exists($className, $this->classReflections)) {
			try {
				/** @var \Roave\BetterReflection\Reflector\ClassReflector $classReflector */
				$classReflector = $this->classReflector;
				/** @var \Roave\BetterReflection\Reflection\ReflectionClass $reflectionClass */
				$reflectionClass = $classReflector->reflect($className);

				$classReflection = $this->getClassFromReflection($reflectionClass, $reflectionClass->getName());
				$this->classReflections[$className] = $classReflection;
				if ($className !== $reflectionClass->getName()) {
					// class alias optimization
					$this->classReflections[$reflectionClass->getName()] = $classReflection;
				}
			} catch (\Roave\BetterReflection\Reflector\Exception\IdentifierNotFound $e) {
				$this->classReflections[$className] = null;
			}
		}

		return $this->classReflections[$className];
	}

	public function getClassFromReflection(\Roave\BetterReflection\Reflection\ReflectionClass $reflectionClass, string $displayName): \PHPStan\Reflection\ClassReflection
	{
		return new ClassReflection(
			$this,
			$this->propertiesClassReflectionExtensions,
			$this->methodsClassReflectionExtensions,
			$displayName,
			$reflectionClass
		);
	}

	public function hasClass(string $className): bool
	{
		return $this->findClass($className) !== null;
	}

	public function getFunction(\PhpParser\Node\Name $nameNode, Scope $scope = null): \PHPStan\Reflection\FunctionReflection
	{
		$functionName = $this->resolveFunctionName($nameNode, $scope);
		if ($functionName === null) {
			throw new \PHPStan\Broker\FunctionNotFoundException((string) $nameNode);
		}

		$lowerCasedFunctionName = strtolower($functionName);
		if (!isset($this->functionReflections[$lowerCasedFunctionName])) {
			/** @var \Roave\BetterReflection\Reflector\FunctionReflector $functionReflector */
			$functionReflector = $this->functionReflector;

			try {
				// Try internal function first
				$reflectionFunction = new \ReflectionFunction($lowerCasedFunctionName);
				if ($reflectionFunction->isUserDefined()) {
					/** @var \Roave\BetterReflection\Reflection\ReflectionFunction $reflectionFunction */
					$reflectionFunction = $functionReflector->reflect($functionName);
				}
			} catch (\ReflectionException $e) {
				/** @var \Roave\BetterReflection\Reflection\ReflectionFunction $reflectionFunction */
				$reflectionFunction = $functionReflector->reflect($functionName);
			}

			$phpDocParameterTypes = [];
			$phpDocReturnType = null;
			if (!$reflectionFunction->isInternal() && $reflectionFunction->getDocComment() !== '') {
				$fileTypeMap = $this->fileTypeMapper->getTypeMap($reflectionFunction->getFileName());
				$docComment = $reflectionFunction->getDocComment();
				$phpDocParameterTypes = TypehintHelper::getParameterTypesFromPhpDoc(
					$fileTypeMap,
					array_map(function (\Roave\BetterReflection\Reflection\ReflectionParameter $parameter): string {
						return $parameter->getName();
					}, $reflectionFunction->getParameters()),
					$docComment
				);
				$phpDocReturnType = TypehintHelper::getReturnTypeFromPhpDoc($fileTypeMap, $docComment);
			}

			$this->functionReflections[$lowerCasedFunctionName] = $this->functionReflectionFactory->create(
				$reflectionFunction,
				$phpDocParameterTypes,
				$phpDocReturnType
			);
		}

		return $this->functionReflections[$lowerCasedFunctionName];
	}

	public function hasFunction(\PhpParser\Node\Name $nameNode, Scope $scope = null): bool
	{
		return $this->resolveFunctionName($nameNode, $scope) !== null;
	}

	/**
	 * @param \PhpParser\Node\Name $nameNode
	 * @param \PHPStan\Analyser\Scope|null $scope
	 * @return string|null
	 */
	public function resolveFunctionName(\PhpParser\Node\Name $nameNode, Scope $scope = null)
	{
		return $this->resolveName($nameNode, function (string $name): bool {
			return function_exists($name);
		}, $scope);
	}

	public function hasConstant(\PhpParser\Node\Name $nameNode, Scope $scope): bool
	{
		return $this->resolveConstantName($nameNode, $scope) !== null;
	}

	/**
	 * @param \PhpParser\Node\Name $nameNode
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return string|null
	 */
	public function resolveConstantName(\PhpParser\Node\Name $nameNode, Scope $scope)
	{
		return $this->resolveName($nameNode, function (string $name): bool {
			return defined($name);
		}, $scope);
	}

	/**
	 * @param \PhpParser\Node\Name $nameNode
	 * @param \Closure $existsCallback
	 * @param \PHPStan\Analyser\Scope|null $scope
	 * @return string|null
	 */
	private function resolveName(
		\PhpParser\Node\Name $nameNode,
		\Closure $existsCallback,
		Scope $scope = null
	)
	{
		$name = (string) $nameNode;
		if ($scope !== null && $scope->getNamespace() !== null && !$nameNode->isFullyQualified()) {
			$namespacedName = sprintf('%s\\%s', $scope->getNamespace(), $name);
			if ($existsCallback($namespacedName)) {
				return $namespacedName;
			}
		}

		if ($existsCallback($name)) {
			return $name;
		}

		return null;
	}

}
