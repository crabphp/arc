# Contributing

Setup, formatting, linting and conventions follow [crabphp/framework's CONTRIBUTING.md](https://github.com/crabphp/framework/blob/main/CONTRIBUTING.md). Use PHP 8.4 or later, put a framework 0.6.x checkout at `../framework`, and run `composer install` here. The path repository uses that checkout; no external services are needed by its tests.

Before a pull request, run here:

```sh
composer validate --strict
composer format:check
composer lint
composer test
```

CI runs the suite on Linux and macOS against framework `v0.6.1` and `main`.
