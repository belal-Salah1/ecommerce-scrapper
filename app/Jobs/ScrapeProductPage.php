<?php

namespace App\Jobs;

use App\Services\Scrapper\PageFetcher;
use App\Services\Scrapper\ProductFetcher;
use App\Services\Scrapper\ProductStorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class ScrapeProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private readonly string $url) {}

    /**
     * Enforce rate limiting: max 10 requests per minute across all workers.
     *
     * The `scraper` limiter is registered in AppServiceProvider.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RateLimited('scraper')];
    }

    public function handle(PageFetcher $fetcher, ProductFetcher $parser, ProductStorer $storer): void
    {
        $html = $fetcher->fetch($this->url);
        $items = $parser->parse($html);
        $storer->store($items);
    }
}
