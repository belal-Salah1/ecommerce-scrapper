<?php

use App\Exceptions\Scrapper\NoProductsFoundException;
use App\Jobs\ScrapeProductPage;
use App\Models\Product;
use App\Services\Scrapper\PageFetcher;
use App\Services\Scrapper\ProductFetcher;
use App\Services\Scrapper\ProductSelectors;
use App\Services\Scrapper\ProductStorer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Mirrors the WooCommerce markup shape of the default target,
 * https://scrapeme.live/shop/.
 */
function productCard(string $name, string $url, string $price): string
{
    return <<<HTML
    <li class="product">
        <a href="{$url}">
            <h2 class="woocommerce-loop-product__title">{$name}</h2>
            <span class="price"><span class="amount">{$price}</span></span>
        </a>
    </li>
    HTML;
}

function listingHtml(string $price = '£63.00'): string
{
    return '<ul class="products">'
        .productCard('Bulbasaur', 'https://shop.test/p/1', $price)
        .productCard('Ivysaur', 'https://shop.test/p/2', '£87.00')
        .'</ul>';
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
        ->name->toBe('Bulbasaur')
        ->price->toBe('£63.00');
});

it('updates the price on re-scrape instead of duplicating the product', function () {
    Http::fake(['shop.test/*' => Http::response(listingHtml('£14.99'))]);

    runScrapeJob();
    runScrapeJob();

    expect(Product::count())->toBe(2);
    expect(Product::where('url', 'https://shop.test/p/1')->first()->price)->toBe('£14.99');
});

it('throws when the card selector matches nothing', function () {
    Http::fake(['shop.test/*' => Http::response('OK')]);

    runScrapeJob();
})->throws(NoProductsFoundException::class, 'No elements matched the product selector');

it('throws when cards match but every inner field selector misses', function () {
    Http::fake(['shop.test/*' => Http::response(
        '<li class="product"><h2 class="renamed">Renamed Title</h2><b>£5</b></li>'
    )]);

    runScrapeJob();
})->throws(NoProductsFoundException::class, 'none yielded a name, price, and link');

it('stores nothing when the page yields no usable products', function () {
    Http::fake(['shop.test/*' => Http::response('<div class="nope"></div>')]);

    try {
        runScrapeJob();
    } catch (NoProductsFoundException) {
        // asserted separately; here we only care that no junk was written
    }

    expect(Product::count())->toBe(0);
});

it('skips a malformed card but keeps the usable ones on the same page', function () {
    Http::fake(['shop.test/*' => Http::response(
        '<li class="product"><h2 class="woocommerce-loop-product__title">Orphan</h2><span class="price">£5</span></li>'
        .productCard('Good', 'https://shop.test/p/9', '£7.00')
    )]);

    runScrapeJob();

    expect(Product::count())->toBe(1);
    expect(Product::first())->name->toBe('Good')->url->toBe('https://shop.test/p/9');
});

it('never invents placeholder values for missing fields', function () {
    Http::fake(['shop.test/*' => Http::response(
        productCard('Real', 'https://shop.test/p/1', '£3.00')
        .'<li class="product"><a href="https://shop.test/p/2"></a></li>'
    )]);

    runScrapeJob();

    expect(Product::pluck('name'))->not->toContain('default name')
        ->and(Product::pluck('price'))->not->toContain('0.00$')
        ->and(Product::pluck('url'))->not->toContain('#');
});

it('trims surrounding whitespace out of scraped fields', function () {
    Http::fake(['shop.test/*' => Http::response(
        '<li class="product"><a href="  https://shop.test/p/1  ">'
        .'<h2 class="woocommerce-loop-product__title">  Spaced Shoe  </h2>'
        .'<span class="price">  £8.00  </span></a></li>'
    )]);

    runScrapeJob();

    expect(Product::first())
        ->name->toBe('Spaced Shoe')
        ->price->toBe('£8.00')
        ->url->toBe('https://shop.test/p/1');
});

it('rate limits and fails fast on an unparseable page', function () {
    $middleware = (new ScrapeProductPage('https://shop.test/x'))->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(RateLimited::class)
        ->and($middleware[1])->toBeInstanceOf(FailOnException::class);
});

it('marks the job failed instead of retrying when the selectors miss', function () {
    $job = new ScrapeProductPage('https://shop.test/category/shoes');

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(NoProductsFoundException::class));
    $job->setJob($queueJob);

    $failOnException = collect($job->middleware())
        ->first(fn ($m) => $m instanceof FailOnException);

    // The middleware rethrows after failing the job; fail() is what stops the retry.
    expect(fn () => $failOnException->handle($job, function () {
        throw NoProductsFoundException::noCardsMatched('.product-card');
    }))->toThrow(NoProductsFoundException::class);
});

it('still retries transient network failures', function () {
    $job = new ScrapeProductPage('https://shop.test/category/shoes');

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $failOnException = collect($job->middleware())
        ->first(fn ($m) => $m instanceof FailOnException);

    expect(fn () => $failOnException->handle($job, function () {
        throw new ConnectionException('cURL error 60: SSL certificate problem');
    }))->toThrow(ConnectionException::class);
});

it('dispatches the job via the scraper:run command', function () {
    Queue::fake();

    $this->artisan('scraper:run', ['url' => 'https://shop.test/category/shoes'])
        ->assertExitCode(0);

    Queue::assertPushed(ScrapeProductPage::class);
});

it('parses real markup captured from the default target site', function () {
    $fixture = file_get_contents(base_path('tests/Fixtures/scrapeme-listing.html'));
    Http::fake(['shop.test/*' => Http::response($fixture)]);

    runScrapeJob();

    expect(Product::count())->toBe(3);
    expect(Product::where('url', 'https://scrapeme.live/shop/Bulbasaur/')->first())
        ->name->toBe('Bulbasaur')
        ->price->toBe('£63.00');
    expect(Product::pluck('url'))->each(
        fn ($url) => $url->toStartWith('https://')
    );
});

it('parses a different store when handed different selectors', function () {
    $books = new ProductFetcher(new ProductSelectors(
        card: 'article.product_pod',
        name: 'h3 a',
        price: 'p.price_color',
    ));

    $products = $books->parse(file_get_contents(base_path('tests/Fixtures/books-listing.html')));

    expect($products)->toHaveCount(2)
        ->and($products[0]['price'])->toBe('£51.77')
        ->and($products[0]['url'])->toBe('catalogue/a-light-in-the-attic_1000/index.html');
});

it('reads its selectors from config rather than hardcoded constants', function () {
    config(['scraper.selectors' => [
        'card' => 'div.item',
        'name' => 'span.n',
        'price' => 'span.p',
        'link' => 'a',
    ]]);
    app()->forgetInstance(ProductSelectors::class);

    $products = app(ProductFetcher::class)->parse(
        '<div class="item"><a href="/x"><span class="n">Configured</span></a><span class="p">£1</span></div>'
    );

    expect($products)->toBe([['name' => 'Configured', 'price' => '£1', 'url' => '/x']]);
});
