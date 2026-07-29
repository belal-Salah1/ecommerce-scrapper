<?php

namespace App\Services\Scrapper;

use App\Models\Product;

class ProductStorer
{
    public function store(array $items){
        foreach ($items as $item) {
            if($item['url'] === '#')continue;
            Product::updateOrCreate(
                ['url' => $item['url']],
                ['name' => $item['name'], 'price' => $item['price']]
            );

            
        }

    }
}
