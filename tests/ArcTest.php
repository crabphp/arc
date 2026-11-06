<?php

declare(strict_types=1);

namespace Crab\Arc\Tests;

use Crab\App;
use Crab\Arc\Arc;
use Crab\Arc\ArcException;
use Crab\Arc\ArcPlugin;
use Crab\Arc\Compiler;
use Crab\Config\Config;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Query;
use Crab\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArcTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/arc-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/views/components', 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testValuesAreEscapedAndRawOnlyWhenAsked(): void
    {
        $this->view('page', '<p>{{ $name }}</p>{!! $html !!}{{ $count }}{{ $missing ?? "none" }}');
        self::assertSame('<p>&lt;b&gt;&quot;Ada&quot; &amp; &#039;co&#039;&lt;/b&gt;</p><i>raw</i>3none', $this->arc()->render('page', [
            'name' => '<b>"Ada" & \'co\'</b>',
            'html' => '<i>raw</i>',
            'count' => 3,
        ]));
    }

    public function testDirectivesBranchAndLoop(): void
    {
        $this->view('page', implode("\n", [
            '@foreach($items as $item)',
            '@if($item > 2)big@elseif($item === 2)two@else small@endif',
            '@unless($item === 1)!@endunless',
            '@endforeach',
            '@for($i = 0; $i < 5; $i++)@if($i === 3)@break@endif{{ $i }}@endfor',
            '@php',
        ]));
        self::assertSame(" small\n\ntwo\n!\nbig\n!\n012\n@php", $this->arc()->render('page', ['items' => [1, 2, 3]]));
    }

    public function testALineHoldingOnlyAStatementLeavesNoBlankLine(): void
    {
        $this->view(
            'page',
            "<ul>\n    @foreach(\$items as \$item)\n    <li>{{ \$item }}</li>\n    @endforeach\n    {{-- a note --}}\n</ul>\n",
        );
        self::assertSame("<ul>\n    <li>a</li>\n    <li>b</li>\n</ul>\n", $this->arc()->render('page', ['items' => [
            'a',
            'b',
        ]]));
    }

    public function testCommentsAndEscapedSymbolsAreText(): void
    {
        $this->view('page', '{{-- hidden --}}me@example.com @@if @{{ $x }} @unknown');
        self::assertSame('me@example.com @if {{ $x }} @unknown', $this->arc()->render('page'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function phpDelimiterCases(): iterable
    {
        yield 'quoted expression' => ['{{ "}}" }}', '}}'];
        yield 'raw expression' => ["{!! '!!}' !!}", '!!}'];
        yield 'escaped quote' => ['{{ "a\\"}}b" }}', 'a&quot;}}b'];
        yield 'interpolated string' => ['{{ "x{$value}}}" }}', 'xA}}'];
        yield 'block comment' => ['{{ 1 /* }} */ + 2 }}', '3'];
        yield 'line comment' => ["{{ 1 // }}\n+ 2 }}", '3'];
        yield 'PHP string' => ['<?php $a = "?>"; ?>{{ $a }}', '?&gt;'];
        yield 'standalone PHP' => ["<?php\n\$a = \"?>\";\n?>\n{{ \$a }}", '?&gt;'];
        yield 'PHP comment' => ['<?php /* ?> */ $a = 3; ?>{{ $a }}', '3'];
        yield 'PHP nowdoc' => ["<?php\n\$a = <<<'TEXT'\n?>\nTEXT;\n?>{{ \$a }}", '?&gt;'];
        yield 'expression nowdoc' => ["{{ <<<'TEXT'\n}}\nTEXT\n}}", '}}'];
        yield 'attribute interpolation' => ['<Card title="{{ \'}}\' }}" />', '}}'];
        yield 'nested braces' => ['{{ (function () { if (true) { return "}}"; }})() }}', '}}'];
    }

    #[DataProvider('phpDelimiterCases')]
    public function testPhpStringsAndCommentsKeepTheirDelimiters(string $source, string $expected): void
    {
        $this->component('card', '@props(string $title){{ $title }}');
        $this->view('page', $source);
        self::assertSame($expected, $this->arc()->render('page', ['value' => 'A']));
    }

    public function testComponentsTakePropsAndSlots(): void
    {
        $this->component(
            'card',
            "@props(string \$title, int \$total = 0, string \$footer = '', bool \$wide = false, string \$dataId = '')\n<section data-id=\"{{ \$dataId }}\" class=\"{{ \$wide ? 'wide' : 'card' }}\"><h2>{{ \$title }}</h2>{!! \$slot !!}<footer>{!! \$footer !!}</footer>{{ \$total }}</section>",
        );
        $this->view('page', implode('', [
            '<Card title="Order #{{ $id }} <x>" :total="$total * 2" wide data-id="d{{ $id }}">',
            '<Slot name="footer"><b>{{ $note }}</b></Slot>',
            'Body {{ $note }}',
            '</Card>',
            '<Card title="Short" />',
        ]));
        self::assertSame(
            '<section data-id="d7" class="wide"><h2>Order #7 &lt;x&gt;</h2>Body &lt;n&gt;<footer><b>&lt;n&gt;</b></footer>20</section>'
            . '<section data-id="" class="card"><h2>Short</h2><footer></footer>0</section>',
            $this->arc()->render('page', ['id' => 7, 'total' => 10, 'note' => '<n>']),
        );
    }

    public function testComponentsNestAndLiveInFolders(): void
    {
        mkdir($this->directory . '/views/components/forms');
        $this->component('layout', "@props(string \$title)\n<title>{{ \$title }}</title><main>{!! \$slot !!}</main>");
        $this->component(
            'forms.text-field',
            "@props(string \$name, bool \$required = false)\n<input name=\"{{ \$name }}\"@if(\$required) required@endif>",
        );
        $this->view(
            'page',
            '<Layout title="T"><form>@foreach($names as $name)<Forms.TextField :name="$name" required />@endforeach</form></Layout>',
        );
        self::assertSame('<title>T</title><main><form><input name="a" required><input name="b" required></form></main>', $this->arc()->render('page', ['names' => [
            'a',
            'b',
        ]]));
    }

    public function testConditionalSlotsUseDefaultsWhenAbsentAndKeepExplicitEmptyContent(): void
    {
        $this->component(
            'card',
            '@props(string $footer = \'default\', string $title = \'title\')<h1>{{ $title }}</h1><footer>{!! $footer !!}</footer>{!! $slot !!}',
        );
        $this->view('page', '<Card title="Card">@if($show)<Slot name="footer">{{ $value }}</Slot>@endif body</Card>');
        $arc = $this->arc();
        self::assertSame('<h1>Card</h1><footer>default</footer> body', $arc->render('page', ['show' => false]));
        self::assertSame('<h1>Card</h1><footer>&lt;footer&gt;</footer> body', $arc->render('page', [
            'show' => true,
            'value' => '<footer>',
        ]));
        self::assertSame('<h1>Card</h1><footer></footer> body', $arc->render('page', ['show' => true, 'value' => '']));
    }

    public function testConditionalSlotsAreResetForEveryLoopIteration(): void
    {
        $this->component('card', '@props(string $footer = \'default\')<footer>{!! $footer !!}</footer>');
        $this->view(
            'page',
            '@foreach($items as $item)<Card>@if($item)<Slot name="footer">{{ $item }}</Slot>@endif</Card>@endforeach',
        );
        self::assertSame('<footer>first</footer><footer>default</footer><footer>last</footer><footer>default</footer>', $this->arc()->render('page', ['items' => [
            'first',
            '',
            'last',
            '',
        ]]));
    }

    public function testNestedConditionalSlotsHaveIndependentDefaults(): void
    {
        $this->component(
            'card',
            '@props(string $footer = \'default\', string $head = \'head\'){!! $head !!}|{!! $footer !!}|{!! $slot !!}',
        );
        $this->view(
            'page',
            '<Card><Slot name="head">Heading</Slot>@if($show)<Slot name="footer"><Card>@if(false)<Slot name="footer">inner</Slot>@endif</Card></Slot>@endif</Card>',
        );
        self::assertSame('Heading|head|default||', $this->arc()->render('page', ['show' => true]));
        self::assertSame('Heading|default|', $this->arc()->render('page', ['show' => false]));
    }

    public function testLowercaseTagsAreAlwaysHtml(): void
    {
        $this->component('card', '<b>card</b>');
        $this->component('button', '<b>{!! $slot !!}</b>');
        $this->view(
            'page',
            '<div><card></card><my-widget a="1"></my-widget><svg><linearGradient/></svg><button>x</button><Button>Go</Button><Card /></div>',
        );
        self::assertSame(
            '<div><card></card><my-widget a="1"></my-widget><svg><linearGradient/></svg><button>x</button><b>Go</b><b>card</b></div>',
            $this->arc()->render('page'),
        );
    }

    public function testATagNamesItsFileInKebabCase(): void
    {
        mkdir($this->directory . '/views/components/shop');
        $this->component('product-grid', 'grid');
        $this->component('html-box', 'box');
        $this->component('shop.price-tag', 'tag');
        $this->view('page', '<ProductGrid /> <HTMLBox /> <Shop.PriceTag />');
        self::assertSame('grid box tag', $this->arc()->render('page'));

        $this->view('missing', "\n<OrderList />");
        $this->expectExceptionMessage('<OrderList> has no component: write components/order-list.arc.php');
        $this->arc()->render('missing');
    }

    public function testMistakesAreReportedWithTheirLine(): void
    {
        $this->component('card', '{!! $slot !!}');
        $cases = [
            "a\n@if(\$x)" => '@if is not closed in',
            '@else' => '@else is not inside an @if',
            "\n\n@endforeach" => 'on line 3',
            '<Card>' => '<Card> is not closed',
            '<Card></Card></Card>' => '</Card> closes no open <Card>',
            '<Slot name="a">x</Slot>' => '<Slot> belongs directly inside a component',
            '@if($x)<Card>@endif</Card>' => '@endif has no open @if',
            '<?= $x ?>' => 'in place of <?=',
            '@props(string $x)' => '@props belongs in a component',
            '{{ }}' => 'The value is empty',
            '<Card title=x></Card>' => 'must be in quotes',
            '@break' => '@break is not inside a loop',
        ];
        foreach ($cases as $source => $message) {
            $this->view('broken', $source);
            if ($message === 'text') {
                // A closing tag with nothing open is not Arc's: it stays text, as HTML would.
                self::assertSame('</Card>', $this->arc()->render('broken'));
                continue;
            }
            try {
                $this->arc()->render('broken', ['x' => true]);
                self::fail("{$source} compiled.");
            } catch (ArcException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $source);
                self::assertStringContainsString('broken.arc.php', $error->getMessage(), $source);
            }
        }
    }

    public function testComponentPropsAreCheckedAtCompileTime(): void
    {
        $this->component('card', '@props(string $title, string $footer = \'\'){!! $title !!}{!! $footer !!}');
        $this->view('unknown', "\n<Card wrong=\"x\" />");
        try {
            $this->arc()->render('unknown');
            self::fail('An undeclared prop compiled.');
        } catch (ArcException $error) {
            self::assertStringContainsString('has no prop $wrong', $error->getMessage());
            self::assertStringContainsString('on line 2', $error->getMessage());
        }

        $this->view('missing', '<Card />');
        $this->expectExceptionMessage('needs the prop $title');
        $this->arc()->render('missing');
    }

    public function testLoopControlCannotEscapeIntoAComponentClosure(): void
    {
        $this->component('card', '@props(string $slot = \'\'){!! $slot !!}');
        $this->view('page', "@foreach(\$items as \$item)\n<Card>@break</Card>\n@endforeach");

        $this->expectExceptionMessage('@break is not inside a loop');
        $this->arc()->render('page', ['items' => [1]]);
    }

    public function testComponentRuntimeErrorsNameTheCallingTemplateLine(): void
    {
        $this->component('card', '@props(string $title){!! $title->missing() !!}');
        $this->view('page', "\n<Card title=\"broken\" />");

        try {
            $this->arc()->render('page');
            self::fail('The component error was swallowed.');
        } catch (ArcException $error) {
            self::assertStringContainsString('on line 2', $error->getMessage());
            self::assertStringContainsString('page.arc.php', $error->getMessage());
            self::assertInstanceOf(\Error::class, $error->getPrevious());
        }
    }

    public function testATemplateIsCompiledOnceAndAgainWhenItChanges(): void
    {
        $this->view('page', 'one');
        self::assertSame('one', $this->arc()->render('page'));
        $compiled = glob($this->directory . '/cache/*.php');
        self::assertCount(1, $compiled);
        $before = filemtime($compiled[0]);
        self::assertSame('one', $this->arc()->render('page'), 'A second worker uses the compiled file.');
        self::assertSame([$compiled[0]], glob($this->directory . '/cache/*.php'));
        self::assertSame($before, filemtime($compiled[0]));

        $this->view('page', 'two');
        self::assertSame('two', $this->arc()->render('page'));
        self::assertCount(2, glob($this->directory . '/cache/*.php'));
        self::assertSame(2, $this->arc()->clear());
    }

    public function testAdjacentComponentErrorsNameTheFailingTagLine(): void
    {
        $this->component('good', 'good');
        $this->component('bad', '{{ missing_arc_test_function() }}');
        foreach (["<Good />\n<Bad />", "<Good>body</Good>\n<Good />\n<Bad />"] as $index => $source) {
            $this->view('page', $source);
            try {
                $this->arc()->render('page');
                self::fail('The failing component returned.');
            } catch (ArcException $error) {
                self::assertStringContainsString('page.arc.php on line ' . ($index + 2), $error->getMessage());
                self::assertInstanceOf(\Error::class, $error->getPrevious());
            }
        }
    }

    public function testCompileAllCompilesEveryTemplate(): void
    {
        mkdir($this->directory . '/views/orders');
        $this->view('orders.index', 'list');
        $this->view('home', 'home');
        $this->component('card', 'card');
        self::assertSame(3, $this->arc()->compileAll());
        self::assertCount(3, glob($this->directory . '/cache/*.php'));
    }

    public function testCachedCallersFollowComponentPropOrderAfterAnEdit(): void
    {
        $body = '<h1>{{ $title }}</h1><p>{{ $subtitle }}</p>';
        $this->component('card', '@props(string $title, string $subtitle)' . $body);
        $this->component('wrapper', '<Card title="Title" subtitle="Subtitle" />');
        $this->view('page', '<Wrapper />');
        $expected = '<h1>Title</h1><p>Subtitle</p>';
        self::assertSame($expected, $this->arc()->render('page'));

        $this->component('card', '@props(string $subtitle, string $title)' . $body);
        $fresh = $this->arc();
        self::assertSame(3, $fresh->compileAll());
        self::assertSame($expected, $fresh->render('page'));
        self::assertSame($expected, $this->arc()->render('page'));
    }

    public function testCachedCallersRevalidateRemovedAndRequiredProps(): void
    {
        $this->component('card', '@props(string $title){{ $title }}');
        $this->view('page', "\n<Card title=\"Title\" />");
        self::assertSame("\nTitle", $this->arc()->render('page'));

        foreach ([
            '@props(string $subtitle)' => 'has no prop $title',
            '@props(string $title, string $subtitle)' => 'needs the prop $subtitle',
        ] as $declaration => $message) {
            $this->component('card', $declaration);
            try {
                $this->arc()->compileAll();
                self::fail('The cached caller accepted an incompatible declaration.');
            } catch (ArcException $error) {
                self::assertStringContainsString($message, $error->getMessage());
                self::assertStringContainsString('page.arc.php on line 2', $error->getMessage());
            }
        }
    }

    public function testWarmRenderingKeepsLoadedClosuresWithoutReadingFiles(): void
    {
        $this->component('card', '@props(string $title){{ $title }}');
        $this->view('page', '<Card :title="$title" />');
        $arc = $this->arc();
        self::assertSame('first', $arc->render('page', ['title' => 'first']));
        rename($this->directory . '/views', $this->directory . '/hidden-views');
        rename($this->directory . '/cache', $this->directory . '/hidden-cache');
        self::assertSame('second', $arc->render('page', ['title' => 'second']));
    }

    public function testCompilationRejectsInvalidPhpAtItsTemplateLine(): void
    {
        foreach ([
            ["<p>\n{{ \$x + }}\n</p>",          false, 2],
            ["{{ 1 }}\n{{ 2 }}\n{!! \$x + !!}", false, 3],
            ["<?php\n\$x = 1;\n\$y = ;\n?>",    false, 3],
            ["\n@if(\$x +)yes@endif",           false, 2],
            ["\n@props(string \$x = )",         true,  2],
            ["{{\n\$x + }}",                    false, 2],
            ["@if(\n\$x +)yes@endif",           false, 2],
            ["@props(\nstring \$x = )",         true,  2],
        ] as [$source, $component, $line]) {
            try {
                new Compiler()->compile($source, 'broken.arc.php', $component, static fn(): bool => false);
                self::fail('Invalid PHP was compiled: ' . $source);
            } catch (ArcException $error) {
                self::assertStringContainsString('broken.arc.php on line ' . $line . '.', $error->getMessage());
                self::assertInstanceOf(\ParseError::class, $error->getPrevious());
            }
        }
    }

    public function testBranchesCannotFollowElse(): void
    {
        foreach (['@else', '@elseif(false)'] as $branch) {
            $this->view('broken', "@if(true)a@else b\n{$branch} c@endif");
            try {
                $this->arc()->compileAll();
                self::fail('A branch following @else compiled.');
            } catch (ArcException $error) {
                self::assertStringContainsString('cannot follow @else', $error->getMessage());
                self::assertStringContainsString('broken.arc.php on line 2', $error->getMessage());
            }
        }
    }

    public function testCacheCommandFailsForInvalidPhp(): void
    {
        $this->view('broken', "<p>\n{{ \$x + }}\n</p>");
        $app = new App(new Config([
            'app' => ['path' => $this->directory, 'storage' => $this->directory . '/storage'],
            'arc' => ['path' => $this->directory . '/views', 'cache' => $this->directory . '/cache'],
        ]));
        $app->register(ArcPlugin::class);
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('broken.arc.php on line 2'));
        $output->expects(self::never())->method('line');
        self::assertSame(1, $app->console->run(['arc:cache'], $output));
        self::assertSame([], glob($this->directory . '/cache/*.php'));
    }

    public function testCompileAllChecksExistingArtifactsWithoutExecutingTemplates(): void
    {
        $this->view('page', "<?php throw new \\RuntimeException('Do not run'); ?>\n{{ \$value }}");
        self::assertSame(1, $this->arc()->compileAll());
        $compiled = glob($this->directory . '/cache/*.php')[0];
        $code = file_get_contents($compiled);
        file_put_contents($compiled, str_replace('($value)', '($value +)', $code));
        $this->expectException(ArcException::class);
        $this->expectExceptionMessage('page.arc.php on line 2');
        $this->arc()->compileAll();
    }

    public function testNamesAreCheckedAndMissingTemplatesNamed(): void
    {
        foreach (['../secret', 'Orders.Index', 'a..b', ''] as $name) {
            try {
                $this->arc()->render($name);
                self::fail("{$name} was accepted.");
            } catch (ArcException $error) {
                self::assertStringContainsString('is not a template name', $error->getMessage());
            }
        }
        $this->expectExceptionMessage('The view missing has no template at');
        $this->arc()->render('missing');
    }

    public function testThePluginAndViewHelperRenderAResponse(): void
    {
        mkdir($this->directory . '/resources/views', 0o777, true);
        file_put_contents($this->directory . '/resources/views/hello.arc.php', 'Hello {{ $name }}');
        $app = App::configure($this->directory)->withPlugins([ArcPlugin::class], only: [])->create();

        $response = view('hello', ['name' => '<Ada>'], 201, ['X-Test' => 'yes']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Hello &lt;Ada&gt;', (string) $response->getBody());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('yes', $response->getHeaderLine('X-Test'));
        self::assertSame('Hello x', $app->container->get(Arc::class)->render('hello', ['name' => 'x']));
        self::assertNotSame([], glob($app->config->get('app.storage') . '/arc/*.php'), 'Compiled into storage/arc.');
    }

    public function testReadmeActionRegistersAndRendersWithConstructorServices(): void
    {
        $this->view('catalogue', '@foreach($products as $product){{ $product[\'name\'] }}@endforeach');
        $app = new App(new Config(['app' => ['path' => $this->directory, 'storage' => $this->directory . '/storage']]));
        $app->container->instance(Arc::class, $this->arc());
        $query = $this->createStub(Query::class);
        $query->method('get')->willReturn([['name' => '<Card>']]);
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('table')->with('products')->willReturn($query);
        $app->container->instance(Database::class, $database);
        $example = $this->directory . '/readme.php';
        file_put_contents(
            $example,
            "<?php\nnamespace ArcReadme;\n" . $this->readmeBlock('php', 'final class ShowCatalogue'),
        );
        require $example;
        $app->router->get('/catalogue', \ArcReadme\ShowCatalogue::class);
        $response = $app->router->handle(new Request('GET', '/catalogue'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('&lt;Card&gt;', (string) $response->getBody());

        mkdir($this->directory . '/views/mail');
        $this->view('mail.receipt', 'Order {{ $order[\'number\'] }}');
        $receiptExample = $this->directory . '/receipt.php';
        file_put_contents(
            $receiptExample,
            "<?php\nnamespace ArcReadme;\n" . $this->readmeBlock('php', 'final class ReceiptBody'),
        );
        require $receiptExample;
        $body = $app->container->get(\ArcReadme\ReceiptBody::class);
        self::assertSame('Order &lt;7&gt;', $body->render(['number' => '<7>']));
    }

    public function testReadmeUrlExamplesRejectUnsafeSchemesAndEscapeAttributes(): void
    {
        $prepare = $this->directory . '/prepare-url.php';
        file_put_contents($prepare, "<?php\n" . $this->readmeBlock('php', 'filter_var'));
        $safeUrl = static function (string $candidate) use ($prepare): string {
            $url = '';
            require $prepare;
            return $url;
        };
        $this->view('link', trim($this->readmeBlock('html', '<a href="{{ $url }}"')));
        $arc = $this->arc();
        foreach ([
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            '//evil.example',
            "java\nscript:alert(1)",
        ] as $candidate) {
            self::assertSame('<a href="/">Website</a>', $arc->render('link', ['url' => $safeUrl($candidate)]));
        }
        self::assertSame('<a href="https://example.com/?a=1&amp;b=2">Website</a>', $arc->render('link', [
            'url' => $safeUrl('https://example.com/?a=1&b=2'),
        ]));
        $this->view('local', trim($this->readmeBlock('html', '<a href="/products/')));
        self::assertSame('<a href="/products/%2F%2Fevil.example%2F%22%3F%26">View product</a>', $arc->render('local', [
            'slug' => '//evil.example/"?&',
        ]));
    }

    public function testBenchmarkCommandsAreExecutableFencedShellExamples(): void
    {
        $markdown = file_get_contents(dirname(__DIR__) . '/BENCHMARKS.md');
        preg_match_all('/^```sh\n(.*?)^```/ms', $markdown, $matches);
        self::assertCount(2, $matches[1]);
        foreach ($matches[1] as $command) {
            self::assertStringContainsString('-d opcache.jit=0', $command);
            $arguments = preg_split('/\s+/', trim(str_replace('30000', '1', $command)));
            $arguments[0] = PHP_BINARY;
            array_splice($arguments, 1, 0, ['-d', 'sys_temp_dir=' . $this->directory]);
            $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertStringContainsString('Arc', $output);
            self::assertStringContainsString('us/render', $output);
        }
    }

    private function arc(): Arc
    {
        return new Arc($this->directory . '/views', $this->directory . '/cache');
    }

    private function readmeBlock(string $language, string $contains): string
    {
        $markdown = file_get_contents(dirname(__DIR__) . '/README.md');
        preg_match_all('/^```' . $language . '\n(.*?)^```/ms', $markdown, $matches);
        foreach ($matches[1] as $code) {
            if (str_contains($code, $contains)) {
                return $code;
            }
        }
        self::fail('No README example contains ' . $contains);
    }

    private function view(string $name, string $source): void
    {
        file_put_contents($this->directory . '/views/' . str_replace('.', '/', $name) . '.arc.php', $source);
    }

    private function component(string $name, string $source): void
    {
        file_put_contents($this->directory . '/views/components/' . str_replace('.', '/', $name) . '.arc.php', $source);
    }
}
