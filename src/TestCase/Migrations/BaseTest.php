<?php

namespace PactTrackSDK\SharedResources\TestCase\Migrations;

use PactTrackSDK\SharedResources\TestCase\BaseTest as PackageBaseTest;
use PactTrackSDK\SharedResources\TestCase\Extras\RefreshDatabase;

abstract class BaseTest extends PackageBaseTest
{
	use RefreshDatabase;
}
