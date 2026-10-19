<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Tests\Stub;

use SilverShop\Barcodes\Extension\BarcodeExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A buyable-like stub that does NOT have the silvershop/product-identity contract (no {@code getResolvedGtin()}),
 * so {@link BarcodeExtension}'s guarded read finds no code. Simulates "product-identity absent".
 *
 * @property string $Title
 */
class PlainBuyableStub extends DataObject implements TestOnly
{
    private static string $table_name = 'BarcodesTest_PlainBuyableStub';

    private static array $db = [
        'Title' => 'Varchar',
    ];

    private static array $extensions = [
        BarcodeExtension::class,
    ];
}
