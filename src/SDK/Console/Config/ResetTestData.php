<?php

namespace PactTraceSDK\SharedResources\SDK\Console\Config;

use PactTraceSDK\SharedResources\Modules\Invoicing\Database\Seeders\CurrencySeeder;
use PactTraceSDK\SharedResources\Modules\Invoicing\Database\Seeders\InvoiceTemplateCategorySeeder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PactTraceSDK\SharedResources\Modules\Invoicing\Database\Seeders\TestInvoiceSeeder;
use PactTraceSDK\SharedResources\Modules\User\Database\Seeders\UserSeeder;

class ResetTestData extends ModularResetTestDataCommand
{
    protected $signature = 'pacttrace:reset 
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
