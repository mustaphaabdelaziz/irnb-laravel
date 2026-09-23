<?php

namespace App\Exceptions\Equipment;

use InvalidArgumentException;

/**
 * A stock operation the user asked for cannot be performed — issuing more
 * units than exist, returning more than are out, splitting units that are
 * not in hand.
 *
 * Carries a translation key and its parameters rather than a finished
 * English sentence, so the message reaches the user in their own language.
 * Still an InvalidArgumentException so existing catch blocks keep working.
 */
class StockException extends InvalidArgumentException
{
    /**
     * @param  array<string, scalar>  $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $key);
    }

    /** The shape FlashMessages.vue expects for an interpolated message. */
    public function toFlash(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }

    public static function notEnoughAvailable(int $requested, int $available): self
    {
        return new self(
            'flash.stock_not_enough_available',
            ['requested' => $requested, 'available' => $available],
            "Cannot issue {$requested} unit(s): only {$available} available.",
        );
    }

    public static function tooManyToReturn(int $requested, int $outstanding): self
    {
        return new self(
            'flash.stock_too_many_to_return',
            ['requested' => $requested, 'outstanding' => $outstanding],
            "Cannot return {$requested} unit(s): {$outstanding} outstanding.",
        );
    }

    public static function cannotSplit(int $requested, int $available): self
    {
        return new self(
            'flash.stock_cannot_split',
            ['requested' => $requested, 'available' => $available],
            "Cannot split {$requested} units: only {$available} are available in this lot.",
        );
    }

    public static function rentalAlreadyClosed(): self
    {
        return new self('flash.stock_rental_closed', [], 'This rental is already closed.');
    }

    public static function invalidQuantity(): self
    {
        return new self('flash.stock_invalid_quantity', [], 'Quantity must be at least 1.');
    }
}
