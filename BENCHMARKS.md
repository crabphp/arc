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
are unchanged. The baseline for this second review is `c16c911`; the fixed
compiler is at `52e8eb5`. Baseline and fixed runs were alternated, reversing their order on
every second pair, with five pairs for OPcache off and three for OPcache on.
All runs used `opcache.jit=0`, with JIT's runtime `on` flag confirmed false.
Times below are microseconds per render.

| CLI OPcache | Renderer | Baseline median | Fixed median | Fixed range |
| --- | --- | ---: | ---: | ---: |
| Off | Hand-built PHP string | 24.67 | 24.72 | 24.49–24.86 |
| Off | Arc | 30.22 | 29.90 | 29.59–30.67 |
| Off | Raw PHP include + buffer | 44.99 | 45.05 | 44.51–45.43 |
| On | Hand-built PHP string | 23.50 | 23.97 | 23.49–24.01 |
| On | Arc | 29.66 | 29.26 | 29.16–31.35 |
| On | Raw PHP include + buffer | 24.98 | 25.39 | 25.09–25.45 |

The generated PHP for all three benchmark templates is byte-for-byte identical
before and after these fixes. The small timing differences fall within observed
run variation; these measurements do not establish a speedup. Dependency checks
and isolated PHP compilation validation happen during compilation or first
load, outside the timed warm loop. The validation subprocess adds cold-start
work, including for existing artifacts, with no new work on warm renders. A
regression test renders after moving both source and cache directories away
from a warmed renderer.

For the fixed alternating runs, Arc took about 21% longer than the hand-built
string with OPcache off, and about 22% longer with it on. It beat the repeatedly
parsed raw include with OPcache off; both PHP baselines were faster with
OPcache on. These are results for this warm 50-card workload, not a ranking
among template engines. **Second only to raw PHP, with JIT off, remains the
speed target.** No competing template engine is included in this benchmark.

Arc emits 5,153 bytes and the PHP baselines emit 4,693 bytes. The difference is
formatting whitespace; output equality was checked after normalizing whitespace
between tags. This benchmark excludes cold compilation, dependency-index reads,
filesystem checks on first load, conditional named slots, framework request
handling and database work. It does not measure whole-application throughput.
