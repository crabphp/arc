<?php

declare(strict_types=1);

namespace Crab\Arc;

/**
 * Turns one template's source into a PHP file that returns a closure.
 *
 * A view compiles to fn(Arc, array $data): string, a component to
 * fn(Arc, ...its props, string $slot = ''): string. Either builds its HTML
 * by appending to a local string, never with echo and output buffering: a
 * worker serves many requests at once in fibers, and an output buffer
 * belongs to the whole process, so a render that waited would mix pages.
 * {{ }} becomes an inline htmlspecialchars(), a component a direct call to
 * its closure with named arguments, and its slots strings rendered before
 * the call. Adjacent text and values are joined into one append.
 */
final class Compiler
{
    /**
     * Part of every compiled file's name: a new compiler compiles again.
     */
    public const string VERSION = '12';

    private const string MARKS = '/\{\{--|@\{\{|@@|\{!!|\{\{|<\?php|<\?=|<\/?[A-Z][A-Za-z0-9]*(?:\.[A-Z][A-Za-z0-9]*)*|@[a-z]+/';

    private const array WITH_ARGUMENTS = ['if', 'elseif', 'unless', 'foreach', 'for', 'while', 'props'];
    private const array WITHOUT_ARGUMENTS = [
        'else',
        'endif',
        'endunless',
        'endforeach',
        'endfor',
        'endwhile',
        'break',
        'continue',
    ];

    private string $source = '';
    private string $file = '';
    private bool $component = false;
    private int $position = 0;
    private string $code = '';
    private int $codeLine = 1;
    /**
     * @var array<int, int> Generated body lines mapped to template lines.
     */
    private array $lines = [];
    private int $propsLine = 1;
    private int $argumentsLine = 1;
    /**
     * Pending parts of the current append: [true, text, null] or
     * [false, expression, component line or null], followed by the source line.
     *
     * @var list<array{bool, string, int|null, int}>
     */
    private array $parts = [];
    /**
     * Output variables, innermost last: the view's, then each open component's or slot's.
     *
     * @var list<string>
     */
    private array $outputs = [];
    /**
     * Open @if, @foreach and the like: [kind, line, has else].
     *
     * @var list<array{string, int, bool}>
     */
    private array $blocks = [];
    /**
     * Open component and slot tags.
     *
     * @var list<array{kind: string, name: string, line: int, output: string, props: array<string, string>, slots: array<string, string>, blocks: int, initialize?: int, conditional?: array<string, string>}>
     */
    private array $tags = [];
    /**
     * The variable holding each component used, by name.
     *
     * @var array<string, string>
     */
    private array $components = [];
    private ?string $props = null;
    /**
     * @var array<string, array{required: bool, variadic: bool}>
     */
    private array $definitions = [];
    /**
     * @var array<string, array<string, array{required: bool, variadic: bool}>>
     */
    private array $componentDefinitions = [];
    private int $counter = 0;
    /**
     * @var \Closure(string): bool
     */
    private \Closure $exists;
    /**
     * @var \Closure(string): ?array{parameters: string, file: string, line: int}|null
     */
    private ?\Closure $propsFor = null;
    /**
     * @var array<string, bool>
     */
    private array $known = [];

    /**
     * @param string $file The template's path, for messages.
     * @param bool $component Whether it is a component, which takes props rather than data.
     * @param \Closure(string): bool $exists Whether a component's file exists, by its file name such as product-grid.
     * @param (\Closure(string): ?array{parameters: string, file: string, line: int})|null $propsFor A component's @props declaration.
     */
    public function compile(
        string $source,
        string $file,
        bool $component,
        \Closure $exists,
        ?\Closure $propsFor = null,
    ): string {
        $this->exists = $exists;
        $this->propsFor = $propsFor;
        $this->known = [];
        $this->source = $source;
        $this->file = $file;
        $this->component = $component;
        $this->position = 0;
        $this->code = '';
        $this->codeLine = 1;
        $this->lines = [];
        $this->propsLine = 1;
        $this->argumentsLine = 1;
        $this->parts = [];
        $this->outputs = ['$__o'];
        $this->blocks = [];
        $this->tags = [];
        $this->components = [];
        $this->props = null;
        $this->definitions = [];
        $this->componentDefinitions = [];
        $this->counter = 0;

        $this->body();
        $this->flush();
        if ($this->tags !== []) {
            $tag = end($this->tags);
            throw ArcException::at($file, $tag['line'], "<{$tag['name']}> is not closed");
        }
        if ($this->blocks !== []) {
            [$kind, $line] = end($this->blocks);
            throw ArcException::at($file, $line, "@{$kind} is not closed");
        }

        $hoisted = '';
        foreach ($this->components as $name => $variable) {
            $hoisted .= "    {$variable} = \$__arc->component(" . self::literal($name) . ");\n";
        }
        $from = str_replace('*/', '* /', $file);
        if ($component) {
            $parameters = $this->props ?? '';
            if (!isset($this->definitions['slot'])) {
                $parameters = trim($parameters) === '' ? 'string $slot = \'\'' : $parameters . ', string $slot = \'\'';
            }
            $head = "static function (\\Crab\\Arc\\Arc \$__arc, {$parameters}): string {\n";
        } else {
            $head = "static function (\\Crab\\Arc\\Arc \$__arc, array \$__data): string {\n    \\extract(\$__data, \\EXTR_SKIP);\n";
        }
        $prefix = "<?php\n\n/* Compiled by Arc from {$from}; do not edit. */\n";
        $lines = [];
        $headLine = substr_count($prefix, "\n") + 3;
        for ($i = 0; $i < substr_count($head, "\n"); $i++) {
            $lines[$headLine + $i] = $this->propsLine + $i;
        }
        $bodyPrefix = "\n\nreturn {$head}{$hoisted}    \$__o = '';\n";
        $offset = substr_count($prefix . $bodyPrefix, "\n");
        foreach ($this->lines as $generated => $original) {
            $lines[$offset + $generated] = $original;
        }
        $code =
            $prefix
            . '/* Arc source lines: '
            . json_encode($lines, JSON_THROW_ON_ERROR)
            . ' */'
            . $bodyPrefix
            . $this->code
            . "    return \$__o;\n};\n";
        self::validate($code, $file);
        return $code;
    }

