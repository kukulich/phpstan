<?php declare(strict_types = 1);

namespace PHPStan\Parser;

interface Parser
{

	/**
	 * @param string $sourceCode
	 * @return \PhpParser\Node[]
	 */
	public function parse(string $sourceCode): array;

}
