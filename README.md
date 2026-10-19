# SilverShop Barcodes

[![CI](https://github.com/silvershop/silvershop-barcodes/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop/silvershop-barcodes/actions/workflows/ci.yml)

Barcode **image generation** for [SilverShop](https://github.com/silvershop/silvershop) on Silverstripe CMS 6.
Render **EAN-13**, **Code128** and **QR** codes to inline **SVG** (the default) or a **PNG data-URI** — from any
code string, or, guarded, from the GTIN that [`silvershop/product-identity`](https://github.com/silvershop/silvershop-product-identity)
stores on your products. For packing slips, invoices, shipping labels, PDFs and POS / scanning.

This module is **generation only**: it never stores or validates codes — that is `silvershop/product-identity`'s
job. Because of that it is also useful **standalone**: hand the `BarcodeGenerator` service any string and get a
barcode back, with or without SilverShop installed.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6.0, `silverstripe/cms` ^6.0
- `picqer/php-barcode-generator` ^3 (EAN-13 / Code128), `endroid/qr-code` ^5 (QR)
- `ext-gd` — only for PNG output; SVG needs no extension

Optional (soft dependency): `silvershop/product-identity` — auto-exposes a product's GTIN as a barcode on
`Product` and `Variation`.

## Installation

```bash
composer require silvershop/barcodes
```

Then run `dev/build?flush=all`.

## The service

`SilverShop\Barcodes\BarcodeGenerator` is a stateless, injectable service:

```php
use SilverShop\Barcodes\BarcodeGenerator;

$generator = BarcodeGenerator::singleton();

// render(string $code, ?string $symbology = 'ean13', ?string $format = 'svg', array $opts = []): string
$svg     = $generator->render('4006381333931', 'ean13');            // inline <svg> markup
$code128 = $generator->render('ORDER-2026-000123', 'code128');      // any string
$qrSvg   = $generator->render('https://shop.example/p/42', 'qr');   // QR as SVG
$pngUri  = $generator->render('4006381333931', 'ean13', 'png');     // data:image/png;base64,…
```

Supported symbologies: `ean13`, `code128`, `qr`. Per-call `$opts`: `scale`, `height` (1D); `size`, `margin`
(QR); `foreground`, `background` (hex colours).

### Configuration

```yaml
SilverShop\Barcodes\BarcodeGenerator:
  default_symbology: ean13
  default_format: svg
  bar_width_factor: 2
  bar_height: 50
  qr_size: 220
  qr_margin: 10
  foreground_color: '#000000'
  background_color: '#ffffff'
  # Add more picqer linear symbologies without code:
  symbologies:
    ean8:
      kind: linear
      type: EAN8
      numeric: true
      lengths: [7, 8]
```

### Errors

`render()` throws `UnsupportedSymbologyException` for an unknown symbology token, and `BarcodeGenerationException`
when a code cannot be *drawn* as the requested symbology (empty code, a non-numeric or wrong-length EAN-13, an
unknown format, or a failure inside the underlying library). This is a rendering guard, not GS1 validation — it
simply refuses to emit a misleading barcode (e.g. picqer would otherwise silently zero-pad a too-short EAN-13).

## Template accessors (with `silvershop/product-identity`)

When `silvershop/product-identity` is installed, `Product` and `Variation` gain barcode accessors that read the
resolved GTIN (variant → product fallback included) **guarded** — so nothing breaks when a code or the module is
absent:

```html
<% if $BarcodeSVG %>$BarcodeSVG<% end_if %>   <%-- EAN-13 for a 13-digit code, else Code128 --%>
$Code128SVG                                    <%-- always Code128 --%>
$QrCodeSVG                                     <%-- QR --%>
<img src="$BarcodePNG" alt="barcode">          <%-- PNG data-URI --%>
```

Inline SVG embeds directly into packing-slip / invoice / label templates and PDFs with no controller and no asset
file. When the buyable has no code (or product-identity is not installed) every accessor is empty.

The accessor is applied only when `silvershop/core` is present; the `BarcodeGenerator` service works regardless.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