    /**
     * Compiles generated PHP without executing it. PHP compile fatals cannot be
     * caught in-process, so lint in a separate CLI process before publishing or loading.
     */
    public static function validate(string $code, string $file): void
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw ArcException::runtime($file, self::sourceLine($code, $error->getLine()), $error);
        }
        if (!function_exists('proc_open')) {
            throw ArcException::at($file, 1, 'PHP compilation validation requires proc_open and the PHP CLI');
        }
        // A file of its own, named on the command line: a shared stream handed to the child as its
        // standard input is not read the same way on every platform, and empty input lints clean.
        $path = @tempnam(sys_get_temp_dir(), 'arc-lint-');
        if ($path === false) {
            throw ArcException::at($file, 1, 'Cannot create a temporary file for PHP compilation validation');
        }
        try {
            if (@file_put_contents($path, $code) !== strlen($code)) {
                throw ArcException::at($file, 1, 'Cannot write the PHP compilation validation input');
            }
            $process = @proc_open(
                [PHP_BINARY, '-n', '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-l', $path],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
            );
            if ($process === false) {
                throw ArcException::at($file, 1, 'Cannot start the PHP CLI for compilation validation');
            }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
        } finally {
            @unlink($path);
        }
        if ($status === 0) {
            return;
        }
        if (preg_match(
            '/^(?:PHP )?(?:Fatal|Parse) error:\s*(.*?) in \S+ on line (\d+)$/ms',
            (string) $output,
            $match,
        )) {
            throw ArcException::at($file, self::sourceLine($code, (int) $match[2]), $match[1]);
        }
        throw ArcException::at($file, 1, 'PHP compilation validation failed: ' . trim((string) $output));
    }

    private static function sourceLine(string $code, int $generatedLine): int
    {
        $line = 1;
        if (preg_match('/^\/\* Arc source lines: (.+) \*\/$/m', $code, $match)) {
            $lines = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            foreach ($lines as $generated => $original) {
                if ($generated > $generatedLine) {
                    break;
                }
                $line = $original;
            }
        }
        return $line;
    }

    private function emit(string $code, int $line, bool $multiline = false): void
    {
        $this->code .= $code;
        for ($i = 0; $i < substr_count($code, "\n"); $i++) {
            $this->lines[$this->codeLine++] = $line + ($multiline ? $i : 0);
        }
    }

    /**
     * The components referenced by the last compilation, including calls inside branches.
     *
     * @return list<string>
     */
    public function dependencies(): array
    {
        return array_keys($this->components);
    }

    private function body(): void
    {
        while (preg_match(self::MARKS, $this->source, $match, PREG_OFFSET_CAPTURE, $this->position) === 1) {
            [$mark, $at] = $match[0];
            $text = substr($this->source, $this->position, $at - $this->position);
            $this->position = $at + strlen($mark);
            // A line holding only a statement, such as @foreach or <Slot name="head">, leaves nothing behind.
            $next = $this->standalone($mark, $at);
            if ($next !== null) {
                $text = rtrim($text, " \t");
            }
            if ($text !== '') {
                $this->text($text);
            }
            match (true) {
                $mark === '{{--' => $this->skipComment($at),
                $mark === '@{{' => $this->text('{{'),
                $mark === '@@' => $this->text('@'),
                $mark === '{!!' => $this->value($at, '!!}', false),
                $mark === '{{' => $this->value($at, '}}', true),
                $mark === '<?php' => $this->php($at),
                $mark === '<?=' => throw $this->error(
                    $at,
                    'Write {{ $value }} or {!! $html !!} in place of <?=, which would echo outside the page',
                ),
                str_starts_with($mark, '</') => $this->closeTag($at, substr($mark, 2)),
                str_starts_with($mark, '<') => $this->openTag($at, substr($mark, 1)),
                default => $this->directive($at, substr($mark, 1)),
            };
            if ($next !== null) {
                $this->position = $next;
            }
        }
        if ($this->position < strlen($this->source)) {
            $this->text(substr($this->source, $this->position));
            $this->position = strlen($this->source);
        }
    }

    /**
     * Where to go on from when the token is a statement alone on its line: past
     * the end of that line. Null when it is not: a value, a component that closes
     * itself, unknown @ text, or a statement with other text on its line.
     */
    private function standalone(string $mark, int $at): ?int
    {
        $start = $at === 0 ? false : strrpos($this->source, "\n", $at - strlen($this->source) - 1);
        $start = $start === false ? 0 : $start + 1;
        if (trim(substr($this->source, $start, $at - $start), " \t") !== '') {
            return null;
        }
        $saved = $this->position;
        try {
            $end = match (true) {
                $mark === '{{--' => ($close = strpos($this->source, '--}}', $saved)) === false ? null : $close + 4,
                $mark === '<?php' => ($close = self::phpEnd($this->source, $saved, '?>')) === false ? null : $close + 2,
                $mark === '<?=' => null,
                str_starts_with($mark, '</') => ($this->source[$this->position] ?? '') === '>'
                    ? $this->position + 1
                    : null,
                str_starts_with($mark, '<') => $this->openTagEnd($at, substr($mark, 1)),
                str_starts_with($mark, '@') && in_array(substr($mark, 1), self::WITH_ARGUMENTS, true)
                    => $this->arguments($at, substr($mark, 1)) !== '' ? $this->position : null,
                str_starts_with($mark, '@') && in_array(substr($mark, 1), self::WITHOUT_ARGUMENTS, true) => $saved,
                default => null,
            };
        } finally {
            $this->position = $saved;
        }
        if ($end === null) {
            return null;
        }
        $newline = strpos($this->source, "\n", $end);
        $rest = substr($this->source, $end, ($newline === false ? strlen($this->source) : $newline) - $end);
        if (trim($rest, " \t\r") !== '') {
            return null;
        }
        return $newline === false ? strlen($this->source) : $newline + 1;
    }

    /**
     * Where an opening tag ends, or null for a component that closes itself, which is output, not a statement.
     */
    private function openTagEnd(int $at, string $name): ?int
    {
        [, $selfClosing] = $this->attributes($at);
        return $selfClosing && $name !== 'Slot' ? null : $this->position;
    }

    private function skipComment(int $at): void
    {
        $end = strpos($this->source, '--}}', $this->position);
        if ($end === false) {
            throw $this->error($at, 'The comment {{-- is not closed with --}}');
        }
        $this->position = $end + 4;
    }

    private function value(int $at, string $close, bool $escape): void
    {
        $end = self::phpEnd($this->source, $this->position, $close);
        if ($end === false) {
            throw $this->error($at, 'The value is not closed with ' . $close);
        }
        $inner = substr($this->source, $this->position, $end - $this->position);
        $line = $this->line($this->position + strspn($inner, " \t\r\n"));
        $expression = trim($inner);
        $this->position = $end + strlen($close);
        if ($expression === '') {
            throw $this->error($at, 'The value is empty');
        }
        $this->parts[] = [
            false,
            $escape
                ? "\\htmlspecialchars((string) ({$expression}), \\ENT_QUOTES | \\ENT_SUBSTITUTE)"
                : "({$expression})",
            null,
            $line,
        ];
    }

    private function php(int $at): void
    {
        $start = $this->position;
        $end = self::phpEnd($this->source, $this->position, '?>');
        $inner = $end === false
            ? substr($this->source, $this->position)
            : substr($this->source, $this->position, $end - $this->position);
        $this->position = $end === false ? strlen($this->source) : $end + 2;
        if (trim($inner) === '') {
            throw $this->error($at, 'The <?php block is empty');
        }
        // PHP must not hide branches around tags from the slot compiler. A
        // standalone parse rejects unfinished braced, alternative and unbraced
        // control flow without adding any instructions to rendered templates.
        try {
            $tokens = token_get_all('<?php ' . $inner, TOKEN_PARSE);
        } catch (\ParseError $error) {
            $line = $this->line($start) + $error->getLine() - 1;
            throw new ArcException(
                'PHP statements must be complete within one <?php ... ?> block; use Arc directives for control flow '
                . "across template markup: {$error->getMessage()} in {$this->file} on line {$line}.",
                0,
                $error,
            );
        }
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_GOTO) {
                throw $this->errorForLine(
                    $this->line($start) + $token[2] - 1,
                    'goto is not supported in PHP blocks; use Arc directives',
                );
            }
        }
        $this->flush();
        $this->emit('    ' . trim($inner) . "\n", $this->line($start + strspn($inner, " \t\r\n")), true);
    }

    private function directive(int $at, string $name): void
    {
        if (in_array($name, self::WITH_ARGUMENTS, true)) {
            $arguments = $this->arguments($at, $name);
            $this->flush();
            match ($name) {
                'if' => $this->open('if', $at, "if ({$arguments}) {"),
                'unless' => $this->open('unless', $at, "if (!({$arguments})) {"),
                'foreach' => $this->open('foreach', $at, "foreach ({$arguments}) {"),
                'for' => $this->open('for', $at, "for ({$arguments}) {"),
                'while' => $this->open('while', $at, "while ({$arguments}) {"),
                'elseif' => $this->continueIf($at, 'elseif', "} elseif ({$arguments}) {"),
                default => $this->declareProps($at, $arguments),
            };
            return;
        }
        if (in_array($name, self::WITHOUT_ARGUMENTS, true)) {
            $this->flush();
            match ($name) {
                'else' => $this->continueIf($at, 'else', '} else {'),
                'break', 'continue' => $this->loopStatement($at, $name),
                default => $this->close($at, substr($name, 3)),
            };
            return;
        }
        // Not a directive, such as the @ of an address: it is text.
        $this->text('@' . $name);
    }

    private function arguments(int $at, string $name): string
    {
        $start = $this->position;
        while ($start < strlen($this->source) && ($this->source[$start] === ' ' || $this->source[$start] === "\t")) {
            $start++;
        }
        if (($this->source[$start] ?? '') !== '(') {
            throw $this->error($at, "@{$name} needs its arguments in parentheses");
        }
        $end = self::phpEnd($this->source, $start + 1, ')');
        if ($end === false) {
            throw $this->error($at, "The parentheses of @{$name} are not closed");
        }
        $this->position = $end + 1;
        $inner = substr($this->source, $start + 1, $end - $start - 1);
        $this->argumentsLine = $this->line($start + 1 + strspn($inner, " \t\r\n"));
        // Keep trailing newlines: one may terminate a // or # comment.
        $arguments = rtrim(ltrim($inner), " \t\r");
        if (trim($arguments) === '') {
            throw $this->error($at, "@{$name} has empty parentheses");
        }
        return $arguments;
    }

    private function open(string $kind, int $at, string $statement): void
    {
        $this->blocks[] = [$kind, $this->line($at), false];
        $this->emit("    {$statement}\n", $this->argumentsLine, true);
    }

    private function continueIf(int $at, string $name, string $statement): void
    {
        $top = end($this->blocks);
        $allowed = $name === 'else' ? ['if', 'unless'] : ['if'];
        if ($top === false || !in_array($top[0], $allowed, true) || $this->blockOutsideTag()) {
            throw $this->error($at, "@{$name} is not inside an @if");
        }
        if ($top[2]) {
            throw $this->error($at, "@{$name} cannot follow @else");
        }
        $this->blocks[array_key_last($this->blocks)][2] = $name === 'else';
        $this->emit("    {$statement}\n", $name === 'else' ? $this->line($at) : $this->argumentsLine, true);
    }

    private function close(int $at, string $kind): void
    {
        $top = end($this->blocks);
        if ($top === false || $top[0] !== $kind || $this->blockOutsideTag()) {
            throw $this->error($at, "@end{$kind} has no open @{$kind}");
        }
        array_pop($this->blocks);
        $this->emit("    }\n", $this->line($at));
    }

    private function loopStatement(int $at, string $name): void
    {
        $tag = end($this->tags);
        $outside = $tag === false ? 0 : $tag['blocks'];
        foreach (array_reverse($this->blocks, true) as $index => [$kind]) {
            if ($index < $outside) {
                continue;
            }
            if (!in_array($kind, ['foreach', 'for', 'while'], true)) {
                continue;
            }
            $this->emit("    {$name};\n", $this->line($at));
            return;
        }
        throw $this->error($at, "@{$name} is not inside a loop");
    }

    /**
     * Whether the innermost open block began outside the innermost open tag.
     */
    private function blockOutsideTag(): bool
    {
        $tag = end($this->tags);
        return $tag !== false && count($this->blocks) <= $tag['blocks'];
    }

    private function declareProps(int $at, string $parameters): void
    {
        if (!$this->component) {
            throw $this->error($at, '@props belongs in a component; a view takes the data it is given');
        }
        if ($this->props !== null) {
            throw $this->error($at, '@props is declared twice');
        }
        $this->definitions = self::definitions($parameters, $this->file, $this->line($at));
        if (self::hasVariadic($this->definitions)) {
            throw $this->error($at, '@props cannot declare a variadic parameter.');
        }
        if (isset($this->definitions['slot']) && $this->definitions['slot']['required']) {
            throw $this->error($at, '@props must give $slot a default value, such as string $slot = \'\'');
        }
        $this->props = $parameters;
        $this->propsLine = $this->argumentsLine;
    }

    private function openTag(int $at, string $name): void
    {
        [$attributes, $selfClosing] = $this->attributes($at);
        if ($name === 'Slot') {
            $parent = end($this->tags);
            if ($parent === false || $parent['kind'] !== 'component') {
                throw $this->error($at, '<Slot> belongs directly inside a component');
            }
            if ($selfClosing) {
                throw $this->error($at, '<Slot> needs content and a closing </Slot>');
            }
            $slot = $attributes['name'] ?? null;
            if ($slot === null || !preg_match('/^\'([A-Za-z_][A-Za-z0-9_]*)\'$/', $slot, $named)) {
                throw $this->error($at, '<Slot> needs a name="..." that is a plain word, such as name="footer"');
            }
            if (count($attributes) > 1) {
                throw $this->error($at, '<Slot> takes only a name');
            }
            $this->flush();
            $output = $this->variable('s');
            $this->emit("    {$output} = '';\n", $this->line($at));
            $this->tags[] = [
                'kind' => 'slot',
                'name' => $named[1],
                'line' => $this->line($at),
                'output' => $output,
                'props' => [],
                'slots' => [],
                'blocks' => count($this->blocks),
            ];
            $this->outputs[] = $output;
            return;
        }
        if (isset($attributes['slot'])) {
            throw $this->error($at, 'slot is the content between the tags, not an attribute');
        }
        $file = self::file($name);
        if (!($this->known[$file] ??= ($this->exists)($file))) {
            throw $this->error(
                $at,
                "<{$name}> has no component: write components/" . str_replace('.', '/', $file) . '.arc.php',
            );
        }
        $definition = $this->definition($file, $at);
        foreach ($attributes as $prop => $_) {
            if (!isset($definition[$prop]) && !self::hasVariadic($definition)) {
                throw $this->error($at, "<{$name}> has no prop $" . $prop);
            }
        }
        $this->components[$file] ??= $this->variable('c');
        if ($selfClosing) {
            // Each guarded append has one component location. Single calls retain
            // the same generated instructions, including the common loop case.
            foreach ($this->parts as [, , $componentLine]) {
                if ($componentLine === null) {
                    continue;
                }
                $this->flush();
                break;
            }
            $this->parts[] = [
                false,
                $this->call($name, $attributes, [], $this->line($at)),
                $this->line($at),
                $this->line($at),
            ];
            return;
        }
        $this->flush();
        $output = $this->variable('s');
        $initialize = strlen($this->code);
        $this->emit("    {$output} = '';\n", $this->line($at));
        $this->tags[] = [
            'kind' => 'component',
            'name' => $name,
            'line' => $this->line($at),
            'output' => $output,
            'props' => $attributes,
            'slots' => [],
            'blocks' => count($this->blocks),
            'initialize' => $initialize,
            'conditional' => [],
        ];
        $this->outputs[] = $output;
    }

    private function closeTag(int $at, string $name): void
    {
        if (($this->source[$this->position] ?? '') !== '>') {
            throw $this->error($at, "</{$name} is not closed with >");
        }
        $this->position++;
        $tag = end($this->tags);
        if (
            $tag === false
            || $tag['kind'] !== ($name === 'Slot' ? 'slot' : 'component')
            || $tag['kind'] === 'component' && $tag['name'] !== $name
        ) {
            throw $this->error($at, "</{$name}> closes no open <{$name}>");
        }
        if (count($this->blocks) > $tag['blocks']) {
            [$kind] = end($this->blocks);
            throw $this->error($at, "@{$kind} is not closed before </{$name}>");
        }
        $this->flush();
        array_pop($this->tags);
        array_pop($this->outputs);
        if ($tag['kind'] === 'slot') {
            $index = array_key_last($this->tags);
            if (isset($this->tags[$index]['slots'][$tag['name']])) {
                throw $this->error($at, "The slot {$tag['name']} is given twice");
            }
            $this->tags[$index]['slots'][$tag['name']] = $tag['output'];
            if ($tag['blocks'] > $this->tags[$index]['blocks']) {
                $this->tags[$index]['conditional'][$tag['name']] = $tag['output'];
            }
            return;
        }
        if ($tag['conditional'] !== []) {
            // Insert on the existing initialization line so the source map stays valid.
            // This runs before any branch, once per invocation (including loop iterations).
            $initialize = '    ' . implode(' = ', $tag['conditional']) . ' = null;';
            $this->code = substr_replace($this->code, $initialize, $tag['initialize'], 0);
        }
        $slots = $tag['slots'];
        $slots['slot'] = $tag['output'];
        $this->parts[] = [
            false,
            $this->call($name, $tag['props'], $slots, $tag['line'], $tag['conditional']),
            $tag['line'],
            $tag['line'],
        ];
    }

    /**
     * @param array<string, string> $props
     * @param array<string, string> $slots
     * @param array<string, string> $conditional Slots that may be absent at runtime.
     */
    private function call(string $name, array $props, array $slots, int $line, array $conditional = []): string
    {
        $definition = $this->definition(self::file($name), 0);
        $arguments = ['$__arc'];
        $provided = $props + $slots;
        $positional = true;
        foreach ($definition as $prop => $details) {
            if ($details['required'] && !array_key_exists($prop, $provided)) {
                throw $this->errorForLine($line, "<{$name}> needs the prop $" . $prop);
            }
            if (!array_key_exists($prop, $provided)) {
                $positional = false;
                continue;
            }
            $expression = $provided[$prop];
            if ($positional && !$details['variadic']) {
                $arguments[] = $expression;
            } else {
                $arguments[] = "{$prop}: {$expression}";
                $positional = false;
            }
        }
        foreach ($props as $prop => $expression) {
            if (isset($slots[$prop])) {
                throw new ArcException("{$prop} is given to <{$name}> as an attribute and as a slot in {$this->file}.");
            }
            if (!isset($definition[$prop])) {
                $arguments[] = "{$prop}: {$expression}";
            }
        }
        foreach ($slots as $slot => $variable) {
            if (!isset($definition[$slot]) && !self::hasVariadic($definition)) {
                throw $this->errorForLine($line, "<{$name}> has no slot $" . $slot);
            }
            if (!isset($definition[$slot])) {
                $arguments[] = "{$slot}: {$variable}";
            }
        }
        $component = $this->components[self::file($name)];
        if ($conditional !== []) {
            $fixed = [];
            $optional = [];
            foreach ($provided as $prop => $expression) {
                $argument = self::literal($prop) . ' => ' . $expression;
                if (isset($conditional[$prop])) {
                    $optional[] = $argument;
                } else {
                    $fixed[] = $argument;
                }
            }
            // Omit absent slots so PHP applies the component's own defaults. Empty
            // strings are supplied content; only the null sentinel is left out.
            return (
                $component
                . '($__arc, ...(['
                . implode(', ', $fixed)
                . '] + \\array_filter(['
                . implode(', ', $optional)
                . "], 'is_string')))"
            );
        }
        return $component . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @return array<string, array{required: bool, variadic: bool}>
     */
    private function definition(string $file, int $at): array
    {
        if (isset($this->componentDefinitions[$file])) {
            return $this->componentDefinitions[$file];
        }
        if ($this->propsFor === null) {
            return $this->componentDefinitions[$file] = [
                '__arc_unknown_props' => ['required' => false, 'variadic' => true],
                'slot' => ['required' => false, 'variadic' => false],
            ];
        }
        $declaration = ($this->propsFor)($file);
        if ($declaration === null) {
            return $this->componentDefinitions[$file] = [
                'slot' => ['required' => false, 'variadic' => false],
            ];
        }
        if ($declaration['parameters'] === '') {
            throw ArcException::at($declaration['file'], $declaration['line'], '@props has empty parentheses.');
        }
        $definitions = self::definitions($declaration['parameters'], $declaration['file'], $declaration['line']);
        if (self::hasVariadic($definitions)) {
            throw ArcException::at(
                $declaration['file'],
                $declaration['line'],
                '@props cannot declare a variadic parameter.',
            );
        }
        if (isset($definitions['slot']) && $definitions['slot']['required']) {
            throw $this->error(
                $at,
                'The component must give $slot a default value, such as string $slot = \'\' in '
                . $declaration['file']
                . ' on line '
                . $declaration['line'],
            );
        }
        $definitions['slot'] ??= ['required' => false, 'variadic' => false];
        return $this->componentDefinitions[$file] = $definitions;
    }

    /**
     * @param array<string, array{required: bool, variadic: bool}> $definition
     */
    private static function hasVariadic(array $definition): bool
    {
        foreach ($definition as $details) {
            if ($details['variadic']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, array{required: bool, variadic: bool}>
     */
    private static function definitions(string $parameters, string $file, int $line): array
    {
        if (trim($parameters) === '') {
            return [];
        }
        $definitions = [];
        foreach (self::parameterParts($parameters, $file, $line) as $part) {
            if (!preg_match(
                '/^(?<before>.*?)(?<variadic>\.\.\.)?\$(?<name>[A-Za-z_][A-Za-z0-9_]*)(?:\s*=\s*(?<default>.*))?$/sD',
                $part,
                $match,
            )) {
                throw ArcException::at(
                    $file,
                    $line,
                    'The @props parameter ' . self::literalForMessage($part) . ' is not valid.',
                );
            }
            $before = trim($match['before']);
            if ($before !== '' && preg_match('/[^A-Za-z0-9_\\\\?|&\\s]/', $before) === 1) {
                throw ArcException::at(
                    $file,
                    $line,
                    'The @props parameter ' . self::literalForMessage($part) . ' is not valid.',
                );
            }
            $name = $match['name'];
            if (str_starts_with($name, '__')) {
                throw ArcException::at(
                    $file,
                    $line,
                    '@props cannot declare the reserved $'
                    . $name
                    . ' parameter; names beginning with $__ belong to Arc.',
                );
            }
            if (isset($definitions[$name])) {
                throw ArcException::at($file, $line, 'The @props parameter $' . $name . ' is declared twice.');
            }
            $definitions[$name] = [
                'required' => !isset($match['default']) && $match['variadic'] === '',
                'variadic' => $match['variadic'] !== '',
            ];
        }
        return $definitions;
    }

    /**
     * @return list<string>
     */
    private static function parameterParts(string $parameters, string $file, int $line): array
    {
        $parts = [];
        $part = '';
        $stack = [];
        foreach (token_get_all('<?php ' . $parameters) as $lexeme) {
            if (is_array($lexeme)) {
                [$kind, $text] = $lexeme;
                if ($kind === T_OPEN_TAG) {
                    continue;
                }
                if ($kind === T_COMMENT || $kind === T_DOC_COMMENT) {
                    $part .= ' ' . str_repeat("\n", substr_count($text, "\n"));
                    continue;
                }
                if ($kind === T_START_HEREDOC) {
                    $stack[] = T_END_HEREDOC;
                } elseif ($kind === T_END_HEREDOC) {
                    array_pop($stack);
                } elseif ($kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $stack[] = '}';
                } elseif ($kind === T_ATTRIBUTE) {
                    $stack[] = ']';
                }
                $part .= $text;
                continue;
            }
            if ($lexeme === ',' && $stack === []) {
                if (trim($part) === '') {
                    throw ArcException::at($file, $line, 'The @props parameters contain an empty item.');
                }
                $parts[] = trim($part);
                $part = '';
                continue;
            }
            if ($lexeme === end($stack)) {
                array_pop($stack);
            } elseif ($lexeme === '"' || $lexeme === '`') {
                $stack[] = $lexeme;
            } elseif (isset(['(' => ')', '[' => ']', '{' => '}'][$lexeme])) {
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$lexeme];
            } elseif (in_array($lexeme, [')', ']', '}'], true)) {
                throw ArcException::at($file, $line, 'The @props parameters have unbalanced brackets.');
            }
            $part .= $lexeme;
        }
        if ($stack !== []) {
            throw ArcException::at($file, $line, 'The @props parameters are not closed.');
        }
        $part = trim($part);
        if ($part === '') {
            throw ArcException::at($file, $line, 'The @props parameters contain an empty item.');
        }
        $parts[] = $part;
        return $parts;
    }

    /**
     * Finds a component's @props declaration without compiling its body.
     *
     * @return array{parameters: string, line: int}|null
     */
    public static function propsDeclaration(string $source, string $file = 'component.arc.php'): ?array
    {
        $scanner = new self();
        $scanner->source = $source;
        $scanner->file = $file;
        // Follow the same marks and lexical boundaries as body(), without
        // compiling component calls or treating text inside PHP as directives.
        while (preg_match(self::MARKS, $source, $match, PREG_OFFSET_CAPTURE, $scanner->position) === 1) {
            [$mark, $at] = $match[0];
            $scanner->position = $at + strlen($mark);
            if ($mark === '@props') {
                return [
                    'parameters' => $scanner->arguments($at, 'props'),
                    'line' => $scanner->line($at),
                ];
            }
            if ($mark === '{{--') {
                $scanner->skipComment($at);
            } elseif ($mark === '{{' || $mark === '{!!') {
                $scanner->value($at, $mark === '{{' ? '}}' : '!!}', false);
            } elseif ($mark === '<?php' || $mark === '<?=') {
                $end = self::phpEnd($source, $scanner->position, '?>');
                $scanner->position = $end === false ? strlen($source) : $end + 2;
            } elseif (str_starts_with($mark, '<') && !str_starts_with($mark, '</')) {
                $scanner->attributes($at);
            } elseif (in_array(substr($mark, 1), self::WITH_ARGUMENTS, true)) {
                $scanner->arguments($at, substr($mark, 1));
            }
        }
        return null;
    }

    /**
     * A component's name as its file: ProductGrid is product-grid, Forms.TextField forms.text-field.
     */
    public static function file(string $tag): string
    {
        return strtolower((string) preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1-$2', $tag));
    }

    /**
     * @return array{array<string, string>, bool} Each prop's PHP expression by name, and whether the tag closes itself.
     */
    private function attributes(int $at): array
    {
        $attributes = [];
        $length = strlen($this->source);
        while (true) {
            while ($this->position < $length && ctype_space($this->source[$this->position])) {
                $this->position++;
            }
            if ($this->position >= $length) {
                throw $this->error($at, 'The component tag is not closed');
            }
            if (substr($this->source, $this->position, 2) === '/>') {
                $this->position += 2;
                return [$attributes, true];
            }
            if ($this->source[$this->position] === '>') {
                $this->position++;
                return [$attributes, false];
            }
            if (!preg_match('/\G(:?)([A-Za-z_][A-Za-z0-9_-]*)/', $this->source, $match, 0, $this->position)) {
                throw $this->error($at, 'An attribute name is expected in the component tag');
            }
            $this->position += strlen($match[0]);
            $bound = $match[1] === ':';
            $prop = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $match[2]))));
            if (isset($attributes[$prop])) {
                throw $this->error($at, "The attribute {$match[2]} is given twice");
            }
            if (($this->source[$this->position] ?? '') !== '=') {
                if ($bound) {
                    throw $this->error($at, ":{$match[2]} needs a PHP expression, such as :{$match[2]}=\"\$value\"");
                }
                $attributes[$prop] = 'true';
                continue;
            }
            $this->position++;
            $quote = $this->source[$this->position] ?? '';
            if ($quote !== '"' && $quote !== '\'') {
                throw $this->error($at, "The value of {$match[2]} must be in quotes");
            }
            $end = $this->position + 1;
            while ($end < $length) {
                if ($this->source[$end] === '\\') {
                    $end += 2;
                    continue;
                }
                if ($this->source[$end] === $quote) {
                    break;
                }
                ++$end;
            }
            if ($end >= $length) {
                throw $this->error($at, "The value of {$match[2]} is not closed");
            }
            $value = substr($this->source, $this->position + 1, $end - $this->position - 1);
            $this->position = $end + 1;
            if ($bound) {
                if (trim($value) === '') {
                    throw $this->error($at, ":{$match[2]} has no expression");
                }
                $attributes[$prop] = '(' . trim($value) . ')';
                continue;
            }
            $attributes[$prop] = $this->interpolate($value, $at);
        }
    }

    /**
     * A text attribute as a PHP string expression, with its {{ }} values joined in
     * unescaped: a prop is data, and the component escapes it where it outputs it.
     */
    private function interpolate(string $value, int $at): string
    {
        $parts = [];
        $position = 0;
        while (preg_match('/\{!!|\{\{/', $value, $match, PREG_OFFSET_CAPTURE, $position)) {
            [$mark, $start] = $match[0];
            if ($start > $position) {
                $parts[] = self::literal(substr($value, $position, $start - $position));
            }
            $start += strlen($mark);
            $close = $mark === '{{' ? '}}' : '!!}';
            $end = self::phpEnd($value, $start, $close);
            if ($end === false) {
                throw $this->error($at, 'The attribute value is not closed with ' . $close);
            }
            $parts[] = '(' . trim(substr($value, $start, $end - $start)) . ')';
            $position = $end + strlen($close);
        }
        if ($position < strlen($value)) {
            $parts[] = self::literal(substr($value, $position));
        }
        if ($parts === []) {
            return "''";
        }
        return count($parts) === 1 && $parts[0][0] === '\'' ? $parts[0] : implode(' . ', $parts);
    }

    /**
     * Finds a delimiter outside PHP strings, comments and nested expressions.
     * PHP's lexer also handles escaped quotes, interpolation and heredoc/nowdoc.
     */
    private static function phpEnd(string $source, int $start, string $close): int|false
    {
        $position = $start - 6; // The synthetic opening tag is not part of the source.
        $stack = [];
        foreach (token_get_all('<?php ' . substr($source, $start)) as $lexeme) {
            if (is_array($lexeme)) {
                [$kind, $text] = $lexeme;
                if ($kind === T_CLOSE_TAG) {
                    return $close === '?>' ? $position : false;
                }
                if ($kind === T_START_HEREDOC) {
                    $stack[] = T_END_HEREDOC;
                } elseif ($kind === T_END_HEREDOC) {
                    array_pop($stack);
                } elseif ($kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $stack[] = '}';
                } elseif ($kind === T_ATTRIBUTE) {
                    $stack[] = ']';
                }
                $position += strlen($text);
                continue;
            }
            if ($stack === [] && substr_compare($source, $close, $position, strlen($close)) === 0) {
                return $position;
            }
            if ($lexeme === end($stack)) {
                array_pop($stack);
            } elseif ($lexeme === '"' || $lexeme === '`') {
                $stack[] = $lexeme;
            } elseif (isset(['(' => ')', '[' => ']', '{' => '}'][$lexeme])) {
                $stack[] = ['(' => ')', '[' => ']', '{' => '}'][$lexeme];
            }
            $position += strlen($lexeme);
        }
        return false;
    }

    private function text(string $text): void
    {
        $last = array_key_last($this->parts);
        if ($last !== null && $this->parts[$last][0]) {
            $this->parts[$last][1] .= $text;
            return;
        }
        $this->parts[] = [true, $text, null, $this->line($this->position)];
    }

    /**
     * Writes the pending parts as one append to the current output.
     */
    private function flush(): void
    {
        if ($this->parts === []) {
            return;
        }
        $line = null;
        foreach ($this->parts as [, , $componentLine]) {
            $line ??= $componentLine;
        }
        if ($line !== null) {
            $this->emit("    try {\n", $line);
        }
        $this->emit('    ' . end($this->outputs) . " .=\n", $this->parts[0][3]);
        $last = array_key_last($this->parts);
        foreach ($this->parts as $index => [$literal, $part, , $sourceLine]) {
            $expression = $literal ? self::literal($part) : $part;
            $this->emit('        ' . $expression . ($index === $last ? ';' : ' .') . "\n", $sourceLine, true);
        }
        $this->parts = [];
        if ($line !== null) {
            $file = self::literal($this->file);
            $this->emit(
                "    } catch (\\Throwable \$__error) {\n"
                . "        throw \\Crab\\Arc\\ArcException::runtime({$file}, {$line}, \$__error);\n"
                . "    }\n",
                $line,
            );
        }
    }

    private function variable(string $kind): string
    {
        return '$__' . $kind . $this->counter++;
    }

    private function line(int $at): int
    {
        return substr_count($this->source, "\n", 0, $at) + 1;
    }

    private function error(int $at, string $message): ArcException
    {
        return ArcException::at($this->file, $this->line($at), $message);
    }

    private function errorForLine(int $line, string $message): ArcException
    {
        return ArcException::at($this->file, $line, $message);
    }

    private static function literalForMessage(string $value): string
    {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    private static function literal(string $text): string
    {
        return '\'' . strtr($text, ['\\' => '\\\\', '\'' => '\\\'']) . '\'';
    }
}
