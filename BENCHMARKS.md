# Arc benchmarks

`benchmarks/render.php` renders a layout with 50 product cards, a loop, a stock
condition in every card, and a featured condition in the page. It compares Arc
with two PHP baselines: a raw include using `ob_start()`, and a hand-built
string. Arc itself builds strings without an output buffer.

Run from this directory with JIT disabled and CLI OPcache disabled:

```sh
php -d opcache.enable_cli=0 -d opcache.jit=0 benchmarks/render.php 30000
```

JIT and OPcache are separate settings. To measure with bytecode caching enabled
and JIT still disabled, allow the benchmark's newly written files into OPcache:

```sh
php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.jit=0 benchmarks/render.php 30000
```

Measured on 2026-11-06 with PHP 8.4.22 on Linux x86_64. Each renderer warms up
for 1,000 renders before 30,000 timed renders. The workload and benchmark script
are unchanged from baseline commit `3165536`. The fixed compiler is at
`6438227`. Baseline and fixed runs were alternated, reversing their order on
every second pair, with five pairs for OPcache off and three for OPcache on.
All runs used JIT off. Times below are microseconds per render.

| CLI OPcache | Renderer | Baseline median | Fixed median | Fixed range |
| --- | --- | ---: | ---: | ---: |
| Off | Hand-built PHP string | 25.79 | 26.23 | 25.60–26.75 |
| Off | Arc | 32.90 | 31.41 | 31.13–32.98 |
| Off | Raw PHP include + buffer | 46.80 | 47.15 | 46.07–49.53 |
| On | Hand-built PHP string | 24.80 | 25.18 | 24.71–25.24 |
| On | Arc | 32.07 | 30.99 | 30.58–31.43 |
| On | Raw PHP include + buffer | 26.25 | 25.91 | 25.88–28.80 |

The initial, separate batches gave Arc medians of 30.64 before / 31.48 after
with OPcache off, and 29.90 / 31.16 with it on. Variation affected the PHP
baselines too, prompting the alternating comparison above. These measurements
support retaining the warm rendering cost through the fixes, without claiming
a speedup. Dependency checks and PHP syntax validation happen during compilation
or first load, outside the timed warm loop. A regression test renders after
moving both source and cache directories away from a warmed renderer.

For the fixed alternating runs, Arc took about 20% longer than the hand-built
string with OPcache off, and about 23% longer with it on. It beat the repeatedly
parsed raw include with OPcache off; both PHP baselines were faster with
OPcache on. These are results for this warm 50-card workload, not a ranking
among template engines. **Second only to raw PHP, with JIT off, remains the
speed target.** No competing template engine is included in this benchmark.

Arc emits 5,153 bytes and the PHP baselines emit 4,693 bytes. The difference is
formatting whitespace; output equality was checked after normalizing whitespace
between tags. This benchmark excludes cold compilation, dependency-index reads,
filesystem checks on first load, conditional named slots, framework request
handling and database work. It does not measure whole-application throughput.
