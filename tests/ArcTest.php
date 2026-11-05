<?php

declare(strict_types=1);

namespace Crab\Arc\Tests;

use Crab\App;
use Crab\Arc\Arc;
use Crab\Arc\ArcException;
use Crab\Arc\ArcPlugin;
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

    public function testCompileAllCompilesEveryTemplate(): void
    {
        mkdir($this->directory . '/views/orders');
        $this->view('orders.index', 'list');
        $this->view('home', 'home');
        $this->component('card', 'card');
        self::assertSame(3, $this->arc()->compileAll());
        self::assertCount(3, glob($this->directory . '/cache/*.php'));
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

    private function arc(): Arc
    {
        return new Arc($this->directory . '/views', $this->directory . '/cache');
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
