<?php

declare(strict_types=1);

namespace Crab\Arc;

use Crab\Http\Response;

/**
 * Renders templates from one folder. A view "orders.index" is the file
 * orders/index.arc.php. A component is a capitalised tag, never HTML's:
 * <Card> is components/card.arc.php, <ProductGrid> components/product-grid.arc.php
 * and <Forms.TextField> components/forms/text-field.arc.php.
 *
 * Each template is compiled once into the cache folder, under a name made
 * from its content, its component dependencies and the compiler's version.
 * An edited component also recompiles its callers in a new worker. A worker
 * loads each compiled closure once and keeps it: rendering is one call,
 * with no file check. A worker keeps what it has loaded until it ends, and
 * the development server restarts workers when a watched file changes.
 */
final class Arc
{
    /**
     * @var array<string, \Closure(self, array<string, mixed>): string>
     */
    private array $views = [];

    /**
     * @var array<string, \Closure>
     */
    private array $components = [];

    private readonly Compiler $compiler;

    /**
     * Whether a component's file exists, for the compiler.
     *
     * @var \Closure(string): bool
     */
    private readonly \Closure $exists;
    /**
     * @var \Closure(string): ?array{parameters: string, file: string, line: int}
     */
    private readonly \Closure $props;

    /**
     * @param string $path The folder of the templates.
     * @param string $cache The folder the compiled templates are written to.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $cache,
    ) {
        $this->compiler = new Compiler();
        $this->exists = fn(string $name): bool => (
            self::validName($name) && is_file($this->path . '/components/' . str_replace('.', '/', $name) . '.arc.php')
        );
        $this->props = function (string $name): ?array {
            if (!self::validName($name)) {
                return null;
            }
            $file = $this->path . '/components/' . str_replace('.', '/', $name) . '.arc.php';
            $source = @file_get_contents($file);
            if ($source === false) {
                return null;
            }
            $declaration = Compiler::propsDeclaration($source);
            return (
                $declaration === null
                    ? null
                    : [
                        'parameters' => $declaration['parameters'],
                        'file' => $file,
                        'line' => $declaration['line'],
                    ]
            );
        };
    }

    /**
     * The view's HTML.
     *
     * @param array<string, mixed> $data Its variables, by name.
     */
    public function render(string $view, array $data = []): string
    {
        return ($this->views[$view] ??= $this->load($view, false))($this, $data);
    }

    /**
     * The view as an HTML response.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $headers
     */
    public function response(string $view, array $data = [], int $status = 200, array $headers = []): Response
    {
        return Response::html(($this->views[$view] ??= $this->load($view, false))($this, $data), $status, $headers);
    }

    /**
     * A component's compiled closure; compiled templates call it.
     *
     * @internal
     */
    public function component(string $name): \Closure
    {
        return $this->components[$name] ??= $this->load('components.' . $name, true);
    }

    /**
     * Compiles every template in the folder ahead of the first request,
     * and returns how many there are.
     */
    public function compileAll(): int
    {
        $count = 0;
        foreach ($this->templates() as $name) {
            $this->compiled($name, str_starts_with($name, 'components.'));
            $count++;
        }
        return $count;
    }

    /**
     * Deletes the compiled templates and returns how many there were.
     */
    public function clear(): int
    {
        $count = 0;
        $files = glob($this->cache . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            $count += (int) @unlink($file);
        }
        $indexes = glob($this->cache . '/*.deps.json');
        foreach ($indexes === false ? [] : $indexes as $file) {
            @unlink($file);
        }
        return $count;
    }

    private function load(string $name, bool $component): \Closure
    {
        $closure = require $this->compiled($name, $component);
        if (!$closure instanceof \Closure) {
            throw new ArcException("The compiled template of {$name} does not return a closure.");
        }
        return $closure;
    }

    /**
     * The compiled file of a template, compiled first when there is none for its content.
     */
    private function compiled(string $name, bool $component): string
    {
        if (!self::validName($name)) {
            throw new ArcException(
                "\"{$name}\" is not a template name: use lowercase words joined by dots, such as orders.index.",
            );
        }
        $file = $this->path . '/' . str_replace('.', '/', $name) . '.arc.php';
        $source = @file_get_contents($file);
        if ($source === false) {
            throw new ArcException(
                ($component ? 'The component <' . substr($name, 11) . '>' : "The view {$name}")
                . " has no template at {$file}.",
            );
        }
        $identity = Compiler::VERSION . "\0" . $file . "\0" . $source;
        $index = $this->cache . '/' . $name . '.' . hash('xxh128', $identity) . '.deps.json';
        $manifest = @file_get_contents($index);
        $dependencies = json_decode($manifest === false ? 'null' : $manifest, true);
        $code = null;
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            $code = $this->compiler->compile($source, $file, $component, $this->exists, $this->props);
            $dependencies = $this->compiler->dependencies();
        }
        // Only first load (or the deployment command) touches dependencies. The index
        // is determined by this source; component contents determine the artifact name.
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !self::validName($dependency)) {
                throw new ArcException("Invalid component dependency in {$index}.");
            }
            $dependencyFile = $this->path . '/components/' . str_replace('.', '/', $dependency) . '.arc.php';
            $dependencySource = @file_get_contents($dependencyFile);
            if ($dependencySource === false) {
                throw new ArcException("The component <{$dependency}> has no template at {$dependencyFile}.");
            }
            $identity .= "\0" . $dependency . "\0" . hash('xxh128', $dependencySource);
        }
        $compiled = $this->cache . '/' . $name . '.' . hash('xxh128', $identity) . '.php';
        if (!is_file($compiled)) {
            $code ??= $this->compiler->compile($source, $file, $component, $this->exists, $this->props);
            $this->write($compiled, $code);
        }
        if ($code !== null) {
            $this->write($index, json_encode($dependencies, JSON_THROW_ON_ERROR));
        }
        return $compiled;
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*(\.[a-z0-9][a-z0-9_-]*)*$/', $name) === 1;
    }

    private function write(string $file, string $code): void
    {
        if (!is_dir($this->cache) && !@mkdir($this->cache, 0o775, true) && !is_dir($this->cache)) {
            throw new ArcException("Cannot create the folder for compiled templates, {$this->cache}.");
        }
        // Written aside and renamed into place, so another worker never includes half a file.
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $code) !== strlen($code)) {
            @unlink($temporary);
            throw new ArcException("Cannot write the compiled template {$file}.");
        }
        if (!@rename($temporary, $file) && !is_file($file)) {
            @unlink($temporary);
            throw new ArcException("Cannot write the compiled template {$file}.");
        }
        @unlink($temporary);
    }

    /**
     * Every template's name.
     *
     * @return list<string>
     */
    private function templates(): array
    {
        if (!is_dir($this->path)) {
            throw new ArcException("The template folder {$this->path} does not exist.");
        }
        $names = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.arc.php')) {
                $names[] = str_replace('/', '.', substr($path, strlen($this->path) + 1, -8));
            }
        }
        sort($names);
        return $names;
    }
}
