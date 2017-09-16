<?php declare(strict_types = 1);

namespace PHPStan\Parser;

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
	 * @param string $sourceCode
	 * @return \PhpParser\Node[]
	 */
	public function parse(string $sourceCode): array
	{
		if (!isset($this->cachedNodesByString[$sourceCode])) {
			$this->cachedNodesByString[$sourceCode] = $this->originalParser->parse($sourceCode);
		}

		return $this->cachedNodesByString[$sourceCode];
	}

}
