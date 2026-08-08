<?php

namespace PactTraceSDK\SharedResources\TestCase\Migrations;

use PactTraceSDK\SharedResources\TestCase\BaseTest as PackageBaseTest;
use PactTraceSDK\SharedResources\TestCase\Extras\RefreshDatabase;

abstract class BaseTest extends PackageBaseTest
{
	use RefreshDatabase;
}
