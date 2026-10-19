<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Tests\Stub;

use SilverShop\Barcodes\Extension\BarcodeExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A buyable-like stub that *has* the silvershop/product-identity contract: it defines {@code getResolvedGtin()},
 * so {@link BarcodeExtension} reads a code from it. Simulates "product-identity present" without depending on
 * silvershop/core in the clean room.
 *
 * @property string $FakeGtin
 */
class IdentifiedBuyableStub extends DataObject implements TestOnly
{
    private static string $table_name = 'BarcodesTest_IdentifiedBuyableStub';

    private static array $db = [
        'FakeGtin' => 'Varchar(14)',
    ];

    private static array $extensions = [
        BarcodeExtension::class,
    ];

    /**
     * Stand-in for silvershop/product-identity's resolved accessor (which owns the variant→product fallback).
     */
    public function getResolvedGtin(): string
    {
        return (string) $this->getField('FakeGtin');
    }
}
