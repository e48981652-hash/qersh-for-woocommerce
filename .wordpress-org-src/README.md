# wordpress.org listing images: sources

`.wordpress-org/` holds what the deploy workflow sends to the plugin's SVN `assets/` folder (`ASSETS_DIR` in `.github/workflows/deploy.yml`); this folder holds the editable sources of the banners. Neither folder is in the plugin zip (`.distignore`, and `scripts/build-zip.mjs` packs from its allow-list).

| File in `.wordpress-org/` | Size | Made from |
|---|---|---|
| `icon.svg` | vector | itself: the Qersh mark in white on a #1D4ED8 square |
| `icon-128x128.png`, `icon-256x256.png` | 128×128, 256×256 | `icon.svg` (wordpress.org needs PNG icons next to an SVG one) |
| `banner-772x250.png`, `banner-1544x500.png` | 772×250, 1544×500 | `banner.svg` (English) |
| `banner-772x250-rtl.png`, `banner-1544x500-rtl.png` | 772×250, 1544×500 | `banner-rtl.svg` (Arabic; wordpress.org shows `-rtl` banners on right-to-left locales) |
| `screenshot-1.png` … `screenshot-6.png` | as captured | the real plugin on the local test store; each matches its caption under `== Screenshots ==` in `readme.txt` |

Naming and limits are those of developer.wordpress.org/plugins/wordpress-org/plugin-assets/ (checked 2026-10-18): lowercase names, icons up to 1 MB, banners up to 4 MB, screenshots up to 10 MB; the 1544×500 banner is only an addition to the 772×250 one.

## Rendering the icon and the banners

The brand renderer of the Qersh repository (`marketing/render/render.mjs`: resvg with the local IBM Plex fonts) turns each SVG into PNG with the real fonts:

```sh
R="<Qersh repository>/marketing/render"
cd "$R"
node render.mjs --one <this repo>/.wordpress-org/icon.svg            <this repo>/.wordpress-org/icon-128x128.png 128
node render.mjs --one <this repo>/.wordpress-org/icon.svg            <this repo>/.wordpress-org/icon-256x256.png 256
node render.mjs --one <this repo>/.wordpress-org-src/banner.svg      <this repo>/.wordpress-org/banner-772x250.png 772
node render.mjs --one <this repo>/.wordpress-org-src/banner.svg      <this repo>/.wordpress-org/banner-1544x500.png 1544
node render.mjs --one <this repo>/.wordpress-org-src/banner-rtl.svg  <this repo>/.wordpress-org/banner-772x250-rtl.png 772
node render.mjs --one <this repo>/.wordpress-org-src/banner-rtl.svg  <this repo>/.wordpress-org/banner-1544x500-rtl.png 1544
```

Arabic lines follow the renderer's direction convention (resvg has no `direction="rtl"`): `text-anchor="end"` with the right edge at `x`, each Arabic line inside U+2067 … U+2069, and a masked number inside U+200E. The rules the banners keep: never call Qersh a payment gateway or say it holds or moves money; no currency sign and no amount; Latin digits; a payer number only in the app's masked form (`0111***3445`); the mark never flipped, recoloured or given a gradient.

## Retaking the screenshots

They come from the local test store (`tests/local/README.md`), on a copy of its database served as `https://shop.example.com` through a local TLS proxy, with invented products, customers, numbers and transaction ids, in Microsoft Edge headless at 1280 px (shop) and 1440 px (admin) wide, cropped to the part the caption names. The shop runs Twenty Twenty-Five.

- **The copy behind the proxy:** `php -S` serves the copy (`QERSH_DB_PATH`, `QERSH_BASE=https://shop.example.com`, `QERSH_HOME_OVERRIDE=1`) through a small router of its own that marks a request HTTPS when the proxy's `X-Forwarded-Proto` says so, then hands it to `tests/local/router.php`. Without it WordPress writes `http://` stylesheet and script addresses, which the browser cannot load through the proxy, and the pages come out unstyled.
- **Orders for 2 and 3:** placed through the Store API from the browser tab itself, then that tab opens the order-received address the checkout returns. Take the screenshot within ten minutes: after that WooCommerce asks a guest to confirm the order's email address instead of showing the page.

1. Checkout block with the payer's wallet number field (two products, a filled billing form).
2. The order-received page's "Your transfer" box for an order waiting for its transfer: the box and 24 px around it (1228 px wide; the height follows the box).
3. The same page's "After you transfer" section, with a whole transfer message pasted (the field still focused) and the id the page will send: 920 px wide from 22 px before the section's text, from 18 px above its top rule to 20 px below the status line.
4. Qersh → Wallets: the wallet card with the store URL, the developer's link without a key and its QR code (never the link with the connection key).
5. Qersh → Transfers → "Needs a decision": a transfer whose payer number arrived masked and one above the phone's auto-confirm limit, each with "Attach to order #…".
6. An order paid by a matched transfer: the order screen with the Qersh box under "Order actions".

A screenshot is retaken whenever the screen it shows changes. Every number, name, address and transaction id in them is invented; nothing is masked that the plugin itself does not mask.
