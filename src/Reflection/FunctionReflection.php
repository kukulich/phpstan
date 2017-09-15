<?php declare(strict_types = 1);

namespace PHPStan\Reflection;

use PhpParser\Node\Stmt\Function_;
use PHPStan\Cache\Cache;
use PHPStan\Parser\FunctionCallStatementFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\Php\DummyParameter;
use PHPStan\Reflection\Php\PhpParameterReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypehintHelper;

class FunctionReflection implements ParametersAcceptor
{

	/** @var \Roave\BetterReflection\Reflection\ReflectionFunction|\ReflectionFunction */
	private $reflection;

	/** @var \PHPStan\Parser\Parser */
	private $parser;

	/** @var \PHPStan\Parser\FunctionCallStatementFinder */
	private $functionCallStatementFinder;

	/** @var \PHPStan\Cache\Cache */
	private $cache;

	/** @var \PHPStan\Type\Type[] */
	private $phpDocParameterTypes;

	/** @var \PHPStan\Type\Type|null */
	private $phpDocReturnType;

	/** @var \PHPStan\Reflection\ParameterReflection[] */
	private $parameters;

	/** @var \PHPStan\Type\Type */
	private $returnType;

	/**
	 * @param \Roave\BetterReflection\Reflection\ReflectionFunction|\ReflectionFunction $reflection
	 * @param \PHPStan\Parser\Parser $parser
	 * @param \PHPStan\Parser\FunctionCallStatementFinder $functionCallStatementFinder
	 * @param \PHPStan\Cache\Cache $cache
	 * @param array $phpDocParameterTypes
	 * @param \PHPStan\Type\Type|null $phpDocReturnType
	 */
	public function __construct(
		$reflection,
		Parser $parser,
		FunctionCallStatementFinder $functionCallStatementFinder,
		Cache $cache,
		array $phpDocParameterTypes,
		Type $phpDocReturnType = null
	)
	{
		$this->reflection = $reflection;
		$this->parser = $parser;
		$this->functionCallStatementFinder = $functionCallStatementFinder;
		$this->cache = $cache;
		$this->phpDocParameterTypes = $phpDocParameterTypes;
		$this->phpDocReturnType = $phpDocReturnType;
	}

	public function getName(): string
	{
		return $this->reflection->getName();
	}

	/**
	 * @return \PHPStan\Reflection\ParameterReflection[]
	 */
	public function getParameters(): array
	{
		if ($this->parameters === null) {
			$this->parameters = array_map(function ($reflection) {
				return new PhpParameterReflection(
					$reflection,
					isset($this->phpDocParameterTypes[$reflection->getName()]) ? $this->phpDocParameterTypes[$reflection->getName()] : null
				);
			}, $this->reflection->getParameters());
			if (
				$this->reflection->getName() === 'array_unique'
				&& count($this->parameters) === 1
			) {
				// PHP bug #70960
				$this->parameters[] = new DummyParameter(
					'sort_flags',
					new IntegerType(),
					true
				);
			}
			if (
				$this->reflection->getName() === 'fputcsv'
				&& count($this->parameters) === 4
			) {
				$this->parameters[] = new DummyParameter(
					'escape_char',
					new StringType(),
					true
				);
			}
			if (
				$this->reflection->getName() === 'unpack'
				&& PHP_VERSION_ID >= 70101
			) {
				$this->parameters[2] = new DummyParameter(
					'offset',
					new IntegerType(),
					true
				);
			}
			if (
				$this->reflection->getName() === 'imagepng'
				&& count($this->parameters) === 2
			) {
				$this->parameters[] = new DummyParameter(
					'quality',
					new IntegerType(),
					true
				);
				$this->parameters[] = new DummyParameter(
					'filters',
					new IntegerType(),
					true
				);
			}

			if (
				$this->reflection->getName() === 'session_start'
				&& count($this->parameters) === 0
			) {
				$this->parameters[] = new DummyParameter(
					'options',
					new ArrayType(new MixedType()),
					true
				);
			}

			if ($this->reflection->getName() === 'locale_get_display_language') {
				$this->parameters[1] = new DummyParameter(
					'in_locale',
					new StringType(),
					true
				);
			}

			if (
				$this->reflection->getName() === 'imagewebp'
				&& count($this->parameters) === 2
			) {
				$this->parameters[] = new DummyParameter(
					'quality',
					new IntegerType(),
					true
				);
			}

			if (
				$this->reflection->getName() === 'setproctitle'
				&& count($this->parameters) === 0
			) {
				$this->parameters[] = new DummyParameter(
					'title',
					new StringType(),
					false
				);
			}

			if (
				$this->reflection->getName() === 'get_class'
			) {
				$this->parameters = [
					new DummyParameter(
						'object',
						new ObjectWithoutClassType(),
						true
					),
				];
			}
		}

		return $this->parameters;
	}

	public function isVariadic(): bool
	{
		$isNativelyVariadic = $this->reflection->isVariadic();

		if ($isNativelyVariadic || $this->reflection->isInternal()) {
			return $isNativelyVariadic;
		}

		$key = sprintf('variadic-function-%s-v0', $this->reflection->getName());
		$cachedResult = $this->cache->load($key);
		if ($cachedResult !== null) {
			return $cachedResult;
		}

		/** @var \Roave\BetterReflection\Reflection\ReflectionFunction $reflection */
		$reflection = $this->reflection;
		/** @var \PhpParser\Node\Stmt\Function_ $node */
		$node = $reflection->getAst();
		$result = $this->callsFuncGetArgs($node);
		$this->cache->save($key, $result);
		return $result;
	}

	private function callsFuncGetArgs(Function_ $node): bool
	{
		return $this->functionCallStatementFinder->findFunctionCallInStatements(self::VARIADIC_FUNCTIONS, $node->getStmts()) !== null;
	}

	public function getReturnType(): Type
	{
		if ($this->returnType === null) {
			if ($this->reflection->getName() === 'count') {
				return $this->returnType = new IntegerType();
			}
			$returnType = $this->reflection->getReturnType();
			$phpDocReturnType = $this->phpDocReturnType;
			if (
				$returnType !== null
				&& $phpDocReturnType !== null
				&& $returnType->allowsNull() !== TypeCombinator::containsNull($phpDocReturnType)
			) {
				$phpDocReturnType = null;
			}
			$this->returnType = TypehintHelper::decideTypeFromReflection(
				$returnType,
				$phpDocReturnType
			);
		}

		return $this->returnType;
	}

}
