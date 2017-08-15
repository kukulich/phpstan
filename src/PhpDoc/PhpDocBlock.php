<?php declare(strict_types = 1);

namespace PHPStan\PhpDoc;

use PHPStan\Broker\Broker;
use PHPStan\Reflection\ClassReflection;

class PhpDocBlock
{

	/** @var string */
	private $docComment;

	/** @var string */
	private $file;

	private function __construct(string $docComment, string $file)
	{
		$this->docComment = $docComment;
		$this->file = $file;
	}

	public function getDocComment(): string
	{
		return $this->docComment;
	}

	public function getFile(): string
	{
		return $this->file;
	}

	public static function resolvePhpDocBlockForProperty(
		Broker $broker,
		string $docComment,
		string $class,
		string $propertyName,
		string $file
	): self
	{
		return self::resolvePhpDocBlock(
			$broker,
			$docComment,
			$class,
			$propertyName,
			$file,
			'hasProperty',
			'getProperty',
			__FUNCTION__
		);
	}

	public static function resolvePhpDocBlockForMethod(
		Broker $broker,
		string $docComment,
		string $class,
		string $methodName,
		string $file
	): self
	{
		return self::resolvePhpDocBlock(
			$broker,
			$docComment,
			$class,
			$methodName,
			$file,
			'hasMethod',
			'getMethod',
			__FUNCTION__
		);
	}

	private static function resolvePhpDocBlock(
		Broker $broker,
		string $docComment,
		string $class,
		string $name,
		string $file,
		string $hasMethodName,
		string $getMethodName,
		string $resolveMethodName
	): self
	{
		if (
			preg_match('#\{@inheritdoc\}#i', $docComment) > 0
			&& $broker->hasClass($class)
		) {
			$classReflection = $broker->getClass($class);
			if ($classReflection->getParentClass() !== null) {
				$parentClassReflection = $classReflection->getParentClass();
				$phpDocBlockFromClass = self::resolvePhpDocBlockFromClass(
					$broker,
					$parentClassReflection,
					$name,
					$hasMethodName,
					$getMethodName,
					$resolveMethodName
				);
				if ($phpDocBlockFromClass !== null) {
					return $phpDocBlockFromClass;
				}
			}

			foreach ($classReflection->getInterfaces() as $interface) {
				$phpDocBlockFromClass = self::resolvePhpDocBlockFromClass(
					$broker,
					$interface,
					$name,
					$hasMethodName,
					$getMethodName,
					$resolveMethodName
				);
				if ($phpDocBlockFromClass !== null) {
					return $phpDocBlockFromClass;
				}
			}
		}

		return new self($docComment, $file);
	}

	/**
	 * @param \PHPStan\Broker\Broker $broker
	 * @param \PHPStan\Reflection\ClassReflection $classReflection
	 * @param string $name
	 * @param string $hasMethodName
	 * @param string $getMethodName
	 * @param string $resolveMethodName
	 * @return self|null
	 */
	private static function resolvePhpDocBlockFromClass(
		Broker $broker,
		ClassReflection $classReflection,
		string $name,
		string $hasMethodName,
		string $getMethodName,
		string $resolveMethodName
	)
	{
		if (!$classReflection->isInternal() && $classReflection->$hasMethodName($name)) {
			$parentMethodReflection = $classReflection->$getMethodName($name);
			if ($parentMethodReflection->getDocComment() !== '') {
				return self::$resolveMethodName(
					$broker,
					$parentMethodReflection->getDocComment(),
					$classReflection->getName(),
					$name,
					$classReflection->getFileName()
				);
			}
		}

		return null;
	}

}
