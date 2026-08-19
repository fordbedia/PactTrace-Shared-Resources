<?php

namespace PactTrackSDK\SharedResources\SDK\Infrastructure\Transactions;

use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;
use Illuminate\Support\Facades\DB;

class EloquentDBTransaction implements Transactional
{
	public function run(callable $fn)
	{
		return DB::transaction($fn);
	}
}