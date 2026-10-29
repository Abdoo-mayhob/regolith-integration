# Regolith Integration for WooCommerce

Lists a WooCommerce store's catalog on [Regolith](https://regolithapp.com/) and keeps it in sync.
The store pushes; Regolith never pulls from the store.

## Requirements

- WordPress 6.5+, WooCommerce, PHP 8.0+
- A Regolith vendor account with an Integration API key

## Install

Download `regolith-integration.zip` from the [latest release](../../releases/latest) and upload it
in **Plugins → Add New → Upload Plugin**. After activation, the setup wizard opens:

1. Paste the API key.
2. Match each WooCommerce category to a Regolith category, or mark it "Don't list".
3. Pick a category for products that sit in several.
4. Tell the plugin where the store keeps brand, model and other specs.
5. Go live.

Later versions install from the Plugins screen like any other update.

## How it works

- WooCommerce hooks only add products to a queue table. Saving a product never waits on Regolith.
- Action Scheduler (bundled with WooCommerce) sends the queue in batches every minute, and full
  catalog syncs on demand.
- Batches are frozen before sending and retried byte-for-byte, in order, so retries are safe replays.
- Every result is stored per product. Problems show up under **Regolith → Needs attention** in
  plain English, with the raw code kept for support.

The engine's rules and their reasons are written next to the code. Start with
`class-sync-service.php` and `class-sync-db.php`.

## Layout

| File | Job |
|---|---|
| `class-sync-service.php` | Sync engine: builds and sends runs, handles every API answer |
| `class-sync-db.php` | Tables: runs, batches, queue, per-product ledger |
| `class-sync-queue.php` | WooCommerce hooks and scheduling |
| `class-product-serializer.php` | WooCommerce product → Regolith row |
| `class-field-mapper.php` | Where each product detail comes from on this store |
| `class-api.php` | HTTP client for the Regolith API |
| `class-onboarding.php` | Category and product-list snapshots |
| `class-wizard.php`, `class-dashboard.php`, `class-admin.php` | Admin screens |
| `class-messages.php` | Every message a store owner reads |
| `class-updater.php` | Updates from this repository's releases |

## Releasing

Bump the version in `regolith-integration.php` (the `Version:` header and `REGOLITH_PLUGIN_VERSION`,
both `X.Y.Z`), commit, push to `main`. The release workflow tags it, builds the zip and publishes
the release. Release notes come from the commit messages, so write them for humans.

## License

GPL-2.0-or-later. Bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
