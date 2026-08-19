<?php

namespace PactTrackSDK\SharedResources\SDK\Database;

use Illuminate\Database\Seeder;

abstract class MakeSeeder extends Seeder
{
    /**
     * @return mixed
     */
     abstract public function run();

    /**
     * @return mixed
     */
     abstract public function revert();

     public function clearDB()
     {

     }
}
