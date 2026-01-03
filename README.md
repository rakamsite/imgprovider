# Safaei Auto Image Loader

Safaei Auto Image Loader is a production-grade WooCommerce plugin that automatically finds and sets product images using Google Programmable Search Engine (Custom Search JSON API) based on a hidden reference code stored in product meta.

## Installation

1. Upload the plugin folder to `wp-content/plugins/safaei-auto-image-loader` or install the ZIP from the WordPress Plugins screen.
2. Activate **Safaei Auto Image Loader**.
3. Go to **WooCommerce > Safaei Image Loader** and configure your Google API Key and CSE CX.

> **Note:** On first activation, the settings are prefilled with test values. Replace them with your own credentials before production use.

## Configuration

Settings are stored in `wp_options` and include:

- Enable/disable the plugin.
- Google API Key and CSE CX.
- Query template and fallback queries.
- Batch size, retries, image size thresholds, and optional allowed domains.
- Gallery controls and cron interval.

All settings are available in **WooCommerce > Safaei Image Loader**.

## Usage

### Per-product

- Open a product in the admin.
- Use the **Safaei Image Loader** metabox to run a search and choose a candidate image.
- Click **Retry** to enqueue the product for background processing.

### Product list bulk actions

- Go to **Products**.
- Use the **Safaei: Find Images** bulk action to enqueue multiple products.
- Use the row action **Find Image** to enqueue a single product.

### Cron processing

The plugin uses WP-Cron to process jobs in batches. The schedule interval is configurable (default 5 minutes). Jobs are stored in a custom table for reliability:

- `wp_safaei_img_jobs`

An optional candidates table is also created:

- `wp_safaei_img_candidates`

## Hidden Reference Code

The hidden reference code is stored in product meta key `_safaei_refcode`. It is used for image searching and never rendered on the frontend. It can be viewed in the admin-only metabox.

## Logging

Logs are written via `wc_get_logger()` with source `safaei_image_loader` for enqueue events, API errors, selected candidates, download errors, and job results.

## Security

- Capability checks use `manage_woocommerce`.
- Nonces are required on all saves and AJAX requests.
- No frontend rendering or shortcodes are added.
