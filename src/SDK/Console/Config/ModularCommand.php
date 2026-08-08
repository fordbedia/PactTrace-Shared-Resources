<?php

namespace PactTraceSDK\SharedResources\SDK\Console\Config;

use Illuminate\Console\Command;
use ReflectionClass;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

abstract class ModularCommand extends Command
{
    protected string $shortName;
    protected string $reflectionFileName;
    protected string $reflectionClassName;
    protected string $type;
    protected object $ref;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->ref = $this->identify();

        $hot = new OutputFormatterStyle(
            'red',        // foreground
            '',     // background
            ['bold','underscore']
        );
        $output->getFormatter()->setStyle('hot', $hot);

        $success = new OutputFormatterStyle(
            'white',        // foreground
            'green',     // background
            ['bold','underscore']
        );
        $output->getFormatter()->setStyle('success', $success);
    }

    /**
     * @throws \Exception
     */
    protected function inProgressInfo($prompt = ''): void
    {
        if ($this->ref->commandType === 'make') {
            $msg = $prompt ?: "Creating {$this->className} In Progress...";
        } else if ($this->ref->commandType === 'reset') {
            $msg = $prompt ?: "Resetting test tables... In Progress...";
        } else {
            throw new \Exception("Can't find command type.");
        }

        $this->info("<hot>{$msg}</hot>");
    }

    protected function completed(string $prompt = ''): void
    {
        if ($this->ref->commandType === 'make') {
            $msg = $prompt ?: "Migration successfully created.";
        } else if ($this->ref->commadType === 'reset') {
            $msg = $prompt ?: "All test rows has been reset.";
        } else {
            throw new \Exception("Can't find command type");
        }

        if ($prompt) {
            $this->line($msg);
        } else {
            $this->line("<success>{$msg}</success>");
        }

    }

    /**
     * @return object
     *
     */
    private function identify(): object
    {
        $ref     = new \ReflectionClass($this);
        $mapping = [
            'shortName'   => fn() => $ref->getShortName(),
            // strip down to just "Make", not the full path
            'reflectionFileName'    => fn() => $ref->getShortName(),
            'reflectionClassName'   => fn() => $ref->getName(),
            'commandType' => fn() => $this->commandType(),
        ];

        $data = [];
        foreach ($mapping as $key => $getter) {
            $data[$key] = $getter();
        }

        // hydrate if you still need to store them on the object
        $this->shortName           = $data['shortName'];
        $this->reflectionFileName  = $data['reflectionFileName'];
        $this->reflectionClassName = $data['reflectionClassName'];

        return (object) $data;
    }

    // ==============================================================
    // Need this to identify which command type is being ran
    // ==============================================================
    abstract protected function commandType(): string;
}
