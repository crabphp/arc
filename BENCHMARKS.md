# Arc benchmarks

The request-path benchmark renders a layout with 50 product cards, a loop, a
stock condition in every card, and a featured condition in the page. It
compares the compiled Arc closure with a raw PHP include using \`ob_start()\` and
with hand-written string concatenation. The generated page is 5 KiB; all three
renderers produce the same catalogue content.

Run it from this directory with the JIT disabled:

\`\`\`sh
php -d opcache.enable_cli=0 -d opcache.jit=0 benchmarks/render.php 30000
\`\`\`

The numbers below are microseconds per render on PHP 8.4.22, measured with
30,000 warm renders. The machine was otherwise idle; small changes between
runs are normal.

| renderer | before Arc 1 | Arc 2 |
| --- | ---: | ---: |
| raw PHP include | 44.9 | 44.7 |
| hand-built string | 25.4 | 25.3 |
| Arc | 31.0 | 31.0 |

Arc 2 resolves component arguments positionally when the component's declared
props make that safe, hoists component lookups, and keeps component runtime
line reporting in one guarded append. These changes are effectively neutral
within benchmark noise, while the output remains faster than the raw include
and close to the hand-built string. The benchmark script is intentionally
small and has no framework or database dependency.
