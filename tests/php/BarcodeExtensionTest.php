<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Tests;

use SilverShop\Barcodes\Extension\BarcodeExtension;
use SilverShop\Barcodes\Tests\Stub\IdentifiedBuyableStub;
use SilverShop\Barcodes\Tests\Stub\PlainBuyableStub;
use SilverStripe\Dev\SapphireTest;

/**
 * Coverage for {@link BarcodeExtension}'s guarded accessors, exercised BOTH ways against TestOnly buyables:
 * with the silvershop/product-identity contract present (a code resolves and a barcode renders) and absent (no
 * code, every accessor empty). Uses stubs rather than a real Product so the clean room needs neither
 * silvershop/core nor silvershop/product-identity.
 */
class BarcodeExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        IdentifiedBuyableStub::class,
        PlainBuyableStub::class,
    ];

    public function testExtensionIsAppliedToBuyables(): void
    {
        $this->assertTrue(IdentifiedBuyableStub::singleton()->hasExtension(BarcodeExtension::class));
        $this->assertTrue(PlainBuyableStub::singleton()->hasExtension(BarcodeExtension::class));
    }

    public function testCodeResolvesWhenProductIdentityPresent(): void
    {
        $buyable = IdentifiedBuyableStub::create(['FakeGtin' => '4006381333931']);
        $buyable->write();

        $this->assertSame('4006381333931', $buyable->getBarcodeCode());
    }

    public function testRendersAllAccessorsWhenProductIdentityPresent(): void
    {
        $buyable = IdentifiedBuyableStub::create(['FakeGtin' => '4006381333931']);
        $buyable->write();

        $this->assertStringContainsString('<svg', (string) $buyable->BarcodeSVG());
        $this->assertStringContainsString('<svg', (string) $buyable->Code128SVG());
        $this->assertStringContainsString('<svg', (string) $buyable->QrCodeSVG());
        $this->assertStringStartsWith('data:image/png;base64,', $buyable->BarcodePNG());
    }

    public function testBarcodeSvgFallsBackToCode128ForNonEan13Code(): void
    {
        // An 8-digit (EAN-8-length) code is not a 13-digit EAN-13, so the retail accessor uses Code128 and still
        // renders (rather than refusing or emitting a misleading padded EAN-13).
        $buyable = IdentifiedBuyableStub::create(['FakeGtin' => '96385074']);
        $buyable->write();

        $this->assertStringContainsString('<svg', (string) $buyable->BarcodeSVG());
    }

    public function testEverythingEmptyWhenProductIdentityAbsent(): void
    {
        $buyable = PlainBuyableStub::create(['Title' => 'No identity']);
        $buyable->write();

        $this->assertSame('', $buyable->getBarcodeCode());
        $this->assertSame('', (string) $buyable->BarcodeSVG());
        $this->assertSame('', (string) $buyable->Code128SVG());
        $this->assertSame('', (string) $buyable->QrCodeSVG());
        $this->assertSame('', $buyable->BarcodePNG());
    }

    public function testEverythingEmptyWhenNoCodeSet(): void
    {
        $buyable = IdentifiedBuyableStub::create(['FakeGtin' => '']);
        $buyable->write();

        $this->assertSame('', $buyable->getBarcodeCode());
        $this->assertSame('', (string) $buyable->BarcodeSVG());
        $this->assertSame('', (string) $buyable->QrCodeSVG());
        $this->assertSame('', $buyable->BarcodePNG());
    }

    public function testBadCodeDegradesToEmptyNeverThrows(): void
    {
        // A 13-digit code with a wrong GS1 check digit makes picqer throw; the retail accessor picks EAN-13 for a
        // 13-digit code, so this exercises the catch path: it must degrade to empty, never break a template/PDF.
        $buyable = IdentifiedBuyableStub::create(['FakeGtin' => '4006381333930']);
        $buyable->write();

        $this->assertSame('4006381333930', $buyable->getBarcodeCode());
        $this->assertSame('', (string) $buyable->BarcodeSVG());
    }
}
