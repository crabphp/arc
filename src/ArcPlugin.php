<?php

declare(strict_types=1);

namespace Crab\Arc;

use Crab\Arc\Commands\CacheTemplates;
use Crab\Arc\Commands\ClearTemplates;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;

/**
 * Binds the Arc renderer, which view() uses, adds the "arc" settings and
 * the arc:cache and arc:clear commands. It adds no middleware.
 */
final class ArcPlugin implements Plugin
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'arc',
            ['path' => 'resources/views', 'cache' => null],
            [
                'path' => ['type' => 'string', 'minLength' => 1],
                'cache' => ['type' => ['string', 'null'], 'minLength' => 1],
            ],
        );

        $container->singleton(Arc::class, static function (Container $container): Arc {
            $config = $container->get(Config::class);
            $config->check('arc');
            $base = $config->get('app.path');
            $resolve = static fn(string $path): string => is_string($base)
                && $base !== ''
                && !str_starts_with($path, '/')
                    ? rtrim($base, '/') . '/' . $path
                    : $path;
            $cache = $config->get('arc.cache');
            return new Arc(
                $resolve((string) $config->get('arc.path')),
                is_string($cache) ? $resolve($cache) : rtrim((string) $config->get('app.storage'), '/') . '/arc',
            );
        });

        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('arc:cache', CacheTemplates::class);
            $console->add('arc:clear', ClearTemplates::class);
        }
    }
}
