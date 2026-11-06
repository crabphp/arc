# Arc

Arc is the template engine of the [Crab framework](https://github.com/crabphp/framework). Templates are HTML with `{{ }}` values, `@if` and `@foreach`, and components written as capitalised tags. Templates compile to plain PHP closures that build strings. `{{ }}` escapes values for HTML text and quoted HTML attributes; other contexts need the handling described below.

```html
<Layout title="Catalogue">
    <Slot name="head"><link rel="stylesheet" href="/shop.css"></Slot>

    <h1>{{ $category }}</h1>
    @foreach($products as $product)
        <ProductCard :product="$product" url="/products/{{ $product->id }}" />
    @endforeach
</Layout>
```

## Installation

```sh
composer require crabphp/arc
```

List its plugin in `app.php`:

```php
use Crab\App;
use Crab\Arc\ArcPlugin;

return App::configure(__DIR__)
    ->withPlugins([ArcPlugin::class])
    ->withRouting(routes: 'routes/web.php')
    ->create();
```

Templates live in `resources/views` and are named after their path: the view `orders.index` is `resources/views/orders/index.arc.php`. Keep them out of `public/`: a `.php` file there could be run by a web server put in front of the application.

## Rendering

An action returns `view()`, an HTML response:

```php
use Crab\Contracts\Database\Database;
use Crab\Http\Response;

final class ShowCatalogue
{
    public function __construct(private readonly Database $db) {}

    public function handle(): Response
    {
        return view('catalogue', ['products' => $this->db->table('products')->get()]);
    }
}
```

Services belong in the action's constructor. `handle()` receives request inputs. The response can carry a status or cookies:

```php
use Crab\Http\Cookie;

return view('orders.missing', status: 404);
return view('orders.index', $data)->withCookie(new Cookie('seen', '1'));
```

`view()` returns a `Response` using the worker's renderer, as `config()` uses its configuration. To get a string, for an email body or a fragment, inject `Arc` into the constructor too:

```php
use Crab\Arc\Arc;

final class ReceiptBody
{
    public function __construct(private readonly Arc $arc) {}

    public function render(array $order): string
    {
        return $this->arc->render('mail.receipt', ['order' => $order]);
    }
}
```

## Templates

| Write | For |
| --- | --- |
| `{{ $value }}` | A value, escaped for HTML: `<`, `>`, `&` and both quotes |
| `{!! $html !!}` | HTML you trust, as it is |
| `{{-- note --}}` | A comment, left out of the page |
| `@if(...)` `@elseif(...)` `@else` `@endif` | Choosing |
| `@unless(...)` `@endunless` | `@if` the other way round |
| `@foreach(...)` `@endforeach`, `@for(...)` `@endfor`, `@while(...)` `@endwhile` | Loops, with `@break` and `@continue` |
| `<?php ... ?>` | PHP statements, such as setting a variable. Do not echo: write the result with `{{ }}` |
| `@@`, `@{{` | A literal `@` and `{{` |

A line that holds only a statement, such as `@foreach(...)` or `{{-- --}}`, leaves no blank line in the page. An `@` that is not one of these, as in an email address, is text.

A view's variables are the data given to it. `<?=` is refused: Arc builds each page as a string rather than through PHP's output buffer, which the worker shares between the requests it serves at once.

### Escaping by context

`{{ }}` escapes `<`, `>`, `&` and both quotes. Put attribute values inside quotes. HTML escaping does not validate URL schemes, and does not make a value safe inside JavaScript, CSS, `style`, or event-handler attributes such as `onclick`. Keep untrusted data out of those contexts; a quoted `data-*` attribute lets JavaScript read a string without inserting it into executable code.

Build local links from a fixed path and encode each dynamic path segment:

```html
<a href="/products/{{ rawurlencode($slug) }}">View product</a>
```

For an external URL supplied as data, validate it and allow only the intended schemes before rendering. For example, prepare `$url` in the action and pass it to the view:

```php
$validated = filter_var($candidate, FILTER_VALIDATE_URL);
$scheme = $validated === false ? null : strtolower((string) parse_url($validated, PHP_URL_SCHEME));
$url = in_array($scheme, ['https', 'http'], true) ? $validated : '/';
```

```html
<a href="{{ $url }}">Website</a>
```

`{!! !!}` inserts trusted HTML without escaping. Slots are markup assembled by the calling template: escape data inside them with `{{ }}` before the component outputs their HTML. Ordinary data props still need escaping wherever the component uses them.

## Components

A component is a capitalised tag; lowercase tags are always HTML, so a component never clashes with an HTML element or a web component. Its template is in `resources/views/components`, named in kebab case: `<ProductCard>` is `components/product-card.arc.php`, `<Forms.TextField>` is `components/forms/text-field.arc.php`.

A component declares its props as PHP parameters with `@props`, and outputs what was put between its tags with `{!! $slot !!}`:

```html
@props(string $title, int $total = 0, string $footer = '')
<section class="card">
    <h2>{{ $title }}</h2>
    {!! $slot !!}
    <footer>{!! $footer !!}</footer>
</section>
```

```html
<Card title="Order #{{ $order->id }}" :total="$order->total">
    <Slot name="footer"><a href="/orders">All orders</a></Slot>
    {{ $order->status }}
</Card>

<Card title="Empty" />
```

- `title="..."` gives text, with `{{ }}` values joined in. A prop is data: the component escapes it where it outputs it.
- `:total="..."` gives the result of a PHP expression.
- An attribute with no value, such as `required`, gives `true`.
- An attribute in kebab case fills the prop in camel case: `data-id` is `$dataId`.
- `<Slot name="footer">` fills the prop `$footer`. It must be declared, as a `string` with a default when it is optional. If a conditional slot is not rendered, that default applies; an explicitly empty slot supplies an empty string.
- The content between the tags is `$slot`, an empty string when there is none.

A layout is a component like any other: there is no `@extends` or `@section`. The page puts itself in its layout, and a named slot fills each place the layout leaves open.

A prop the component does not declare, or a required one left out, is a compile error at the tag's line. Arc reads the component's @props declaration while compiling the caller. A component that has no file is also a compile error at the tag's line. If a component throws while it is called, the error names the calling template and tag line.

## How it stays fast

- **Cached compilation.** A template compiles to a closure in `storage/arc`, under a name made from its source, its referenced component sources, and Arc's compiler version. A small dependency index lets a new worker check its callers without recompiling unchanged templates. The worker loads each closure once and keeps it: warm rendering has no file checks.
- **One string.** The page is built by appending to a local string. Adjacent text and values become one append.
- **Escaping inline.** `{{ }}` compiles to `htmlspecialchars()`, with no helper between.
- **Components are calls.** Each component type is a closure, looked up once per template render, and each use is one call. When its @props declaration makes it safe, Arc emits positional arguments; otherwise it uses named arguments. Slots are rendered to strings before the call. Conditional named slots omit absent arguments so PHP applies the component's defaults.

The speed target is second only to raw PHP, measured with JIT off. The current benchmark compares one warm page of 50 product cards with two PHP implementations. It does not establish a ranking among template engines; the raw-include comparison also depends on whether OPcache is enabled.

The reproducible numbers and benchmark script are in [BENCHMARKS.md](BENCHMARKS.md).

## Deploying and developing

Compile every template when deploying, then restart workers to load the new templates. Compilation validates generated PHP with an isolated `php -l` process, without executing template code. Syntax errors and PHP compile-time fatal errors fail the command with the template path and source line. This requires `proc_open`, a writable temporary directory, and a PHP CLI executable at `PHP_BINARY`; validation fails closed if it cannot run. `arc:cache` and a worker's first load also validate existing artifacts. Loaded closures need no further validation on warm renders. The check cannot detect every runtime error or missing data value.

```sh
php cua arc:cache
```

To delete compiled templates and dependency indexes as a separate maintenance operation:

```sh
php cua arc:clear
```

An edited template, or a caller whose component source changes, is compiled again on deployment or when a new worker first loads it. Loaded workers keep their closures until restarted. The development server restarts its workers when a watched file changes: add `resources` to `server.watch` in `config/server.php`.

## Settings

| Setting | Default | |
| --- | --- | --- |
| `arc.path` | `resources/views` | The templates, relative to the application |
| `arc.cache` | `storage/arc` | Where compiled templates are written |

## License

MIT
