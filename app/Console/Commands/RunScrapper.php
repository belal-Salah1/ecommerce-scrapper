<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeProductPage;
use Illuminate\Console\Command;

class RunScraper extends Command
{
    protected $signature   = 'scraper:run {url}';
    protected $description = 'Dispatches a scraping job for a given URL';

    public function handle(): void
    {
        $url = $this->argument('url');
        $this->info("Dispatching scraper job for: {$url}");
        ScrapeProductPage::dispatch($url);
        $this->info('Job successfully pushed to the queue!');
    }
}