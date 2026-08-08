<?php

namespace PactTraceSDK\SharedResources\TestCase\Scenario;

abstract class BaseScenario
{
	public function __construct(
		protected string $status = 'draft',
		protected string $planType = 'free',
	)
	{}

	public static function make(...$arguments): mixed
	{
		return (new static(...$arguments))->handle();
	}

	public function __invoke(): mixed
	{
		return $this->handle();
	}

	abstract public function handle(): mixed;

	public function collect(array $data)
	{
		return TestScenarioCollection::make($data);
	}
}
