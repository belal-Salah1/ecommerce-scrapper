<?php

use App\Jobs\ScrapeProductPage;
use App\Models\Product;
use App\Services\Scrapper\PageFetcher;
use App\Services\Scrapper\ProductFetcher;
use App\Services\Scrapper\ProductStorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function listingHtml(string $price = '$19.99'): string
{
    return <<<HTML
    <html><body>
        <div class="product-card">
            <a href="https://shop.test/p/1"><span class="product-title">Running Shoe</span></a>
            <span class="price">{$price}</span>
        </div>
        <div class="product-card">
            <a href="https://shop.test/p/2"><span class="product-title">Trail Shoe</span></a>
            <span class="price">\$29.50</span>
        </div>
    </body></html>
    HTML;
}

function runScrapeJob(string $url = 'https://shop.test/category/shoes'): void
{
    (new ScrapeProductPage($url))->handle(
        app(PageFetcher::class),
        app(ProductFetcher::class),
        app(ProductStorer::class),
    );
}

it('fetches, parses, and stores products from a listing page', function () {
    Http::fake(['shop.test/*' => Http::response(listingHtml())]);

    runScrapeJob();

    expect(Product::count())->toBe(2);
    expect(Product::where('url', 'https://shop.test/p/1')->first())
        ->name->toBe('Running Shoe')
        ->price->toBe('$19.99');
});

it('updates the price on re-scrape instead of duplicating the product', function () {
    Http::fake(['shop.test/*' => Http::response(listingHtml('$14.99'))]);

    runScrapeJob();
    runScrapeJob();

    expect(Product::count())->toBe(2);
    expect(Product::where('url', 'https://shop.test/p/1')->first()->price)->toBe('$14.99');
});

it('skips cards with no link rather than storing a placeholder url', function () {
    Http::fake(['shop.test/*' => Http::response(
        '<div class="product-card"><span class="product-title">Orphan</span><span class="price">$5</span></div>'
    )]);

    runScrapeJob();

    expect(Product::count())->toBe(0);
});

it('declares a resolvable rate limiter as its queue middleware', function () {
    $middleware = (new ScrapeProductPage('https://shop.test/x'))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class);
});

it('dispatches the job via the scraper:run command', function () {
    Queue::fake();

    $this->artisan('scraper:run', ['url' => 'https://shop.test/category/shoes'])
        ->assertExitCode(0);

    Queue::assertPushed(ScrapeProductPage::class);
});
