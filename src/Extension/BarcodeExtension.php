<?php

declare(strict_types=1);

namespace SilverShop\Barcodes\Extension;

use SilverShop\Barcodes\BarcodeGenerator;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\FieldType\DBHTMLText;
use Throwable;

/**
 * Exposes barcode-image template accessors on a SilverShop buyable (applied to {@code SilverShop\Page\Product}
 * and {@code SilverShop\Model\Variation\Variation} via {@code _config/barcodes.yml}, guarded by
 * {@code Only.moduleexists: silvershop/core}).
 *
 * The code itself is read **guarded** from {@code silvershop/product-identity}'s {@code getResolvedGtin()} (which
 * owns the variant→product fallback), so this module never stores or validates codes. When product-identity is
 * absent, or the buyable has no code, every accessor returns empty — nothing is rendered and a packing-slip or
 * PDF template is never broken by a bad code.
 *
 * Inline SVG is returned as an {@link DBHTMLText} so {@code $BarcodeSVG} renders raw (unescaped) in templates and
 * needs no controller or asset file.
 *
 * @extends Extension<\SilverStripe\ORM\DataObject>
 */
class BarcodeExtension extends Extension
{
    /**
     * The buyable's barcode code, read guarded from silvershop/product-identity. Empty when that module is absent
     * or no code is set.
     */
    public function getBarcodeCode(): string
    {
        return trim((string) $this->callOwnerMethod('getResolvedGtin', ''));
    }

    /**
     * The buyable's code as a scannable retail barcode: EAN-13 for a 13-digit code, otherwise Code128 (which
     * encodes any 8/12/14-digit or alphanumeric code). Inline SVG; empty when there is no code.
     */
    public function BarcodeSVG(): DBHTMLText
    {
        return $this->inlineSvg($this->getBarcodeCode(), $this->retailSymbology($this->getBarcodeCode()));
    }

    /**
     * The code as a Code128 barcode (inline SVG) — a universal 1D symbology for any code string.
     */
    public function Code128SVG(): DBHTMLText
    {
        return $this->inlineSvg($this->getBarcodeCode(), 'code128');
    }

    /**
     * The code as a QR code (inline SVG) — for mobile scanning / deep links on labels and slips.
     */
    public function QrCodeSVG(): DBHTMLText
    {
        return $this->inlineSvg($this->getBarcodeCode(), 'qr');
    }

    /**
     * The retail barcode as a {@code data:image/png;base64,…} URI for {@code <img src="$BarcodePNG">}. Empty when
     * there is no code or PNG rendering is unavailable (no ext-gd).
     */
    public function BarcodePNG(): string
    {
        $code = $this->getBarcodeCode();
        if ($code === '') {
            return '';
        }

        try {
            return BarcodeGenerator::singleton()
                ->render($code, $this->retailSymbology($code), BarcodeGenerator::FORMAT_PNG);
        } catch (Throwable) {
            return '';
        }
    }

    private function retailSymbology(string $code): string
    {
        return preg_match('/^\d{13}$/', $code) === 1 ? 'ean13' : 'code128';
    }

    private function inlineSvg(string $code, string $symbology): DBHTMLText
    {
        $markup = '';

        if ($code !== '') {
            try {
                $markup = BarcodeGenerator::singleton()->render($code, $symbology, BarcodeGenerator::FORMAT_SVG);
            } catch (Throwable) {
                // Never let a bad code break the template/PDF — just render nothing.
                $markup = '';
            }
        }

        $field = DBHTMLText::create();
        $field->setValue($markup);

        return $field;
    }

    /**
     * Call a method on the owner when it exists (including extension-provided methods), else return $default. The
     * defensive caller for the soft dependency on silvershop/product-identity, invisible to static analysis.
     */
    private function callOwnerMethod(string $method, mixed $default = null): mixed
    {
        $owner = $this->getOwner();

        if ($owner->hasMethod($method)) {
            return call_user_func_array([$owner, $method], []);
        }

        return $default;
    }
}
