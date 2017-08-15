<?php declare(strict_types = 1);

namespace PHPStan\Reflection;

use PHPStan\Type\Type;

interface FunctionReflectionFactory
{

	/**
	 * @param \Roave\BetterReflection\Reflection\ReflectionFunction|\ReflectionFunction $reflection
	 * @param array $phpDocParameterTypes
	 * @param \PHPStan\Type\Type|null $phpDocReturnType
	 * @return \PHPStan\Reflection\FunctionReflection
	 */
	public function create(
		$reflection,
		array $phpDocParameterTypes,
		Type $phpDocReturnType = null
	): FunctionReflection;

}
