<?php
namespace App\Jobs;

use App\Services\Scraper\PageFetcher;
use App\Services\Scraper\ProductParser;
use App\Services\Scraper\ProductStorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Throttle;

class ScrapeProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(private readonly string $url) {}
    
    public function middleware(): array
    {
        // Enforce rate limiting: max 10 requests per 60 seconds across all workers
        return [new Throttle(key: 'scraper', maxAttempts: 10, decaySeconds: 60)];
    }

    public function handle(PageFetcher $fetcher, ProductParser $parser, ProductStorer $storer): void
    {
        $html  = $fetcher->fetch($this->url);
        $items = $parser->parse($html);
        $storer->store($items);
    }
}