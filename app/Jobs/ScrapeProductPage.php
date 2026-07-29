<?php

namespace App\Jobs;

use App\Exceptions\Scrapper\NoProductsFoundException;
use App\Services\Scrapper\PageFetcher;
use App\Services\Scrapper\ProductFetcher;
use App\Services\Scrapper\ProductStorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScrapeProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly string $url) {}

    /**
     * Throttle every worker against the shared `scraper` limiter, and fail
     * immediately on a selector mismatch, which no amount of retrying will fix.
     *
     * The limiter is registered in AppServiceProvider from config/scraper.php.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('scraper'),
            new FailOnException([NoProductsFoundException::class]),
        ];
    }

    public function handle(PageFetcher $fetcher, ProductFetcher $parser, ProductStorer $storer): void
    {
        $html = $fetcher->fetch($this->url);
        $items = $parser->parse($html);
        $storer->store($items);

        Log::info('Scraped product page.', ['url' => $this->url, 'products' => count($items)]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Scrape failed.', [
            'url' => $this->url,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
