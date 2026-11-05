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
    public const string VERSION = '1';

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
    /**
     * Pending parts of the current append: [true, text] or [false, expression].
     *
     * @var list<array{bool, string}>
     */
    private array $parts = [];
    /**
     * Output variables, innermost last: the view's, then each open component's or slot's.
     *
     * @var list<string>
     */
    private array $outputs = [];
    /**
     * Open @if, @foreach and the like: [kind, line].
     *
     * @var list<array{string, int}>
     */
    private array $blocks = [];
    /**
     * Open component and slot tags.
     *
     * @var list<array{kind: string, name: string, line: int, output: string, props: array<string, string>, slots: array<string, string>, blocks: int}>
     */
    private array $tags = [];
    /**
     * The variable holding each component used, by name.
     *
     * @var array<string, string>
     */
    private array $components = [];
    private ?string $props = null;
    private int $counter = 0;
    /**
     * @var \Closure(string): bool
     */
    private \Closure $exists;
    /**
     * @var array<string, bool>
     */
    private array $known = [];

    /**
     * @param string $file The template's path, for messages.
     * @param bool $component Whether it is a component, which takes props rather than data.
     * @param \Closure(string): bool $exists Whether a component's file exists, by its file name such as product-grid.
     */
    public function compile(string $source, string $file, bool $component, \Closure $exists): string
    {
        $this->exists = $exists;
        $this->known = [];
        $this->source = $source;
        $this->file = $file;
        $this->component = $component;
        $this->position = 0;
        $this->code = '';
        $this->parts = [];
        $this->outputs = ['$__o'];
        $this->blocks = [];
        $this->tags = [];
        $this->components = [];
        $this->props = null;
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
            if (!preg_match('/\$slot\b/', $parameters)) {
                $parameters = trim($parameters) === '' ? 'string $slot = \'\'' : $parameters . ', string $slot = \'\'';
            }
            $head = "static function (\\Crab\\Arc\\Arc \$__arc, {$parameters}): string {\n";
        } else {
            $head = "static function (\\Crab\\Arc\\Arc \$__arc, array \$__data): string {\n    \\extract(\$__data, \\EXTR_SKIP);\n";
        }
        return "<?php\n\n/* Compiled by Arc from {$from}; do not edit. */\n\nreturn {$head}{$hoisted}    \$__o = '';\n{$this->code}    return \$__o;\n};\n";
    }

    private function body(): void
    {
        $pattern = '/\{\{--|@\{\{|@@|\{!!|\{\{|<\?php|<\?=|<\/?[A-Z][A-Za-z0-9]*(?:\.[A-Z][A-Za-z0-9]*)*|@[a-z]+/';
        while (preg_match($pattern, $this->source, $match, PREG_OFFSET_CAPTURE, $this->position) === 1) {
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
                $mark === '<?php' => ($close = strpos($this->source, '?>', $saved)) === false ? null : $close + 2,
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
        $end = strpos($this->source, $close, $this->position);
        if ($end === false) {
            throw $this->error($at, 'The value is not closed with ' . $close);
        }
        $expression = trim(substr($this->source, $this->position, $end - $this->position));
        $this->position = $end + strlen($close);
        if ($expression === '') {
            throw $this->error($at, 'The value is empty');
        }
        $this->parts[] = [
            false,
            $escape
                ? "\\htmlspecialchars((string) ({$expression}), \\ENT_QUOTES | \\ENT_SUBSTITUTE)"
                : "({$expression})",
        ];
    }

    private function php(int $at): void
    {
        $end = strpos($this->source, '?>', $this->position);
        $inner = $end === false
            ? substr($this->source, $this->position)
            : substr($this->source, $this->position, $end - $this->position);
        $this->position = $end === false ? strlen($this->source) : $end + 2;
        if (trim($inner) === '') {
            throw $this->error($at, 'The <?php block is empty');
        }
        $this->flush();
        $this->code .= '    ' . trim($inner) . "\n";
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
        $depth = 0;
        $quote = null;
        for ($i = $start; $i < strlen($this->source); $i++) {
            $char = $this->source[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                $this->position = $i + 1;
                $arguments = trim(substr($this->source, $start + 1, $i - $start - 1));
                if ($arguments === '') {
                    throw $this->error($at, "@{$name} has empty parentheses");
                }
                return $arguments;
            }
        }
        throw $this->error($at, "The parentheses of @{$name} are not closed");
    }

    private function open(string $kind, int $at, string $statement): void
    {
        $this->blocks[] = [$kind, $this->line($at)];
        $this->code .= "    {$statement}\n";
    }

    private function continueIf(int $at, string $name, string $statement): void
    {
        $top = end($this->blocks);
        $allowed = $name === 'else' ? ['if', 'unless'] : ['if'];
        if ($top === false || !in_array($top[0], $allowed, true) || $this->blockOutsideTag()) {
            throw $this->error($at, "@{$name} is not inside an @if");
        }
        $this->code .= "    {$statement}\n";
    }

    private function close(int $at, string $kind): void
    {
        $top = end($this->blocks);
        if ($top === false || $top[0] !== $kind || $this->blockOutsideTag()) {
            throw $this->error($at, "@end{$kind} has no open @{$kind}");
        }
        array_pop($this->blocks);
        $this->code .= "    }\n";
    }

    private function loopStatement(int $at, string $name): void
    {
        foreach (array_reverse($this->blocks) as [$kind]) {
            if (!in_array($kind, ['foreach', 'for', 'while'], true)) {
                continue;
            }
            $this->code .= "    {$name};\n";
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
        $this->props = $parameters;
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
            $this->code .= "    {$output} = '';\n";
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
        $this->components[$file] ??= $this->variable('c');
        if ($selfClosing) {
            $this->parts[] = [false, $this->call($name, $attributes, [])];
            return;
        }
        $this->flush();
        $output = $this->variable('s');
        $this->code .= "    {$output} = '';\n";
        $this->tags[] = [
            'kind' => 'component',
            'name' => $name,
            'line' => $this->line($at),
            'output' => $output,
            'props' => $attributes,
            'slots' => [],
            'blocks' => count($this->blocks),
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
            return;
        }
        $slots = $tag['slots'];
        $slots['slot'] = $tag['output'];
        $this->parts[] = [false, $this->call($name, $tag['props'], $slots)];
    }

    /**
     * @param array<string, string> $props
     * @param array<string, string> $slots
     */
    private function call(string $name, array $props, array $slots): string
    {
        $arguments = ['$__arc'];
        foreach ($props as $prop => $expression) {
            if (isset($slots[$prop])) {
                throw new ArcException("{$prop} is given to <{$name}> as an attribute and as a slot in {$this->file}.");
            }
            $arguments[] = "{$prop}: {$expression}";
        }
        foreach ($slots as $slot => $variable) {
            $arguments[] = "{$slot}: {$variable}";
        }
        return $this->components[self::file($name)] . '(' . implode(', ', $arguments) . ')';
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
            $end = strpos($this->source, $quote, $this->position + 1);
            if ($end === false) {
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
            $attributes[$prop] = $this->interpolate($value);
        }
    }

    /**
     * A text attribute as a PHP string expression, with its {{ }} values joined in
     * unescaped: a prop is data, and the component escapes it where it outputs it.
     */
    private function interpolate(string $value): string
    {
        $pieces = preg_split(
            '/(\{\{.*?\}\}|\{!!.*?!!\})/s',
            $value,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );
        $parts = [];
        foreach ($pieces === false ? [$value] : $pieces as $piece) {
            $edge = match (true) {
                str_starts_with($piece, '{!!') => 3,
                str_starts_with($piece, '{{') => 2,
                default => 0,
            };
            $parts[] = $edge === 0 ? self::literal($piece) : '(' . trim(substr($piece, $edge, -$edge)) . ')';
        }
        if ($parts === []) {
            return "''";
        }
        return count($parts) === 1 && $parts[0][0] === '\'' ? $parts[0] : implode(' . ', $parts);
    }

    private function text(string $text): void
    {
        $last = array_key_last($this->parts);
        if ($last !== null && $this->parts[$last][0]) {
            $this->parts[$last][1] .= $text;
            return;
        }
        $this->parts[] = [true, $text];
    }

    /**
     * Writes the pending parts as one append to the current output.
     */
    private function flush(): void
    {
        if ($this->parts === []) {
            return;
        }
        $expressions = [];
        foreach ($this->parts as [$literal, $part]) {
            $expressions[] = $literal ? self::literal($part) : $part;
        }
        $this->parts = [];
        $this->code .= '    ' . end($this->outputs) . ' .= ' . implode(' . ', $expressions) . ";\n";
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

    private static function literal(string $text): string
    {
        return '\'' . strtr($text, ['\\' => '\\\\', '\'' => '\\\'']) . '\'';
    }
}
