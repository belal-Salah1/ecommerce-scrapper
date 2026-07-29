<?php

namespace App\Services\Scrapper;

use App\Models\Product;

class ProductStorer
{
    /**
     * @param  array<int, array{name: string, price: string, url: string}>  $items
     */
    public function store(array $items): void
    {
        foreach ($items as $item) {
            if ($item['url'] === '#') {
                continue;
            }
            Product::updateOrCreate(
                ['url' => $item['url']],
                ['name' => $item['name'], 'price' => $item['price']]
            );

        }

    }
}
