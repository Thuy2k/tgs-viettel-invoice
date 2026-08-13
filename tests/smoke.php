<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', dirname(__DIR__, 2));
define('AUTH_KEY', 'test-auth-key');
define('SECURE_AUTH_KEY', 'test-secure-auth-key');
define('DB_NAME', 'test-db');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

class Smoke_Wpdb
{
    public $base_prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function get_var($query) { return null; }
}
$GLOBALS['wpdb'] = new Smoke_Wpdb();

class TGS_Global_Product_Source
{
    public static function table_exists($table) { return false; }
}

function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function plugin_dir_url($file) { return ''; }
function trailingslashit($value) { return rtrim((string) $value, '/\\') . DIRECTORY_SEPARATOR; }
function add_filter() { return true; }
function add_action() { return true; }
function register_deactivation_hook() { return true; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function get_current_blog_id() { return 1; }
function current_time($format) { return $format === 'Y-m-d' ? '2026-08-13' : '2026-08-13 10:00:00'; }

require dirname(__DIR__) . '/tgs-viettel-invoice.php';

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function call_private(object $object, string $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $arguments);
}

$plugin = TGS_Viettel_Invoice_Plugin::instance();

$invoice_only = ['result' => ['invoiceNo' => 'K26ABC123']];
assert_same('', call_private($plugin, 'extract_transaction_uuid', [$invoice_only]), 'invoiceNo must never be used as transactionUuid');
assert_same('K26ABC123', call_private($plugin, 'extract_invoice_no_from_issue_payload', [$invoice_only]), 'invoiceNo extraction');

$uuid_response = ['result' => ['transactionUuid' => 'uuid-123', 'invoiceNo' => 'K26ABC123']];
assert_same('uuid-123', call_private($plugin, 'extract_transaction_uuid', [$uuid_response]), 'transactionUuid extraction');
assert_same(true, call_private($plugin, 'validate_viettel_response', ['issue', $uuid_response])['success'], 'valid issue response');

$empty_validation = call_private($plugin, 'validate_viettel_response', ['issue', []]);
assert_same(false, $empty_validation['success'], 'empty JSON must fail');
assert_same(true, $empty_validation['ambiguous'], 'empty issue response is ambiguous');

$business_error = call_private($plugin, 'validate_viettel_response', ['issue', ['errorCode' => 'E101', 'message' => 'invalid']]);
assert_same(false, $business_error['success'], 'business error must fail');
assert_same(false, $business_error['ambiguous'], 'explicit business error is not ambiguous');

$cqt_success = call_private($plugin, 'validate_viettel_response', ['send_cqt', ['status' => 'SUCCESS']]);
assert_same(true, $cqt_success['success'], 'CQT positive status');

$secret = 'secret-value';
$encrypted = call_private($plugin, 'encrypt_secret', [$secret]);
if (function_exists('openssl_encrypt')) {
    assert_same(true, strpos($encrypted, 'tgsenc:v1:') === 0, 'secret must be encrypted at rest');
    assert_same($secret, call_private($plugin, 'decrypt_secret', [$encrypted]), 'secret encryption round trip');
}

$flow = new TGS_Viettel_Invoice_Flow_Service();
$filter_result = $flow->filter_and_sort_items_for_tax([
    'sale_ledger_id' => 10,
    'items' => [
        [
            'ledger_item_id' => 1,
            'sku' => 'EXCLUDED-Z',
            'item_name' => 'Excluded gift',
            'tax_percent' => null,
            'is_gift' => true,
            'is_under24_promo_danger' => true,
        ],
        [
            'ledger_item_id' => 2,
            'sku' => 'ZERO-VAT',
            'item_name' => 'Zero VAT item',
            'tax_percent' => 0,
            'quantity' => 1,
            'unit_price_after_discount' => 10000,
            'is_gift' => false,
        ],
    ],
]);
assert_same(true, $filter_result['success'], 'missing tax on an excluded line must not block invoice');
assert_same(1, count($filter_result['payload']['items']), 'excluded line must be removed');
assert_same(0.0, (float) $filter_result['payload']['items'][0]['tax_percent'], 'real 0% tax must be preserved');

$missing_tax_result = $flow->filter_and_sort_items_for_tax([
    'sale_ledger_id' => 11,
    'items' => [[
        'ledger_item_id' => 3,
        'sku' => 'MISSING-VAT',
        'item_name' => 'Missing VAT item',
        'tax_percent' => null,
        'is_gift' => false,
    ]],
]);
assert_same(false, $missing_tax_result['success'], 'missing tax on a sent line must block invoice');

$source = file_get_contents(dirname(__DIR__) . '/tgs-viettel-invoice.php');
assert_same(false, strpos($source, 'wp_ajax_nopriv_tgs_viettel') !== false, 'invoice endpoints must not expose nopriv hooks');
assert_same(false, strpos($source, 'thuy.nguyenvan2000hn@gmail.com') !== false, 'personal fallback email must not exist');

fwrite(STDOUT, "All smoke tests passed.\n");
