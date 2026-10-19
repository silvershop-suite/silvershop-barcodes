<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Exception;

use InvalidArgumentException;

/**
 * Thrown when {@link \SilverShop\Barcodes\BarcodeGenerator} is asked for a symbology token that is not in its
 * registry (a programmer error — the set of symbologies is known up front and extended via config).
 */
class UnsupportedSymbologyException extends InvalidArgumentException
{
}
