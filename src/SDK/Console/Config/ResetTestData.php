<?php

namespace PactTrackSDK\SharedResources\SDK\Console\Config;

use PactTrackSDK\SharedResources\Modules\Invoicing\Database\Seeders\CurrencySeeder;
use PactTrackSDK\SharedResources\Modules\Invoicing\Database\Seeders\InvoiceTemplateCategorySeeder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PactTrackSDK\SharedResources\Modules\Invoicing\Database\Seeders\TestInvoiceSeeder;
use PactTrackSDK\SharedResources\Modules\User\Database\Seeders\UserSeeder;

class ResetTestData extends ModularResetTestDataCommand
{
    protected $signature = 'pacttrack:reset 
        {--testonly : Refreshed only the test data and exclude stable data.}';
    protected $description = 'Restart all test data.';

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
    }

    protected function process(): array
    {
        return [
			UserSeeder::class,
			InvoiceTemplateCategorySeeder::class,
			CurrencySeeder::class,
			TestInvoiceSeeder::class,
        ];
    }

    protected function commandType(): string
    {
        return 'reset';
    }
}
