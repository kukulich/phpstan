<?php declare(strict_types = 1);

namespace PHPStan\Parser;

use PhpParser\ErrorHandler;

class CachedParser implements Parser
{

	/** @var \PHPStan\Parser\Parser */
	private $originalParser;

	/** @var mixed[] */
	private $cachedNodesByString = [];

	public function __construct(Parser $originalParser)
	{
		$this->originalParser = $originalParser;
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param string $sourceCode
	 * @param \PhpParser\ErrorHandler|null $errorHandler
	 * @return \PhpParser\Node[]
	 */
	public function parse($sourceCode, \PhpParser\ErrorHandler $errorHandler = null): array
	{
		if (!isset($this->cachedNodesByString[$sourceCode])) {
			$this->cachedNodesByString[$sourceCode] = $this->originalParser->parse($sourceCode, $errorHandler);
		}

		return $this->cachedNodesByString[$sourceCode];
	}
}
