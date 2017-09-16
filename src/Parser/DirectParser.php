<?php declare(strict_types = 1);

namespace PHPStan\Parser;

use PhpParser\NodeTraverser;

class DirectParser implements Parser
{

	/**
	 * @var \PhpParser\Parser
	 */
	private $parser;

	/**
	 * @var \PhpParser\NodeTraverser
	 */
	private $traverser;

	public function __construct(\PhpParser\Parser $parser, NodeTraverser $traverser)
	{
		$this->parser = $parser;
		$this->traverser = $traverser;
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param string $sourceCode
	 * @param \PhpParser\ErrorHandler|null $errorHandler
	 * @return \PhpParser\Node[]
	 */
	public function parse($sourceCode, \PhpParser\ErrorHandler $errorHandler = null): array
	{
		$nodes = $this->parser->parse($sourceCode, $errorHandler);
		if ($nodes === null) {
			throw new \PHPStan\ShouldNotHappenException();
		}
		return $this->traverser->traverse($nodes);
	}

}
