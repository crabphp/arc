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
| `@json($value)` | A value as JSON inside `<script>` |
| `@@`, `@{{` | A literal `@` and `{{` |

A line that holds only a statement, such as `@foreach(...)` or `{{-- --}}`, leaves no blank line in the page. An `@` that is not one of these, as in an email address, is text.

A view's variables are the data given to it. `<?=` is refused: Arc builds each page as a string rather than through PHP's output buffer, which the worker shares between the requests it serves at once.

Each `<?php ... ?>` block must contain complete statements, with any PHP control structures closed inside that block. Use Arc directives such as `@if` and `@foreach` to control template markup, including named slots. PHP control flow spanning blocks (for example, `<?php if ($show) { ?>...<?php } ?>`) and `goto` are compile errors. A complete PHP conditional that only sets variables inside one block is supported.

### Importing names

A `<?php` block at the very top of a template may start with `use` statements, so the rest of it names classes, functions and constants by their short names. Arc puts them at the top of the compiled file, where PHP takes them:

```html
<?php
use App\Models\Product;
use App\Shop\Carts;
?>
@props(Product $product)
<input type="hidden" name="actionId" value="{{ Carts::uuid() }}">
```

Every form PHP has works: `use X as Y`, `use function`, `use const` and a group, `use App\Models\{Product, Category}`. The block may go on with other statements after its imports. A `use` anywhere else, after markup or another statement, is a compile error at its line, and so is importing a name from the global namespace, such as `use ArrayObject;`, which PHP warns has no effect. A closure's `use ($x)` is not an import and works anywhere.

### Escaping by context

`{{ }}` escapes `<`, `>`, `&` and both quotes. Put attribute values inside quotes. HTML escaping does not validate URL schemes, and does not make a value safe inside JavaScript, CSS, `style`, or event-handler attributes such as `onclick`. Keep untrusted data out of those contexts, and hand data to JavaScript in one of two ways:

```html
<script>window.cart = @json($cart);</script>
<div x-data="{{ json_encode($cart) }}"></div>
```

Inside `<script>`, `@json($value)` writes the value as JSON with `<`, `>`, `&`, `'` and `"` inside its strings written as `\u` escapes, so no value can end the script or open a comment, and floats keep their fraction. A value JSON cannot hold, such as `INF`, throws `JsonException`. In an attribute, write `{{ json_encode($value) }}`: `{{ }}` escapes the JSON's own quotes, and the browser undoes that before JavaScript reads the attribute. `@json` is not for a double-quoted attribute, where the JSON's quotes would end it.

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

### Attributes it does not declare

HTML has more attributes than a component can name: `id`, `aria-*`, `data-*`, and those of htmx or Alpine. A component that declares `array $attributes = []` takes every attribute it does not declare as a prop, under its HTML name, and writes them with `@attributes`:

```html
@props(string $variant = 'primary', array $attributes = [])
<button @attributes($attributes, ['type' => 'button', 'class' => "btn btn-{$variant}"])>
    {!! $slot !!}
</button>
```

```html
<Button variant="danger" class="mt-4" aria-label="Delete order" hx-post="/orders/{{ $order->id }}/delete" disabled>
    Delete
</Button>
```

```html
<button type="button" class="btn btn-danger mt-4" aria-label="Delete order" hx-post="/orders/42/delete" disabled>
    Delete
</button>
```

- `@attributes($attributes, $defaults)` writes the caller's attributes over the component's defaults, escaped for quoted attributes. `class` is joined, the defaults' first; any other attribute the caller gives replaces the default.
- A class can be a list, where a key is kept when its value is true: `:class="['tab', 'active' => $current]"`.
- `true` writes a bare attribute, such as `disabled`; `false` and `null` leave the attribute out, including a default: `:type="null"`.
- Names a prop cannot have, such as `@click`, `x-on:click.prevent` or `hx-on::after-request`, are kept as written. A leading `:` still means a PHP expression, so Alpine's `:class` shorthand is written `x-bind:class` on a component.
- A component passes its attributes on with `:attributes="$attributes"`; attributes written beside it are merged in the same way.
- Without `array $attributes = []`, an attribute the component does not declare stays a compile error at the tag's line.

Escaping covers the quoted value; the context rules above still apply to what the value means, such as a URL in `href` or code in `hx-on`.

Variable names beginning with `$__` belong to Arc's compiler. `@props` rejects every name with that prefix, including `$__o`, `$__arc`, and generated slot and component variables. Choose other names for template locals and view data too; a single leading underscore is allowed.

## How it stays fast

- **Cached compilation.** A template compiles to a closure in `storage/view`, under a name made from its source, its referenced component sources, Arc's compiler version and the PHP version. A small dependency index lets a new worker check its callers without recompiling unchanged templates. The worker loads each closure once and keeps it: warm rendering has no file checks.
- **One string.** The page is built by appending to a local string. Adjacent text and values become one append.
- **Escaping inline.** `{{ }}` compiles to `htmlspecialchars()`, with no helper between.
- **Components are calls.** Each component type is a closure, looked up once per template render, and each use is one call. When its @props declaration makes it safe, Arc emits positional arguments; otherwise it uses named arguments. Slots are rendered to strings before the call. Conditional named slots omit absent arguments so PHP applies the component's defaults.

The speed target is second only to raw PHP, measured with JIT off. The current benchmark compares one warm page of 50 product cards with two PHP implementations. It does not establish a ranking among template engines; the raw-include comparison also depends on whether OPcache is enabled.

The reproducible numbers and benchmark script are in [BENCHMARKS.md](BENCHMARKS.md).

## Deploying and developing

Compile every template when deploying, then restart workers to load the new templates. Compiling checks the generated PHP in a `php -l` process of its own, without executing template code: syntax errors and PHP compile-time fatal errors fail the command with the template path and source line. This needs `proc_open`, a writable temporary directory and a PHP CLI executable at `PHP_BINARY`, and fails closed if it cannot run. The check cannot detect every runtime error or missing data value.

A compiled template carries the hash of the PHP that passed the check, and its name includes the PHP version. A worker loads it after comparing the hash, in its own process, so serving needs no `proc_open` and starts no process. A compiled template that was changed afterwards, or that an older Arc or another PHP version wrote, is compiled again from its template; it is never loaded. Templates compiled when deploying mean workers never compile at all.

```sh
php cua view:cache
```

To delete compiled templates and dependency indexes as a separate maintenance operation:

```sh
php cua view:clear
```

An edited template, or a caller whose component source changes, is compiled again on deployment or when a new worker first loads it. Loaded workers keep their closures until restarted. The development server restarts its workers when a watched file changes: add `resources` to `server.watch` in `config/server.php`.

The plugin is named Arc, but its console commands use the framework-level `view:` namespace so deployment tooling does not depend on the view engine's implementation name.

## Settings

| Setting | Default | |
| --- | --- | --- |
| `view.path` | `resources/views` | The templates, relative to the application |
| `view.cache` | `storage/view` | Where compiled templates are written |

## Stability

Arc follows [semantic versioning](https://semver.org). Before 1.0, a minor version may change template syntax or behaviour, and its entry in the [changelog](CHANGELOG.md) says what to change in your templates; a patch version only fixes. A change that could alter rendered output, such as a new directive taking over `@name` text, is always called out there with how to keep the old output (`@@name`).

What Arc promises in every version: `{{ }}` escapes, an undeclared or missing prop is a compile error at the tag's line, and a worker only ever loads a compiled template that passed the PHP check.

## License

MIT
