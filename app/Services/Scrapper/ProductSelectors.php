<?php

namespace App\Services\Scrapper;

/**
 * CSS selectors describing one store's listing markup.
 *
 * Resolved from config/scraper.php by default, but constructible directly so a
 * test or a second store can be parsed without touching configuration.
 */
final readonly class ProductSelectors
{
    public function __construct(
        public string $card,
        public string $name,
        public string $price,
        public string $link = 'a',
    ) {}

    /**
     * @param  array{card: string, name: string, price: string, link?: string}  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            card: $config['card'],
            name: $config['name'],
            price: $config['price'],
            link: $config['link'] ?? 'a',
        );
    }
}
