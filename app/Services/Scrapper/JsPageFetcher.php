<?php
namespace App\Services\Scrapper;


use spatie\Browsershot\Browsershot;

class JsPageFetcher
{
    public function fetch(string $url): string
    {
        return Browsershot::url($url)
            ->waitUntilNetworkIdle()
            ->bodyHtml();
    }

    
}
