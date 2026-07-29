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

Storage is SQLite at `database/database.sqlite`, and the queue, cache, and session drivers all point at the `database` connection. There are no scraper-specific environment variables — `.env.example` is the stock Laravel file.

## Running

Start everything (HTTP server, queue worker, log tailer, Vite) with:

```bash
composer dev
```

The queue worker matters: scrape jobs are pushed to the `database` queue and do nothing until a worker picks them up.

Then dispatch a scrape for a listing page:

```bash
php artisan scraper:run "https://example.com/category/shoes"
```

The command only enqueues the job and returns. Watch the `logs` pane of `composer dev` (or `php artisan pail`) for what the worker actually does, and check the `failed_jobs` table if a page never lands.

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

**`ProductFetcher`** — parses with `symfony/dom-crawler`. Because `symfony/css-selector` is installed, `filter()` accepts CSS selectors instead of XPath. It maps every `.product-card` in the document to `['name', 'price', 'url']`, reading `.product-title`, `.price`, and the first `<a href>`. Each field has a fallback (`'default name'`, `'0.00$'`, `'#'`) so a missing node yields a placeholder rather than an exception.

> These selectors are placeholders. No real store uses `.product-card`/`.product-title`/`.price` verbatim — retarget them per site before pointing this at a live page.

**`ProductStorer`** — `updateOrCreate` keyed on `url`, which is `unique()` on the `products` table. Re-scraping the same page therefore refreshes prices instead of duplicating rows. Rows whose URL fell back to `'#'` are skipped.

**`ScrapeProductPage`** — `$tries = 3` with `$backoff = 30`, plus a `RateLimited('scraper')` queue middleware that caps the whole scraper at 10 requests per minute across every worker. The named `scraper` limiter is registered in `AppServiceProvider::boot()`; change the rate there, not in the job.

Note that retry budgets stack: `PageFetcher` retries 3× on its own *and* the job has `$tries = 3`, so a persistently failing URL can cost up to 9 HTTP requests. Collapse that into one layer if you tighten the throttle.

### Data model

`products` (`database/migrations/2026_07_29_151757_create_products_table.php`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint | |
| `name` | string | |
| `price` | string | raw scraped text, e.g. `"$19.99"` — not numeric |
| `url` | string | `unique`, the upsert key |
| `created_at` / `updated_at` | timestamps | |

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

`tests/Feature/ScrapeProductPageTest.php` covers the pipeline with `Http::fake()` and a fixture HTML string, so nothing hits the network: fetch → parse → store, the re-scrape upsert, the skip for link-less cards, the queue middleware, and command dispatch via `Queue::fake()`. Follow that pattern rather than pointing tests at a live store.

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
  Services/Scrapper/
    PageFetcher.php                    HTTP fetch
    JsPageFetcher.php                  headless-Chromium fetch (not yet wired up)
    ProductFetcher.php                 HTML → array of products
    ProductStorer.php                  array → database upsert
database/migrations/                   products table + Laravel baseline
```

## Scraping responsibly

Only point this at sites you are permitted to scrape. Check `robots.txt` and the site's terms, keep the request throttle conservative, and prefer an official API or data feed when one exists.
