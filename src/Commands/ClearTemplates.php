<?php

declare(strict_types=1);

namespace Crab\Arc\Commands;

use Crab\Arc\Arc;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class ClearTemplates implements Command
{
    public function __construct(
        private Arc $arc,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Delete the compiled Arc templates

            They are compiled again when next rendered, or by arc:cache.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $count = $this->arc->clear();
        $output->line("Deleted {$count} compiled template" . ($count === 1 ? '' : 's') . '.');
        return 0;
    }
}
