<?php

namespace PactTraceSDK\SharedResources\SDK\Infrastructure\Transactions;

use PactTraceSDK\SharedResources\SDK\Application\Ports\Transactional;
use Illuminate\Support\Facades\DB;

class EloquentDBTransaction implements Transactional
{
	public function run(callable $fn)
	{
		return DB::transaction($fn);
	}
}