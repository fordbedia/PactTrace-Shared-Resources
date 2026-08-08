<?php

namespace PactTraceSDK\SharedResources\SDK\Console\Config;

use http\Exception\RuntimeException;

abstract class ModularResetTestDataCommand extends ModularCommand
{
    protected $signature = 'pacttrace:reset 
        {--testonly : Refreshed only the test data and exclude stable data.}';

    protected $description = 'Restart all test data.';

    protected ?string $testonly;

    public function handle()
    {
        if (config('app.env') === 'local') {
            $this->instantiateAllProcess();
        } else {
            throw new \RuntimeException('This command is not supported. Please run `ModularResetTestDataCommand` on local only.');
        }
    }

    /**
     * @param array $processes
     */
    protected function processAll(array $processes): void
    {
        foreach($processes as $process) {
            (new $process)->run();
        }
    }

    /**
     * @param array $processes
     */
    protected function revert(array $processes): void
    {
        foreach($processes as $process) {
            (new $process)->revert();
        }
    }

    /**
     * @param array $processes
     * @return array
     */
    protected function filterProcess(array $processes): array
    {
        if ($this->testonly) {
            $processes = array_diff($processes,
                [
									//
                ]
            );
        }

        return $processes;
    }

    protected function instantiateAllProcess()
    {
        $this->testonly = $this->option('testonly');

        $seeders = $this->process();
        $processes = [];

        foreach($seeders as $seed) {
            $processes[] = $seed;
        }
        $processes = $this->filterProcess($processes);

        // ==============================================================
        // Truncate all test data across all tables
        // ==============================================================
        $this->revert($processes);
        // ==============================================================
        // All test data has been removed, and now we seed back
        // ==============================================================
        $this->processAll($processes);
    }

    // ==============================================================
    // This executes all seeders to restart test data across tables
    // ==============================================================
    abstract protected function process(): array;
}
