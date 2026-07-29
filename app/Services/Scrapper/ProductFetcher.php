<?php

namespace App\Services\Scrapper;

use Symfony\Component\DomCrawler\Crawler;

class ProductFetcher
{
    public function Parse(string $html)
    {
        $crawler = new Crawler($html);

        return $crawler->filter('.product-card')->each(function (Crawler $node) {
            return [
                'name' => $node->filter('.product-title')->text('default name'),
                'price' => $node->filter('.price')->text('0.00$'),
                'url' => $node->filter('a')->count() ? $node->filter('a')->attr('href') : '#',            ];
        });
    }
}
