<?php

namespace App\Services\Scrapper;

use Illuminate\Support\Facades\Http;

class PageFetcher
{
    public function fetch(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            'Accept-Language' => 'en-US,en;q=0.9',
        ])
        ->timeout(10)
        ->retry(3, 1000)
        ->get($url);
        $response->throw();
        return $response->body();

    }
}
