<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Tests;

use SilverShop\Barcodes\BarcodeGenerator;
use SilverShop\Barcodes\Exception\BarcodeGenerationException;
use SilverShop\Barcodes\Exception\UnsupportedSymbologyException;
use SilverStripe\Dev\SapphireTest;

/**
 * Unit coverage for the stateless {@link BarcodeGenerator}: SVG + PNG output for each shipped symbology (EAN-13,
 * Code128, QR), the defaults, per-call option overrides, and the renderability guards (empty, wrong-length
 * EAN-13, non-numeric EAN-13, unknown symbology, unknown format).
 */
class BarcodeGeneratorTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const VALID_EAN13 = '4006381333931';

    private const PNG_PREFIX = 'data:image/png;base64,';

    private function generator(): BarcodeGenerator
    {
        return BarcodeGenerator::create();
    }

    public function testSupportedSymbologies(): void
    {
        $this->assertSame(['ean13', 'code128', 'qr'], $this->generator()->supportedSymbologies());
    }

    public function testEan13Svg(): void
    {
        $svg = $this->generator()->render(self::VALID_EAN13, 'ean13', 'svg');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg, 'The XML prolog must be stripped for inline use');
        $this->assertStringNotContainsString('<!DOCTYPE', $svg, 'The DOCTYPE must be stripped for inline use');
    }

    public function testEan13AcceptsTwelveDigits(): void
    {
        // picqer computes the 13th (check) digit from 12 digits — a valid renderable length.
        $svg = $this->generator()->render('400638133393', 'ean13', 'svg');
        $this->assertStringContainsString('<svg', $svg);
    }

    public function testCode128SvgEncodesArbitraryString(): void
    {
        $svg = $this->generator()->render('ABC-123/REF', 'code128', 'svg');
        $this->assertStringContainsString('<svg', $svg);
    }

    public function testQrSvg(): void
    {
        $svg = $this->generator()->render('https://example.com/p/42', 'qr', 'svg');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function testDefaultSymbologyAndFormatIsEan13Svg(): void
    {
        $svg = $this->generator()->render(self::VALID_EAN13);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function testPngDataUriForEachSymbology(): void
    {
        $cases = [
            'ean13' => self::VALID_EAN13,
            'code128' => 'ABC-123',
            'qr' => 'hello',
        ];

        foreach ($cases as $symbology => $code) {
            $uri = $this->generator()->render($code, $symbology, 'png');

            $this->assertStringStartsWith(self::PNG_PREFIX, $uri, $symbology);
            $decoded = base64_decode(substr($uri, strlen(self::PNG_PREFIX)), true);
            $this->assertNotFalse($decoded, $symbology);
            $this->assertStringContainsString('PNG', (string) $decoded, $symbology . ' is real PNG data');
        }
    }

    public function testForegroundOptionChangesOutput(): void
    {
        $default = $this->generator()->render(self::VALID_EAN13, 'ean13', 'svg');
        $red = $this->generator()->render(self::VALID_EAN13, 'ean13', 'svg', ['foreground' => '#ff0000']);

        $this->assertStringContainsString('<svg', $red);
        $this->assertNotSame($default, $red, 'A custom foreground colour must change the rendered SVG');
    }

    public function testSizeOptionIsHonouredForQr(): void
    {
        $small = $this->generator()->render('x', 'qr', 'svg', ['size' => 60]);
        $large = $this->generator()->render('x', 'qr', 'svg', ['size' => 400]);

        $this->assertStringContainsString('<svg', $small);
        $this->assertNotSame($small, $large);
    }

    public function testEmptyCodeIsRejected(): void
    {
        $this->expectException(BarcodeGenerationException::class);
        $this->generator()->render('', 'ean13');
    }

    public function testEan13RejectsWrongLength(): void
    {
        // picqer would silently zero-pad "12345" into a misleading EAN-13; the guard rejects it instead.
        $this->expectException(BarcodeGenerationException::class);
        $this->generator()->render('12345', 'ean13');
    }

    public function testEan13RejectsNonNumeric(): void
    {
        $this->expectException(BarcodeGenerationException::class);
        $this->generator()->render('4006381ABCDE', 'ean13');
    }

    public function testUnknownSymbologyThrows(): void
    {
        $this->expectException(UnsupportedSymbologyException::class);
        $this->generator()->render('4006381333931', 'datamatrix');
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(BarcodeGenerationException::class);
        $this->generator()->render(self::VALID_EAN13, 'ean13', 'tiff');
    }

    public function testArbitraryCodeRendersStandalone(): void
    {
        // The module is useful without any product: hand it any string.
        $svg = $this->generator()->render('ORDER-2026-000123', 'code128');
        $this->assertStringContainsString('<svg', $svg);
    }
}
