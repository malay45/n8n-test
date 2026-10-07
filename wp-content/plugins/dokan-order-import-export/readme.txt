=== Dokan Order Import Export for WooCommerce ===
Requires at least: 5.9
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.0.0
License: GPLv2 or later

Import and export WooCommerce orders as CSV or JSON, with full support for Dokan / Dokan Pro multi-vendor data.

== Description ==

Admin screen: WooCommerce → Order Import / Export (needs the `manage_woocommerce` capability).

= Export =
* Filters: order date range, statuses, Dokan vendor, and which orders to include:
  * All orders: parent orders plus vendor sub-orders. Use this for migration or backup.
  * Vendor orders only: skips parent orders that were split into sub-orders, so totals are not counted twice in reports.
  * Customer orders only: top-level orders without sub-orders.
* Formats: CSV (opens in Excel, with formula-injection protection) or JSON.
* Bulk action "Export (CSV)" on the WooCommerce orders list.
* Each row is one order. Items, shipping, fees, coupons, taxes, refunds, notes and order meta are stored as JSON in their own columns, so nothing is lost when you import the file again.

= Dokan columns =
`dokan_vendor_id`, `dokan_vendor_email`, `dokan_store_name`, `dokan_has_sub_order`, `dokan_order_total`, `dokan_vendor_earning`, `dokan_admin_commission`. Vendor shipping lines keep their `seller_id` meta. Dokan commission meta on line items is kept too.

= Import =
* Batched with AJAX, so large files do not time out. Parent orders are always imported before their sub-orders.
* Duplicate protection: each imported order stores its original ID. You can skip, update or always create new orders when the same order is imported again.
* Vendors are matched by email first, then by store name, so imports work between sites where the user IDs differ. Customers are matched by email. Products are matched by SKU, then by ID.
* Dokan bookkeeping is rebuilt: `_dokan_vendor_id`, `has_sub_order`, the parent/sub-order link, `dokan_orders` rows and `dokan_vendor_balance` ledger rows. By default the exported vendor earning and admin commission are kept. Untick the option to have Dokan recalculate them with the current commission settings.
* Dokan does not re-split imported orders into new sub-orders.
* By default the import sends no emails, does not change stock, and does not increase sales or coupon-usage counts. Each of these can be turned on.
* Works with both High-Performance Order Storage (HPOS) and the legacy posts storage.

= WP-CLI =
    wp doie export --file=orders.csv [--format=json] [--status=processing,completed] [--from=2026-01-01] [--to=2026-12-31] [--vendor=12] [--scope=all|vendor|parent]
    wp doie import orders.csv [--existing=skip|update|create] [--match-by-id] [--send-emails] [--reduce-stock] [--recalculate] [--no-preserve-earnings] [--batch=50]

= Developer hooks =
* `doie_export_columns` (filter): list of CSV columns.
* `doie_export_query_args` (filter): `wc_get_orders()` arguments for each export page.
* `doie_export_record` (filter): an order record before it is written.
* `doie_import_record` (filter): a record before it is imported.
* `doie_import_vendor_id` / `doie_import_customer_id` (filters): override vendor or customer matching.
* `doie_import_batch_size` (filter): orders per AJAX batch. Default 20.
* `doie_before_import` / `doie_after_import` / `doie_order_imported` (actions).
* The `DOIE_IMPORTING` constant is defined while an import batch runs.

== Notes ==
* Import files are converted into a temporary queue in `wp-content/uploads/doie-jobs/`. The folder is protected with `.htaccess`; on Nginx, deny access to it. Queue files are deleted when the import finishes. Abandoned queue files are deleted after 2 days.
* Dokan Pro refund records (`dokan_refund`) and withdrawals are not part of the export. WooCommerce refunds are exported and re-created.
