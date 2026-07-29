<?php

namespace App\Services\Scrapper;

use App\Exceptions\Scrapper\NoProductsFoundException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ProductFetcher
{
    public function __construct(private readonly ProductSelectors $selectors) {}

    /**
     * @return array<int, array{name: string, price: string, url: string}>
     *
     * @throws NoProductsFoundException when the selectors match nothing usable
     */
    public function parse(string $html): array
    {
        $cards = (new Crawler($html))->filter($this->selectors->card);

        if ($cards->count() === 0) {
            throw NoProductsFoundException::noCardsMatched($this->selectors->card);
        }

        $products = collect($cards->each($this->extractProduct(...)))->filter()->values();

        if ($products->isEmpty()) {
            throw NoProductsFoundException::allCardsUnusable($cards->count(), $this->selectors->card);
        }

        $this->warnAboutSkippedCards($cards->count(), $products->count());

        return $products->all();
    }

    /**
     * Null when any required field is missing, so a selector mismatch becomes a
     * skipped card rather than a row of invented data.
     *
     * @return array{name: string, price: string, url: string}|null
     */
    private function extractProduct(Crawler $card): ?array
    {
        $product = [
            'name' => $this->text($card, $this->selectors->name),
            'price' => $this->text($card, $this->selectors->price),
            'url' => $this->attribute($card, $this->selectors->link, 'href'),
        ];

        return in_array('', $product, true) ? null : $product;
    }

    private function text(Crawler $card, string $selector): string
    {
        return trim($card->filter($selector)->text(''));
    }

    private function attribute(Crawler $card, string $selector, string $attribute): string
    {
        $node = $card->filter($selector);

        return $node->count() === 0 ? '' : trim($node->attr($attribute) ?? '');
    }

    private function warnAboutSkippedCards(int $matched, int $usable): void
    {
        if ($matched === $usable) {
            return;
        }

        Log::warning('Scraper skipped malformed product cards.', [
            'selector' => $this->selectors->card,
            'matched' => $matched,
            'skipped' => $matched - $usable,
        ]);
    }
}
