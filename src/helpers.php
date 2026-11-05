<?php

declare(strict_types=1);

use Crab\App;
use Crab\Arc\Arc;
use Crab\Http\Response;

if (!function_exists('view')) {
    /**
     * A view as an HTML response: return view('orders.index', ['orders' => $orders]).
     * The renderer is the worker's, like config(); a string is $arc->render().
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $headers
     */
    function view(string $view, array $data = [], int $status = 200, array $headers = []): Response
    {
        return App::current()->container->get(Arc::class)->response($view, $data, $status, $headers);
    }
}
