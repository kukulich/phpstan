<?php declare(strict_types = 1);

namespace PHPStan\Broker;

use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Cache\Cache;
use PHPStan\Reflection\FunctionReflectionFactory;
use PHPStan\Type\FileTypeMapper;

class BrokerTest extends \PHPStan\TestCase
{

	/** @var \PHPStan\Broker\Broker */
	private $broker;

	protected function setUp()
	{
		$this->broker = new Broker(
			[],
			[],
			[],
			[],
			$this->createMock(FunctionReflectionFactory::class),
			new FileTypeMapper($this->getParser(), $this->createMock(Cache::class))
		);
	}

	public function testClassNotFound()
	{
		$this->expectException(\PHPStan\Broker\ClassNotFoundException::class);
		$this->expectExceptionMessage('NonexistentClass');
		$this->broker->getClass('NonexistentClass');
	}

	public function testFunctionNotFound()
	{
		$this->expectException(\PHPStan\Broker\FunctionNotFoundException::class);
		$this->expectExceptionMessage('Function nonexistentFunction not found while trying to analyse it - autoloading is probably not configured properly.');

		$scope = $this->createMock(Scope::class);
		$scope->method('getNamespace')
			->willReturn(null);
		$this->broker->getFunction(new Name('nonexistentFunction'), $scope);
	}

}
