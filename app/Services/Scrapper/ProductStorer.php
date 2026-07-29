<?php

namespace App\Services\Scrapper;

use App\Models\Product;

class ProductStorer
{
    /**
     * Upsert products, keyed on the unique url so a re-scrape refreshes prices.
     *
     * @param  array<int, array{name: string, price: string, url: string}>  $items
     */
    public function store(array $items): void
    {
        foreach ($items as $item) {
            Product::updateOrCreate(
                ['url' => $item['url']],
                ['name' => $item['name'], 'price' => $item['price']]
            );
        }
    }
}
