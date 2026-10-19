<?php

declare(strict_types=1);

namespace SilverShop\Barcodes;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Picqer\Barcode\Exceptions\BarcodeException;
use SilverShop\Barcodes\Exception\BarcodeGenerationException;
use SilverShop\Barcodes\Exception\UnsupportedSymbologyException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Stateless service that renders a code string as a barcode image.
 *
 * It wraps {@code picqer/php-barcode-generator} for 1D symbologies (EAN-13, Code128) and {@code endroid/qr-code}
 * for QR, behind one {@see self::render()} entry point that returns inline SVG markup (the default — PDF, packing
 * slip and template friendly) or a {@code data:image/png;base64,…} URI. The supported symbologies live in a
 * {@code symbologies} config registry so more picqer linear types can be added without code.
 *
 * It is deliberately generation-only: it does not store or GS1-validate codes (that is
 * {@code silvershop/product-identity}'s job), so it works standalone on any code string. It only refuses input it
 * cannot *draw* as the requested symbology — e.g. a non-numeric or wrong-length EAN-13 — rather than silently
 * emitting a misleading barcode.
 */
class BarcodeGenerator
{
    use Injectable;
    use Configurable;

    public const FORMAT_SVG = 'svg';

    public const FORMAT_PNG = 'png';

    public const KIND_LINEAR = 'linear';

    public const KIND_QR = 'qr';

    private const PNG_DATA_URI_PREFIX = 'data:image/png;base64,';

    /**
     * The symbology used when {@see self::render()} is called without one.
     */
    private static string $default_symbology = 'ean13';

    /**
     * The output format used when {@see self::render()} is called without one (self::FORMAT_SVG or FORMAT_PNG).
     */
    private static string $default_format = self::FORMAT_SVG;

    /**
     * 1D (linear) minimum bar width — picqer's {@code widthFactor}.
     */
    private static int $bar_width_factor = 2;

    /**
     * 1D (linear) bar height, in SVG user units / PNG pixels.
     */
    private static int $bar_height = 50;

    /**
     * QR edge length in pixels.
     */
    private static int $qr_size = 220;

    /**
     * QR quiet-zone (margin) in pixels.
     */
    private static int $qr_margin = 10;

    /**
     * Bar/module colour as a hex string (SVG and PNG).
     */
    private static string $foreground_color = '#000000';

    /**
     * Background colour as a hex string. Applies to QR; picqer SVG output is transparent regardless.
     */
    private static string $background_color = '#ffffff';

    /**
     * Symbology registry: token => definition. A linear entry carries the picqer {@code type} and, for the
     * fixed-length numeric symbologies, a {@code numeric} flag plus the accepted {@code lengths} (used as a
     * render-time guard so bad input is rejected instead of silently zero-padded). Extend in YAML, e.g.:
     *
     * <code>
     * SilverShop\Barcodes\BarcodeGenerator:
     *   symbologies:
     *     ean8:
     *       kind: linear
     *       type: EAN8
     *       numeric: true
     *       lengths: [7, 8]
     * </code>
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $symbologies = [
        'ean13' => [
            'kind' => self::KIND_LINEAR,
            'type' => BarcodeGeneratorSVG::TYPE_EAN_13,
            'numeric' => true,
            'lengths' => [12, 13],
        ],
        'code128' => [
            'kind' => self::KIND_LINEAR,
            'type' => BarcodeGeneratorSVG::TYPE_CODE_128,
        ],
        'qr' => [
            'kind' => self::KIND_QR,
        ],
    ];

    /**
     * Render $code as a barcode.
     *
     * @param string $code the code to encode (e.g. a GTIN, an order reference, a URL)
     * @param string|null $symbology one of {@see self::supportedSymbologies()}; the configured default when null/empty
     * @param string|null $format self::FORMAT_SVG (default) or self::FORMAT_PNG
     * @param array<string, mixed> $opts per-call overrides: scale, height (1D); size, margin (QR); foreground,
     *                                    background (hex)
     * @return string inline SVG markup, or a {@code data:image/png;base64,…} URI
     *
     * @throws UnsupportedSymbologyException when $symbology is not registered
     * @throws BarcodeGenerationException when $code/$format cannot be rendered as $symbology
     */
    public function render(string $code, ?string $symbology = null, ?string $format = null, array $opts = []): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new BarcodeGenerationException('Cannot render an empty code.');
        }

        $symbology = $this->resolveOrDefault($symbology, 'default_symbology');
        $format = $this->resolveOrDefault($format, 'default_format');

        if ($format !== self::FORMAT_SVG && $format !== self::FORMAT_PNG) {
            throw new BarcodeGenerationException(sprintf('Unknown format "%s"; expected "svg" or "png".', $format));
        }

        $definition = $this->symbologyDefinition($symbology);

        return ($definition['kind'] ?? self::KIND_LINEAR) === self::KIND_QR
            ? $this->renderQr($code, $format, $opts)
            : $this->renderLinear($code, $definition, $format, $opts);
    }

    /**
     * The registered symbology tokens, in registry order (e.g. ['ean13', 'code128', 'qr']).
     *
     * @return array<int, string>
     */
    public function supportedSymbologies(): array
    {
        return array_keys((array) static::config()->get('symbologies'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnsupportedSymbologyException
     */
    private function symbologyDefinition(string $symbology): array
    {
        $registry = (array) static::config()->get('symbologies');
        $definition = $registry[$symbology] ?? null;

        if (!is_array($definition)) {
            throw new UnsupportedSymbologyException(sprintf(
                'Unsupported symbology "%s"; known symbologies are: %s.',
                $symbology,
                implode(', ', array_keys($registry)) ?: '(none configured)'
            ));
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $opts
     */
    private function renderLinear(string $code, array $definition, string $format, array $opts): string
    {
        $type = (string) ($definition['type'] ?? '');
        if ($type === '') {
            throw new UnsupportedSymbologyException('Linear symbology definition is missing its picqer "type".');
        }

        $this->guardLinearInput($code, $type, $definition);

        $widthFactor = max(1, $this->intOpt($opts, 'scale', 'bar_width_factor'));
        $height = max(1, $this->intOpt($opts, 'height', 'bar_height'));
        $foreground = $this->strOpt($opts, 'foreground', 'foreground_color');

        try {
            if ($format === self::FORMAT_PNG) {
                $png = (new BarcodeGeneratorPNG())
                    ->getBarcode($code, $type, $widthFactor, $height, $this->hexToRgb($foreground));

                return self::PNG_DATA_URI_PREFIX . base64_encode($png);
            }

            $svg = (new BarcodeGeneratorSVG())
                ->getBarcode($code, $type, (float) $widthFactor, (float) $height, $foreground);

            return $this->stripSvgProlog($svg);
        } catch (BarcodeException $e) {
            throw new BarcodeGenerationException(
                sprintf('Cannot render "%s" as %s: %s', $code, $type, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Reject input that cannot be *drawn* as a fixed-length numeric symbology (EAN/UPC). picqer would otherwise
     * silently zero-pad a too-short numeric code into a different, misleading barcode. This is a renderability
     * guard, not GS1 validation (the check digit is picqer's own concern).
     *
     * @param array<string, mixed> $definition
     *
     * @throws BarcodeGenerationException
     */
    private function guardLinearInput(string $code, string $type, array $definition): void
    {
        if (empty($definition['numeric'])) {
            return;
        }

        $lengths = array_map('intval', (array) ($definition['lengths'] ?? []));

        if (!ctype_digit($code) || ($lengths !== [] && !in_array(strlen($code), $lengths, true))) {
            throw new BarcodeGenerationException(sprintf(
                'Code "%s" cannot be rendered as %s (expected %s digits).',
                $code,
                $type,
                $lengths === [] ? 'numeric' : implode(' or ', array_map('strval', $lengths))
            ));
        }
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function renderQr(string $code, string $format, array $opts): string
    {
        $size = max(1, $this->intOpt($opts, 'size', 'qr_size'));
        $margin = max(0, $this->intOpt($opts, 'margin', 'qr_margin'));
        $foreground = $this->strOpt($opts, 'foreground', 'foreground_color');
        $background = $this->strOpt($opts, 'background', 'background_color');

        try {
            $qrCode = QrCode::create($code)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::Low)
                ->setSize($size)
                ->setMargin($margin)
                ->setForegroundColor($this->hexToColor($foreground))
                ->setBackgroundColor($this->hexToColor($background));

            if ($format === self::FORMAT_PNG) {
                return (new PngWriter())->write($qrCode)->getDataUri();
            }

            return $this->stripSvgProlog((new SvgWriter())->write($qrCode)->getString());
        } catch (Throwable $e) {
            throw new BarcodeGenerationException(
                sprintf('Cannot render "%s" as a QR code: %s', $code, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Strip the XML prolog and DOCTYPE the barcode libraries emit, so the SVG embeds cleanly inside HTML/PDF.
     */
    private function stripSvgProlog(string $svg): string
    {
        return trim((string) preg_replace('/^\s*<\?xml[^>]*\?>\s*(<!DOCTYPE[^>]*>)?\s*/i', '', $svg));
    }

    private function hexToColor(string $hex): Color
    {
        [$red, $green, $blue] = $this->hexToRgb($hex);

        return new Color($red, $green, $blue);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function intOpt(array $opts, string $optKey, string $configKey): int
    {
        return (int) ($opts[$optKey] ?? static::config()->get($configKey));
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function strOpt(array $opts, string $optKey, string $configKey): string
    {
        return (string) ($opts[$optKey] ?? static::config()->get($configKey));
    }

    private function configString(string $configKey): string
    {
        return (string) static::config()->get($configKey);
    }

    /**
     * A caller-supplied symbology/format, lower-cased, falling back to the named config default when empty/null.
     */
    private function resolveOrDefault(?string $value, string $configKey): string
    {
        $value = trim((string) $value);

        return strtolower($value !== '' ? $value : $this->configString($configKey));
    }
}
