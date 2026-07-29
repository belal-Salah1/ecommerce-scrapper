<?php

namespace App\Providers;

use App\Services\Scrapper\ProductSelectors;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            ProductSelectors::class,
            fn () => ProductSelectors::fromArray(config('scraper.selectors')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'scraper',
            fn () => Limit::perMinute(config('scraper.requests_per_minute')),
        );
    }
}
