<?php

namespace PactTrackSDK\SharedResources\Modules\Signature\Tests\Application\Services;

use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use Illuminate\Support\Facades\Cache;

class EnvelopeNotifierTest extends BaseTest
{
	protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}