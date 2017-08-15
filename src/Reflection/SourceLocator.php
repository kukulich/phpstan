<?php declare(strict_types = 1);

namespace PHPStan\Reflection;

use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Identifier\Identifier;
use Roave\BetterReflection\Identifier\IdentifierType;
use Roave\BetterReflection\Reflection\Reflection;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Ast\Locator;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\ComposerSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\MemoizingSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;

class SourceLocator implements \Roave\BetterReflection\SourceLocator\Type\SourceLocator
{

	/**
	 * @var \Roave\BetterReflection\SourceLocator\Type\MemoizingSourceLocator
	 */
	private $memoizingSourceLocator;

	/**
	 * @param \Composer\Autoload\ClassLoader $composerClassLoader
	 * @param string[] $analyzedFiles
	 * @param \PhpParser\Parser $parser
	 */
	public function __construct(\Composer\Autoload\ClassLoader $composerClassLoader, array $analyzedFiles, \PhpParser\Parser $parser)
	{
		$sourceLocators = [];

		$astLocator = new Locator($parser);

		$sourceLocators[] = new ComposerSourceLocator($composerClassLoader, $astLocator);
		$sourceLocators[] = new PhpInternalSourceLocator($astLocator);
		foreach ($analyzedFiles as $analyzedFile) {
			$sourceLocators[] = new SingleFileSourceLocator($analyzedFile, $astLocator);
		}

		$this->memoizingSourceLocator = new MemoizingSourceLocator(new AggregateSourceLocator($sourceLocators));
	}

	public function locateIdentifier(Reflector $reflector, Identifier $identifier) : ?Reflection
	{
		return $this->memoizingSourceLocator->locateIdentifier($reflector, $identifier);
	}

	public function locateIdentifiersByType(Reflector $reflector, IdentifierType $identifierType) : array
	{
		return $this->memoizingSourceLocator->locateIdentifiersByType($reflector, $identifierType);
	}

}
