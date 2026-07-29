# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state

E-commerce scraper on a Laravel 13 skeleton. There is no HTTP surface for the domain — `routes/web.php` still serves the welcome view, and the only `Controller` is the default. Everything happens through an Artisan command and a queued job.

The pipeline, one class per stage, all resolved from the container:

```
scraper:run <url>                          app/Console/Commands/RunScraper.php
  └─ ScrapeProductPage($url)               app/Jobs/ScrapeProductPage.php
       ├─ PageFetcher::fetch(url): string
       ├─ ProductFetcher::parse(html): array   ['name','price','url'][]
       └─ ProductStorer::store(items): void    updateOrCreate keyed on url
```

Services live in `app/Services/Scrapper/` — note the **two-p `Scrapper`** namespace, which is easy to mistype as `Scraper`. Class names are also inconsistent with their roles (`ProductFetcher` parses; it does not fetch). Verify with `class_exists()` before assuming an import resolves.

The scraping stack chosen by the dependencies:
- **Guzzle** for HTTP — used via Laravel's `Http` facade in `PageFetcher`.
- **`symfony/dom-crawler` + `symfony/css-selector`** for parsing (`new Crawler($html)` then `->filter('css selector')`). The CSS selector package is what makes `filter()` accept CSS instead of XPath.
- **`spatie/browsershot`** for client-rendered pages, in `JsPageFetcher`. Same `fetch(string): string` shape as `PageFetcher` but **not wired into the job** — nothing selects between the two fetchers. Needs a Chromium/Puppeteer binary that `composer setup` does not install, so don't add it to a code path that tests or CI will hit.

**Selectors are configuration, never hardcoded in a class.** They live in `config/scraper.php` (alongside `requests_per_minute`) and reach `ProductFetcher` as an injected `ProductSelectors` readonly value object, bound as a singleton in `AppServiceProvider::register()`. Defaults target **https://scrapeme.live/shop/** (a WooCommerce practice site), so `php artisan scraper:run "https://scrapeme.live/shop/"` works out of the box and stores 16 products. Override per-environment with `SCRAPER_SELECTOR_*`, or construct `new ProductFetcher(new ProductSelectors(...))` directly to parse a second store without touching config. `tests/Fixtures/` holds real markup captured from live targets, so selector rot fails a test instead of silently returning nothing.

Note when overriding selectors in a test: `ProductSelectors` is a singleton, so `config([...])` alone won't take effect — call `app()->forgetInstance(ProductSelectors::class)` after it, or just construct the parser directly.

**Never let a selector mismatch pass silently.** The parser throws `NoProductsFoundException` when nothing matches `CARD`, or when cards match but none yields a full name + price + link, and skips (never placeholder-fills) individual malformed cards. An empty result is indistinguishable from a successful scrape of an empty page, which is why this is loud. `FailOnException` in the job's middleware fails that exception immediately rather than burning 3 retries on a permanent problem — keep transient errors (network, HTTP) retryable and parse errors fatal.

`price` is a **string** column holding raw scraped text like `"£63.00"` — not numeric, so it can't be sorted or compared. `url` is `unique()` because it's the `updateOrCreate` key, and it's stored exactly as the `href` appears in the markup. **Relative hrefs are not resolved**, so sites emitting them (books.toscrape.com, webscraper.io's test site) will collide rows; resolve against the page URL first via `new Crawler($html, $url)` + `$node->link()->getUri()`.

## Commands

```bash
composer setup          # install, .env, key:generate, migrate, npm install, npm run build
composer dev            # serve (:8000) + queue:listen + pail (logs) + vite, concurrently
composer test           # config:clear then php artisan test
php artisan test --compact --filter=someTestName    # single test / filtered run
vendor/bin/pint --dirty --format agent              # required after touching PHP files
npm run build           # or `npm run dev` for the Vite watcher
```

## Infrastructure notes

- **Everything is SQLite** (`database/database.sqlite`), and queue, cache, and session all use the `database` driver. The `jobs`/`job_batches`/`failed_jobs`, `cache`/`cache_locks`, and `sessions` tables already exist in the three baseline migrations. Queued work therefore needs a running worker — `composer dev` provides one.
- **Tests run against `sqlite :memory:`** (`phpunit.xml`), with `QUEUE_CONNECTION=sync`. `RefreshDatabase` is deliberately commented out in `tests/Pest.php`, so any test needing the database must opt in with `uses(RefreshDatabase::class)` in its file, or you enable it globally there.
- Pest 4 with `pest-plugin-laravel`; no `pint.json`, so Pint uses the default Laravel preset.
- **`Product` has no factory.** `database/factories/` holds only `UserFactory`. Generate one before writing a test that needs arbitrary product rows — `tests/Feature/ScrapeProductPageTest.php` gets its rows through the pipeline itself, via `Http::fake()` and a fixture HTML string, so it doesn't need one. Never point a test at a live store.
- `bootstrap/app.php` registers nothing custom, so Artisan commands rely on Laravel's **auto-discovery of `app/Console/Commands`, which derives the class name from the file path**. A filename that doesn't match its class is skipped silently — the command simply won't appear in `php artisan list`. Confirm registration there after adding or renaming a command; a passing `class_exists()` is not proof, because Composer's optimized classmap resolves the class even when PSR-4 wouldn't.
- Queue middleware lives in `Illuminate\Queue\Middleware\*` (`RateLimited`, `ThrottlesExceptions`, `WithoutOverlapping`, `Skip`, …) — there is no `Illuminate\Queue\Throttle`, and `RateLimited` takes the *name* of a limiter, not inline limits. The scraper's `scraper` limiter is registered in `AppServiceProvider::boot()` at 10/minute; change the rate there, not in the job.
- Retry budgets currently stack: `PageFetcher` does `->retry(3, 1000)` *and* the job sets `$tries = 3`, so one URL can produce up to 9 HTTP requests. Keep retries in one layer when touching either.

## Agent tooling in this repo

- Boost's generated rules are appended to this file inside the `<laravel-boost-guidelines>` block below. **Do not hand-edit that block** — `composer post-update-cmd` runs `php artisan boost:update`, which regenerates it. Project-specific guidance belongs in the sections above it.
- Boost MCP tools worth reaching for here: `database-schema` and `database-query` against the SQLite file, `read-log-entries` / `last-error` for what a worker actually did, and `search-docs` for version-matched Laravel 13 docs.
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
