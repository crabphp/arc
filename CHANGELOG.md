# Changelog

Crab Arc follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

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
