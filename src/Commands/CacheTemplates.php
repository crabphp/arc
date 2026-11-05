<?php

declare(strict_types=1);

namespace Crab\Arc\Commands;

use Crab\Arc\Arc;
use Crab\Arc\ArcException;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class CacheTemplates implements Command
{
    public function __construct(
        private Arc $arc,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Compile every Arc template ahead of the first request

            Run it when deploying, so that no worker compiles a template
            while it serves. A template that does not compile fails it.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        try {
            $count = $this->arc->compileAll();
        } catch (ArcException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line("Compiled {$count} template" . ($count === 1 ? '' : 's') . '.');
        return 0;
    }
}
