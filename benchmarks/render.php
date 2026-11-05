<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/arc-benchmark-' . bin2hex(random_bytes(6));
mkdir($directory . '/views/components', 0o777, true);
mkdir($directory . '/cache', 0o777, true);

file_put_contents($directory . '/views/components/layout.arc.php', <<<'ARC'
@props(string $title, string $slot = '')
<!doctype html><html><head><title>{{ $title }}</title></head><body><header>Crab Shop</header><main>{!! $slot !!}</main></body></html>
ARC);
file_put_contents($directory . '/views/components/product-card.arc.php', <<<'ARC'
@props(array $product, string $slot = '')
<article><h2>{{ $product['name'] }}</h2><p>{{ $product['category'] }}</p><strong>{{ $product['price'] }}</strong>@if($product['stock'] > 0)<span>In stock</span>@else<span>Sold out</span>@endif{!! $slot !!}</article>
ARC);
file_put_contents($directory . '/views/catalogue.arc.php', <<<'ARC'
<Layout title="Catalogue">
    <h1>{{ $heading }}</h1>
    @foreach($products as $product)
        <ProductCard :product="$product" />
    @endforeach
    @if($featured)<p>Featured collection</p>@endif
</Layout>
ARC);

$products = [];
foreach (range(1, 50) as $id) {
    $products[] = [
        'name' => "Product {$id}",
        'category' => $id % 2 === 0 ? 'home' : 'desk',
        'price' => $id * 100,
        'stock' => $id % 7,
    ];
}
$data = ['heading' => 'Catalogue', 'products' => $products, 'featured' => true];

$arc = new Crab\Arc\Arc($directory . '/views', $directory . '/cache');
$arc->render('catalogue', $data);

$rawFile = $directory . '/raw-template.php';
file_put_contents($rawFile, <<<'PHP'
<?php
echo '<!doctype html><html><head><title>Catalogue</title></head><body><header>Crab Shop</header><main>';
echo '<h1>' . htmlspecialchars($data['heading'], ENT_QUOTES | ENT_SUBSTITUTE) . '</h1>';
foreach ($data['products'] as $product) {
    echo '<article><h2>' . htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE) . '</h2>';
    echo '<p>' . htmlspecialchars($product['category'], ENT_QUOTES | ENT_SUBSTITUTE) . '</p>';
    echo '<strong>' . htmlspecialchars((string) $product['price'], ENT_QUOTES | ENT_SUBSTITUTE) . '</strong>';
    echo $product['stock'] > 0 ? '<span>In stock</span>' : '<span>Sold out</span>';
    echo '</article>';
}
if ($data['featured']) echo '<p>Featured collection</p>';
echo '</main></body></html>';
PHP);

$raw = static function (array $data) use ($rawFile): string {
    ob_start();
    include $rawFile;
    return (string) ob_get_clean();
};
$hand = static function (array $data): string {
    $html = '<!doctype html><html><head><title>Catalogue</title></head><body><header>Crab Shop</header><main>';
    $html .= '<h1>' . htmlspecialchars($data['heading'], ENT_QUOTES | ENT_SUBSTITUTE) . '</h1>';
    foreach ($data['products'] as $product) {
        $html .= '<article><h2>' . htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE) . '</h2>'
            . '<p>' . htmlspecialchars($product['category'], ENT_QUOTES | ENT_SUBSTITUTE) . '</p>'
            . '<strong>' . htmlspecialchars((string) $product['price'], ENT_QUOTES | ENT_SUBSTITUTE) . '</strong>'
            . ($product['stock'] > 0 ? '<span>In stock</span>' : '<span>Sold out</span>')
            . '</article>';
    }
    if ($data['featured']) {
        $html .= '<p>Featured collection</p>';
    }
    return $html . '</main></body></html>';
};

$iterations = (int) ($_SERVER['argv'][1] ?? 20_000);
$results = [];
foreach (['raw include' => $raw, 'hand string' => $hand, 'Arc' => static fn(array $value): string => $arc->render('catalogue', $value)] as $name => $render) {
    for ($i = 0; $i < 1_000; ++$i) {
        $render($data);
    }
    $start = hrtime(true);
    $bytes = 0;
    for ($i = 0; $i < $iterations; ++$i) {
        $bytes += strlen($render($data));
    }
    $elapsed = hrtime(true) - $start;
    $results[$name] = [$elapsed / $iterations / 1_000, $bytes];
}

foreach ($results as $name => [$microseconds, $bytes]) {
    printf("%-12s %8.2f us/render (%d bytes)\n", $name, $microseconds, $bytes / $iterations);
}
