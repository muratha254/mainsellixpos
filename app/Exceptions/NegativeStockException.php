<?php

namespace App\Exceptions;

use RuntimeException;

class NegativeStockException extends RuntimeException
{
    public static function forProduct(string $productName, $quantityAfter): self
    {
        return new self("Insufficient stock for {$productName}. Resulting quantity would be {$quantityAfter}.");
    }

    public static function available(string $availableLabel): self
    {
        return new self('Insufficient stock. Available stock: ' . $availableLabel . '.');
    }

    public static function otherBranch(): self
    {
        return new self('This item belongs to another branch. Transfer it before stocking it here.');
    }
}
