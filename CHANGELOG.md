# Changelog

Crab Arc follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- **Safer components:** caller templates now check component props and slots
  while compiling, loop control cannot cross a component boundary, and
  component failures name the template line. Component arguments use positional
  calls when their declarations allow it; cache writes remain atomic when
  workers compile the same source together.
- **First version,** for `crabphp/framework` 0.6: templates with `{{ }}` values escaped by default, `@if`, `@foreach` and the other control directives, and components as capitalised tags with props and named slots. Each template compiles once to a PHP closure that builds the page as a string. `view()` returns an HTML response, and `arc:cache` compiles every template when deploying.
