# E-commerce Scraper

A Laravel 13 service that scrapes product listings (name, price, URL) from e-commerce pages and upserts them into a local database. Scraping runs on the queue, one job per page, so it can be rate-limited and retried independently of the caller.

## Requirements

- PHP 8.3+
- Composer
- Node 18+ (for the Vite asset pipeline)
- Chromium/Puppeteer — **only** if you use the JavaScript renderer (`JsPageFetcher`). See [Scraping JavaScript pages](#scraping-javascript-pages).

## Setup

```bash
composer setup
```

That runs `composer install`, copies `.env.example` → `.env`, generates the app key, migrates, then `npm install && npm run build`.

Storage is SQLite at `database/database.sqlite`, and the queue, cache, and session drivers all point at the `database` connection.

Scraper settings live in `config/scraper.php` — the CSS selectors and the request throttle. Every value has a working default, so no environment variables are required; each can be overridden with a `SCRAPER_*` variable (commented out in `.env.example`).

## Running

Start everything (HTTP server, queue worker, log tailer, Vite) with:

```bash
composer dev
```

The queue worker matters: scrape jobs are pushed to the `database` queue and do nothing until a worker picks them up.

Then dispatch a scrape for a listing page:

```bash
php artisan scraper:run "https://scrapeme.live/shop/"
```

That URL works out of the box — the default selectors target it (see [Retargeting](#retargeting-a-different-site)). A successful run stores 16 products.

The command only enqueues the job and returns — **it never prints products**, even on a successful scrape, so a working run and a broken one look identical in the terminal. Watch the `logs` pane of `composer dev` (or `php artisan pail`) for what the worker actually does, and check the `failed_jobs` table if a page never lands.

Inspect what was stored:

```bash
php artisan tinker --execute 'App\Models\Product::latest()->take(10)->get(["name","price","url"])->each(fn ($p) => print("$p->name | $p->price | $p->url\n"));'
```

## How it works

```
scraper:run <url>
  └─ dispatch ScrapeProductPage($url)          app/Jobs/ScrapeProductPage.php
       ├─ PageFetcher::fetch($url): string     app/Services/Scrapper/PageFetcher.php
       ├─ ProductFetcher::parse($html): array  app/Services/Scrapper/ProductFetcher.php
       └─ ProductStorer::store($items): void   app/Services/Scrapper/ProductStorer.php
```

The job is the only orchestrator; each service does one thing and is resolved out of the container, so any of them can be swapped or faked in a test.

**`PageFetcher`** — plain HTTP via the `Http` facade (Guzzle underneath). Sends a desktop `User-Agent` and `Accept-Language`, times out at 10s, retries 3× with a 1s delay, and calls `throw()` so a 4xx/5xx surfaces as an exception and the job retries or fails.

**`ProductFetcher`** — parses with `symfony/dom-crawler`. Because `symfony/css-selector` is installed, `filter()` accepts CSS selectors instead of XPath. It maps every card in the document to `['name', 'price', 'url']` and trims each field. Its selectors arrive as an injected `ProductSelectors` (see [Retargeting](#retargeting-a-different-site)), so the class holds no site-specific knowledge.

It never invents data. A card missing any of name, price, or link is skipped (and the count logged as a warning), and it throws `NoProductsFoundException` when either

- nothing matched `.product-card` at all, or
- cards matched but not one produced a full name + price + link.

Both cases mean the selectors are wrong, which is why they're loud: a silent empty result is indistinguishable from a successful scrape of an empty page.

**`ProductStorer`** — `updateOrCreate` keyed on `url`, which is `unique()` on the `products` table. Re-scraping the same page therefore refreshes prices instead of duplicating rows.

**`ScrapeProductPage`** — `$tries = 3` with `$backoff = 30`, and two queue middlewares:

- `RateLimited('scraper')` caps the whole scraper at `scraper.requests_per_minute` (default 10) across every worker. The named limiter is registered in `AppServiceProvider::boot()`; change the rate in `config/scraper.php`, not in the job.
- `FailOnException([NoProductsFoundException::class])` fails the job on the first selector mismatch instead of retrying it 3× over 90 seconds. A wrong selector is permanent; retrying only delays the error.

It logs the URL and product count on success, and `failed()` logs the URL and reason on failure, so `php artisan pail` tells you which page broke without digging into `failed_jobs`.

Note that retry budgets stack for transient errors: `PageFetcher` retries 3× on its own *and* the job has `$tries = 3`, so a persistently failing URL can cost up to 9 HTTP requests. Collapse that into one layer if you tighten the throttle.

### Data model

`products` (`database/migrations/2026_07_29_151757_create_products_table.php`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `name` | string | |
| `price` | string | raw scraped text, e.g. `"$19.99"` — not numeric |
| `url` | string | `unique`, the upsert key |
| `created_at` / `updated_at` | timestamps | |

### Retargeting a different site

Selectors are configuration, not code — they live in `config/scraper.php` and reach `ProductFetcher` as an injected `ProductSelectors` value object. The defaults target **https://scrapeme.live/shop/**, a WooCommerce storefront published for scraping practice:

```php
'selectors' => [
    'card' => 'li.product',                            // repeating container
    'name' => '.woocommerce-loop-product__title',      // resolved within each card
    'price' => 'span.price',
    'link' => 'a',
],
```

Retarget by editing that file, or per-environment without touching code:

```bash
SCRAPER_SELECTOR_CARD="article.product_pod" php artisan scraper:run "https://books.toscrape.com/"
```

To scrape two stores in one app, construct the parser directly instead of resolving it from the container — no config change needed:

```php
$books = new ProductFetcher(new ProductSelectors(
    card: 'article.product_pod',
    name: 'h3 a',
    price: 'p.price_color',
));
```

Two other practice sites, with selectors verified against their live markup:

| Site | `CARD` | `NAME` | `PRICE` | Caveat |
| --- | --- | --- | --- | --- |
| [scrapeme.live/shop](https://scrapeme.live/shop/) | `li.product` | `.woocommerce-loop-product__title` | `span.price` | none — the default |
| [books.toscrape.com](https://books.toscrape.com/) | `article.product_pod` | `h3 a` | `p.price_color` | relative hrefs; `h3 a` text is truncated (`"A Light in the ..."`) — the full title is in the `title` attribute |
| [webscraper.io test site](https://webscraper.io/test-sites/e-commerce/allinone) | `.thumbnail` | `a.title` | `h4.price` | relative hrefs; `a.title` text is truncated |

Both alternatives need the relative-href fix below before they're usable, because `url` is the unique upsert key: `catalogue/foo/index.html` from two different hosts would collide into one row. Resolve them against the page URL — pass the base URI into the crawler (`new Crawler($html, $url)`) and read `$node->link()->getUri()`, or use `Symfony\Component\DomCrawler\UriResolver`.

Only scrape sites that permit it. The three above exist specifically for this; check `robots.txt` and the terms for anything else.

### Scraping JavaScript pages

`app/Services/Scrapper/JsPageFetcher.php` renders a page with `spatie/browsershot` (headless Chromium via Puppeteer), waiting for network idle before returning `bodyHtml()`. Use it for storefronts that build their listings client-side, where `PageFetcher` would return an empty shell.

It exposes the same `fetch(string $url): string` signature as `PageFetcher` but is not wired into the job yet — nothing currently chooses between the two fetchers. Browsershot also needs Node, Puppeteer, and a Chromium binary on the host, which `composer setup` does not install.

## Testing

```bash
composer test                                     # config:clear, then the full suite
php artisan test --compact --filter=someTestName   # one test
```

Tests run against `sqlite :memory:` with `QUEUE_CONNECTION=sync`. `RefreshDatabase` is **not** applied globally — `tests/Pest.php` has it commented out — so any test touching the database must opt in per file:

```php
uses(RefreshDatabase::class);
```

`tests/Feature/ScrapeProductPageTest.php` covers the pipeline with `Http::fake()`, so nothing hits the network: fetch → parse → store, the re-scrape upsert, both `NoProductsFoundException` paths, partial-card skipping, whitespace trimming, retry-vs-fail-fast behavior in the queue middleware, and command dispatch via `Queue::fake()`.

The last test feeds `tests/Fixtures/scrapeme-listing.html` — three product cards captured verbatim from the live target — so the real selectors are pinned. If the site's markup changes, that test fails instead of production silently returning nothing. Refresh it with:

```bash
curl -s https://scrapeme.live/shop/ | head -c 100000 > /tmp/shop.html   # then re-extract li.product cards
```

Follow that pattern rather than pointing tests at a live store.

## Code style

```bash
vendor/bin/pint --dirty --format agent
```

Run after touching any PHP file. There is no `pint.json`, so Pint uses the default Laravel preset.

## Project layout

```
app/
  Console/Commands/RunScraper.php      scraper:run — enqueues a page
  Jobs/ScrapeProductPage.php           queued orchestrator
  Models/Product.php
  Exceptions/Scrapper/
    NoProductsFoundException.php       selector mismatch — permanent, no retry
  Services/Scrapper/
    PageFetcher.php                    HTTP fetch
    JsPageFetcher.php                  headless-Chromium fetch (not yet wired up)
    ProductFetcher.php                 HTML → array of products
    ProductSelectors.php               injected selector value object
    ProductStorer.php                  array → database upsert
config/scraper.php                     selectors + request throttle
database/migrations/                   products table + Laravel baseline
tests/Fixtures/                        real markup captured from target sites
```

## Scraping responsibly

Only point this at sites you are permitted to scrape. Check `robots.txt` and the site's terms, keep the request throttle conservative, and prefer an official API or data feed when one exists.
