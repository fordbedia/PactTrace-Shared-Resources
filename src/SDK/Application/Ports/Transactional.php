<?php

namespace PactTrackSDK\SharedResources\SDK\Application\Ports;

interface Transactional
{
	/**
     * Run the callback atomically.
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(callable $fn);
}