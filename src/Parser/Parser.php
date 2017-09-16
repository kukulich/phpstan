<?php declare(strict_types = 1);

namespace PHPStan\Parser;

interface Parser extends \PhpParser\Parser
{

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param string $sourceCode
	 * @param \PhpParser\ErrorHandler $errorHandler
	 * @return \PhpParser\Node[]
	 */
	public function parse($sourceCode, \PhpParser\ErrorHandler $errorHandler = null): array;

}
