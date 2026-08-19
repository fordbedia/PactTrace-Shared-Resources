<?php

namespace PactTrackSDK\SharedResources\SDK\Traits;

trait AttributesCallable
{
	public function __get(string $name)
	{
		if (property_exists($this, $name)) {
			return $this->{$name};
		}
		throw new \LogicException(sprintf('Unknown criteria property "%s".', $key));
	}
}