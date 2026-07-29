<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Selectors
    |--------------------------------------------------------------------------
    |
    | CSS selectors used to pull products out of a listing page. "card" is the
    | repeating container; the rest are resolved relative to it. Retarget these
    | per store — the defaults point at https://scrapeme.live/shop/, a
    | WooCommerce storefront published for scraping practice.
    |
    */

    'selectors' => [
        'card' => env('SCRAPER_SELECTOR_CARD', 'li.product'),
        'name' => env('SCRAPER_SELECTOR_NAME', '.woocommerce-loop-product__title'),
        'price' => env('SCRAPER_SELECTOR_PRICE', 'span.price'),
        'link' => env('SCRAPER_SELECTOR_LINK', 'a'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Throttle
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed across every queue worker, enforced by the
    | "scraper" rate limiter registered in AppServiceProvider.
    |
    */

    'requests_per_minute' => (int) env('SCRAPER_REQUESTS_PER_MINUTE', 10),

];
