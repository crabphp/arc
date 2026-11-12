# Changelog

Crab Arc follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- **Ready for production use:** the framework's guide no longer marks Arc experimental. Before 1.0 a minor version may still change template syntax, always with what to change in this changelog; see Stability in the README.

- **Attributes a component does not declare:** a component that declares `array $attributes = []` receives every other attribute of its tag, by HTML name, and writes them with `@attributes($attributes, $defaults)`, joining `class` and escaping values. Attribute names on component tags may now hold `@`, `:` and `.`, as in `@click`, `x-on:click.prevent` and `hx-on::after-request`. Components without `$attributes` reject undeclared attributes at compile time as before. `@attributes` is now a directive: write `@@attributes` for the literal text.
- **`@json($value)`:** writes a value as JSON that is safe inside `<script>`, with `<`, `>`, `&` and both quotes as `\u` escapes. In an attribute, `{{ json_encode($value) }}` stays the way. `@json` is now a directive: write `@@json` for the literal text.
- **Imports in templates:** a `<?php` block at the very top of a template may start with `use` statements (aliases, `use function`, `use const` and groups included), which Arc puts above the compiled closure, so `@props(Product $product)` and the rest of the template take short names. A `use` anywhere else is a compile error at its line, as is a global name PHP would warn has no effect.
- **Workers load compiled templates without starting a process:** a compiled template carries the hash of the PHP that passed the `php -l` check, and a worker compares it in its own process instead of checking the file again in a new one. Serving no longer needs `proc_open` once templates are compiled, and the first request for a template no longer waits for a process. A compiled template that was changed afterwards is compiled again from its template rather than refused, and a new PHP version compiles every template again. Existing compiled templates are compiled once more after upgrading; run `php cua view:cache` when deploying.

## 0.2.0 — 2026-11-12

- Requires `crabphp/framework` ^0.7.
- Rename the template cache commands to the implementation-independent `view:cache` and `view:clear`. Update deployment scripts from `arc:cache` and `arc:clear`.

## 0.1.1 — 2026-11-06

- **Compiled templates are linted from a file of their own:** the check that refuses a compiled template with a PHP compile error ran `php -l` on a temporary stream as standard input, which is not read the same way on every platform. It now names a temporary file, as it does on Linux and macOS alike.

## 0.1.0 — 2026-11-06

- **Second review fixes:** compilation and first load validate generated PHP in
  an isolated CLI process, catching compile-time fatal errors without executing
  template code. This requires `proc_open`, a writable temporary directory and
  the PHP CLI at `PHP_BINARY`. PHP blocks must contain complete statements;
  control flow spanning template markup and `goto` are rejected (use Arc
  directives). Prop discovery respects comments and strings, directive arguments
  and prop lists respect PHP comments, and all `$__` prop names are reserved for
  the compiler. These checks add no work to warm rendering.
- **Pre-publication fixes:** component edits invalidate cached callers in new
  workers; compilation and `arc:cache` reject malformed generated PHP with
  template source lines. Conditional named slots preserve defaults and reset
  per call. Adjacent component failures report their own tag lines, and PHP
  strings and comments can contain template delimiters. README examples cover
  constructor injection and context-specific escaping; JIT-off benchmark
  results distinguish CLI OPcache settings.
- **Safer components:** caller templates now check component props and slots
  while compiling, loop control cannot cross a component boundary, and
  component failures name the template line. Component arguments use positional
  calls when their declarations allow it; cache writes remain atomic when
  workers compile the same source together.
- **First version,** for `crabphp/framework` 0.6: templates with `{{ }}` values escaped by default, `@if`, `@foreach` and the other control directives, and components as capitalised tags with props and named slots. Each template compiles once to a PHP closure that builds the page as a string. `view()` returns an HTML response, and `arc:cache` compiles every template when deploying.
