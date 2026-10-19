<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Exception;

use RuntimeException;

/**
 * Thrown when a code cannot be rendered as the requested symbology/format — for example an empty code, a
 * non-numeric or wrong-length EAN-13, an unknown output format, or a failure inside the underlying barcode
 * library (whose exception is attached as the previous throwable).
 *
 * This is a *rendering* failure, not GS1 validation: barcodes never validates or stores the codes themselves —
 * that is {@code silvershop/product-identity}'s job. It only reports that it cannot draw the given input.
 */
class BarcodeGenerationException extends RuntimeException
{
}
