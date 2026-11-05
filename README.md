# Arc

Arc is the template engine of the [Crab framework](https://github.com/crabphp/framework). Templates are HTML with `{{ }}` values, `@if` and `@foreach`, and components written as capitalised tags. Each template is compiled once to a plain PHP closure, so rendering a page costs about what a raw PHP template does, with every value escaped.

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
use Crab\Http\Response;

final class ShowCatalogue
{
    public function handle(Database $db): Response
    {
        return view('catalogue', ['products' => $db->table('products')->get()]);
    }
}

return view('orders.missing', status: 404);
return view('orders.index', $data)->withCookie(new Cookie('seen', '1'));
```

`view()` uses the worker's renderer, as `config()` uses its configuration. To get a string, for an email body or a fragment, take `Crab\Arc\Arc` as a parameter and call `$arc->render('mail.receipt', $data)`.

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
- `<Slot name="footer">` fills the prop `$footer`. It must be declared, as a `string` with a default when it is optional.
- The content between the tags is `$slot`, an empty string when there is none.

A layout is a component like any other: there is no `@extends` or `@section`. The page puts itself in its layout, and a named slot fills each place the layout leaves open.

A prop the component does not declare, or a required one left out, is a compile error at the tag's line. Arc reads the component's @props declaration while compiling the caller. A component that has no file is also a compile error at the tag's line. If a component throws while it is called, the error names the calling template and tag line.

## How it stays fast

- **Compiled once.** A template compiles to a closure in `storage/arc`, under a name made from its content and Arc's version. A worker loads each one once and keeps it: rendering is one call, with no file checks.
- **One string.** The page is built by appending to a local string. Adjacent text and values become one append.
- **Escaping inline.** `{{ }}` compiles to `htmlspecialchars()`, with no helper between.
- **Components are calls.** A component is a closure, looked up once per render, and each use is one call. When its @props declaration makes it safe, Arc emits positional arguments; otherwise it uses named arguments. Slots are rendered to strings before the call.

Render a page of 50 product cards in a layout, with the JIT off, and Arc takes about as long as the same page written as a raw PHP template with `ob_start()` and `include`.

The reproducible numbers and benchmark script are in [BENCHMARKS.md](BENCHMARKS.md).

## Deploying and developing

Compile every template when deploying, so that no worker compiles while it serves; a template that does not compile fails the deploy:

```sh
php cua arc:cache
php cua arc:clear      # delete the compiled templates
```

An edited template is compiled again when a new worker first renders it. The development server restarts its workers when a watched file changes: add `resources` to `server.watch` in `config/server.php`.

## Settings

| Setting | Default | |
| --- | --- | --- |
| `arc.path` | `resources/views` | The templates, relative to the application |
| `arc.cache` | `storage/arc` | Where compiled templates are written |

## License

MIT
