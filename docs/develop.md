# Extend Dexo

Install your changes as a separate WordPress plugin so you can upgrade Dexo independently. Dexo’s hooks below carry the supported extension contract for 0.1.

| Hook | Parameters |
| --- | --- |
| Filter `dexo_sections` | An associative array of dashboard sections, keyed by lowercase slug. Each section defines `label`, `capability`, `render($id, $is_print)` and optionally `submit($id, $form)`. Content-style sections can also provide `create()`, `create_label`, `record_label`, and `group`. Use `new=1` in the section URL to open its creation form. |
| Filter `dexo_tracking_url` | The URL string, the stored tracking code, and its `WC_Order`. The panel accepts HTTP and HTTPS links. |
| Actions `dexo_order_updated`, `dexo_product_updated` | The WooCommerce object, the change name, and the WordPress actor ID. They run after successful commerce writes. |
| Action `dexo_head` | Runs inside the standalone page’s head. Use it to load assets required by your section. |

This example adds a warehouse-facing tab. Use a dedicated capability if the module needs to restrict access beyond the built-in roles.

```php
<?php
/**
 * Plugin Name: Shipping desk for Dexo
 * Requires Plugins: dexo
 */

add_filter( 'dexo_sections', static function ( array $sections ): array {
	$sections['shipping-desk'] = array(
		'label'      => 'Shipping desk',
		'capability' => 'dexo_orders_fulfill',
		'render'     => static function ( int $id, bool $is_print ): void {
			echo '<p>' . esc_html( 'Use your dispatch checklist here.' ) . '</p>';
		},
	);
	return $sections;
} );
```

For writes, point a POST form at `\Dexo\panel_url([ 'tab' => 'your-slug', 'id' => $id ])` and include `\Dexo\edit_form_nonce('your-slug', $id)`. Dexo checks the section capability and nonce before calling `submit`. Your handler still owns operation-specific permissions, record scope, validated input, safe output, and concurrent updates. Return `null` on success, a newly created record ID to redirect to it, or a `WP_Error` with a numeric HTTP status in its error data. Use WooCommerce’s object APIs for order reads and writes so both HPOS and legacy stores stay compatible.

### Build and check

Development needs PHP and Python 3. Build with `python3 scripts/package.py`. The output in `dist/` includes the installable ZIP and a SHA-256 file. The script uses an allowlist so development files and fixtures stay outside the install ZIP.

Use `find . -name '*.php' -exec php -l {} \;` for PHP syntax checks. Update the Persian translation in `languages/dexo-fa_IR.po` and compile it with `msgfmt --check-format -o languages/dexo-fa_IR.mo languages/dexo-fa_IR.po`.

For HTTP checks use a disposable single-site WordPress with `WP_ENVIRONMENT_TYPE` set to `local`, WooCommerce and Dexo active, and a known test administrator. Run:

```sh
wp eval-file tests/fixture.php > /tmp/dexo-fixture.json
python3 tests/smoke.py http://127.0.0.1:8892 /tmp/dexo-fixture.json --admin-password YOUR_LOCAL_TEST_PASSWORD
```

Use a fresh fixture for each test run. Run once with HPOS and once with legacy order storage. Use `--route '/?dexo=1'` for plain permalinks.

If a PHP process is force-killed mid-save and the record remains locked, check that the original request has ended and inspect the record before deleting its matching `dexo_write_lock_orders_ID` or `dexo_write_lock_products_ID` option. Other WooCommerce integrations may write the same records independently. Test these workflows together on staging.

## Content checks

Posts, pages, and attachments use the current user's native WordPress capabilities and record-level `edit_post` checks. The dedicated Dexo commerce roles do not grant content capabilities. Block-based content and custom HTML remain in the WordPress editor to preserve their structure.

To verify these paths on a disposable local site, run `wp eval-file tests/content-fixture.php > /tmp/dexo-content.json`, then `python3 tests/content-smoke.py http://127.0.0.1:8892 /tmp/dexo-content.json`. Additional record-level checks run with `DX_CONTENT_FIXTURE=/tmp/dexo-content.json wp eval-file tests/content-checks.php`. Create new fixture records before repeating the tests.

## Site-management checks

On a disposable local WordPress site, `tests/admin-fixture.php` creates synthetic accounts and records for `tests/admin-smoke.py` and `tests/admin-checks.php`. Set `DX_ADMIN_FIXTURE` to the fixture JSON path when running the PHP checks. Use a fresh fixture before repeating the checks. For the disposable fixture site, copy `tests/admin-support.php` into its `wp-content/mu-plugins/` directory. It captures mail addressed to `@example.test`, registers the fixture menu location and adds paginated privacy callbacks in local environments. Remove this support file when the test run ends. The fixture and support files stay outside the installable Dexo ZIP. The HTTP checks cover settings, accounts, role boundaries, comments, terms, menus and privacy requests. The PHP checks cover navigation cycles, advanced-block preservation and privilege boundaries.

Site Health shows environment details and local WordPress checks. The complete native Site Health screen also runs network-dependent checks.

Profile and user-management endpoints consume only their explicit fields. Keep record-level WordPress capability checks in any extension that changes another user's data. Site settings use an explicit option schema, and privacy processing delegates to the native WordPress AJAX actions and plugin callbacks.
