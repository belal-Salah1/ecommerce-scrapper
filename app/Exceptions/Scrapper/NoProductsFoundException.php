<?php

namespace App\Exceptions\Scrapper;

use Exception;

/**
 * Thrown when a fetched page yields no usable products.
 *
 * This almost always means the CSS selectors no longer match the target site's
 * markup rather than that the page is legitimately empty, so it is treated as a
 * permanent failure: `ScrapeProductPage` fails the job instead of retrying.
 */
class NoProductsFoundException extends Exception
{
    public static function noCardsMatched(string $selector): self
    {
        return new self(
            "No elements matched the product selector '{$selector}'. The page markup likely changed, "
            .'the response was not a product listing, or the products are rendered client-side (try JsPageFetcher).'
        );
    }

    public static function allCardsUnusable(int $cardCount, string $selector): self
    {
        return new self(
            "Matched {$cardCount} element(s) for '{$selector}', but none yielded a name, price, and link. "
            .'The container selector still matches while the inner field selectors do not.'
        );
    }
}
