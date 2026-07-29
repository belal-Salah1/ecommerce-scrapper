<?php

namespace App\Services\Scrapper;

use Symfony\Component\DomCrawler\Crawler;

class ProductFetcher
{
    /**
     * @return array<int, array{name: string, price: string, url: string}>
     */
    public function parse(string $html): array
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
