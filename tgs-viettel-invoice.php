<?php
/**
 * Plugin Name: TGS Viettel Invoice
 * Plugin URI:  https://thegioisua.vn
 * Description: Tao hoa don nhap/phat hanh tren Viettel VInvoice, tich hop vao TGS Shop Management.
 * Version:     1.0.0
 * Author:      TGS Team
 * Text Domain: tgs-viettel-invoice
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_VIETTEL_INVOICE_VERSION', '1.0.0');
define('TGS_VIETTEL_INVOICE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_VIETTEL_INVOICE_PLUGIN_URL', plugin_dir_url(__FILE__));

class TGS_Viettel_Invoice_Plugin
{
    const OPTION_SETTINGS = 'tgs_viettel_invoice_settings';

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);
        add_action('tgs_shop_system_menu', [$this, 'render_system_menu'], 25, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_tgs_viettel_invoice_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_send_payload', [$this, 'ajax_send_payload']);

        add_action('tgs_sale_completed', [$this, 'handle_sale_completed'], 20, 1);
    }

    public function register_routes($routes)
    {
        $routes['viettel-invoice-create'] = ['Viettel Invoice', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/create-invoice.php'];
        $routes['viettel-invoice-settings'] = ['Viettel Invoice Settings', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/settings.php'];
        $routes['viettel-invoice-guide'] = ['Viettel Invoice Flow', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/flow-guide.php'];

        return $routes;
    }

    public function render_system_menu($current_view)
    {
        $items = [
            'viettel-invoice-create' => ['bx bx-receipt text-primary me-1', 'Viettel Invoice'],
            'viettel-invoice-settings' => ['bx bx-cog text-warning me-1', 'Cau hinh Viettel Invoice'],
            'viettel-invoice-guide' => ['bx bx-book-content text-info me-1', 'Huong dan luong'],
        ];

        foreach ($items as $view => $meta) {
            $active = ($current_view === $view) ? 'active' : '';
            echo '<li class="menu-item ' . esc_attr($active) . '">';
            echo '<a class="menu-link" href="' . esc_url(admin_url('admin.php?page=tgs-shop-management&view=' . $view)) . '">';
            echo '<i class="' . esc_attr($meta[0]) . '"></i><div>' . esc_html($meta[1]) . '</div>';
            echo '</a></li>';
        }
    }

    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'tgs-shop-management') === false) {
            return;
        }

        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if (!in_array($view, ['viettel-invoice-create', 'viettel-invoice-settings', 'viettel-invoice-guide'], true)) {
            return;
        }

        wp_enqueue_script(
            'tgs-viettel-invoice-admin',
            TGS_VIETTEL_INVOICE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            TGS_VIETTEL_INVOICE_VERSION,
            true
        );

        wp_localize_script('tgs-viettel-invoice-admin', 'tgsViettelInvoice', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tgs_viettel_invoice_nonce'),
        ]);
    }

    public static function get_default_settings()
    {
        return [
            'company_name' => '',
            'supplier_tax_code' => '',
            'company_address' => '',
            'company_phone' => '',
            'api_base_url' => 'https://api-vinvoice.viettel.vn/services/einvoiceapplication/api',
            'auth_mode' => 'basic',
            'username' => '',
            'password' => '',
            'access_token' => '',
            'default_template_code' => '',
            'default_invoice_series' => '',
            'default_payment_method' => 'TM/CK',
            'verify_ssl' => 1,
            'auto_enabled' => 0,
            'auto_mode' => 'draft',
        ];
    }

    public static function get_settings()
    {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::get_default_settings(), $stored);
    }

    private function sanitize_settings($data)
    {
        $current = self::get_settings();

        $sanitized = [
            'company_name' => sanitize_text_field($data['company_name'] ?? ''),
            'supplier_tax_code' => sanitize_text_field($data['supplier_tax_code'] ?? ''),
            'company_address' => sanitize_text_field($data['company_address'] ?? ''),
            'company_phone' => sanitize_text_field($data['company_phone'] ?? ''),
            'api_base_url' => esc_url_raw(trim($data['api_base_url'] ?? '')),
            'auth_mode' => in_array(($data['auth_mode'] ?? ''), ['basic', 'token'], true) ? $data['auth_mode'] : 'basic',
            'username' => sanitize_text_field($data['username'] ?? ''),
            'password' => isset($data['password']) ? (string) $data['password'] : '',
            'access_token' => isset($data['access_token']) ? (string) $data['access_token'] : '',
            'default_template_code' => sanitize_text_field($data['default_template_code'] ?? ''),
            'default_invoice_series' => sanitize_text_field($data['default_invoice_series'] ?? ''),
            'default_payment_method' => sanitize_text_field($data['default_payment_method'] ?? ''),
            'verify_ssl' => !empty($data['verify_ssl']) ? 1 : 0,
            'auto_enabled' => !empty($data['auto_enabled']) ? 1 : 0,
            'auto_mode' => (($data['auto_mode'] ?? '') === 'issue') ? 'issue' : 'draft',
        ];

        if ($sanitized['password'] === '********') {
            $sanitized['password'] = $current['password'];
        }

        if ($sanitized['access_token'] === '********') {
            $sanitized['access_token'] = $current['access_token'];
        }

        if (empty($sanitized['api_base_url'])) {
            $sanitized['api_base_url'] = $current['api_base_url'];
        }

        return $sanitized;
    }

    public function ajax_save_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Ban khong co quyen.'], 403);
        }

        $raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Du lieu khong hop le.'], 400);
        }

        $settings = $this->sanitize_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_send_json_success([
            'message' => 'Da luu cau hinh cho shop hien tai.',
        ]);
    }

    public function ajax_export_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Ban khong co quyen.'], 403);
        }

        $settings = self::get_settings();

        wp_send_json_success([
            'settings' => $settings,
            'exported_at' => current_time('mysql'),
            'blog_id' => get_current_blog_id(),
            'site_name' => get_bloginfo('name'),
        ]);
    }

    public function ajax_import_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Ban khong co quyen.'], 403);
        }

        $json_text = isset($_POST['settings_json']) ? wp_unslash($_POST['settings_json']) : '';
        if (empty($json_text)) {
            wp_send_json_error(['message' => 'Ban chua nhap JSON.'], 400);
        }

        $decoded = json_decode($json_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            wp_send_json_error(['message' => 'JSON khong hop le: ' . json_last_error_msg()], 400);
        }

        $payload = $decoded;
        if (isset($decoded['settings']) && is_array($decoded['settings'])) {
            $payload = $decoded['settings'];
        }

        $settings = $this->sanitize_settings($payload);
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_send_json_success(['message' => 'Da import cau hinh thanh cong.']);
    }

    public function ajax_send_payload()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Ban khong co quyen.'], 403);
        }

        $mode = 'draft';
        if (isset($_POST['mode'])) {
            $input_mode = sanitize_text_field(wp_unslash($_POST['mode']));
            if (in_array($input_mode, ['draft', 'issue', 'cancel'], true)) {
                $mode = $input_mode;
            }
        }
        $payload_text = isset($_POST['payload_json']) ? wp_unslash($_POST['payload_json']) : '';

        if (empty($payload_text)) {
            wp_send_json_error(['message' => 'Payload JSON dang rong.'], 400);
        }

        $payload = json_decode($payload_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            wp_send_json_error(['message' => 'Payload JSON khong hop le: ' . json_last_error_msg()], 400);
        }

        $result = $this->submit_invoice_payload($payload, $mode, [
            'created_by' => get_current_user_id(),
            'sale_ledger_id' => isset($_POST['sale_ledger_id']) ? intval($_POST['sale_ledger_id']) : 0,
        ]);

        if (!$result['success']) {
            wp_send_json_error($result, 400);
        }

        wp_send_json_success($result);
    }

    private function submit_invoice_payload($payload, $mode, $context = [])
    {
        $settings = self::get_settings();
        if ($mode === 'cancel' && empty($payload['supplierTaxCode']) && !empty($settings['supplier_tax_code'])) {
            $payload['supplierTaxCode'] = $settings['supplier_tax_code'];
        }

        $validate_error = $this->validate_before_send($settings, $payload, $mode);
        if (!empty($validate_error)) {
            return [
                'success' => false,
                'message' => $validate_error,
            ];
        }

        $supplier_tax_code = $settings['supplier_tax_code'];
        $url = $this->build_api_url($settings, $mode, $supplier_tax_code);

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($settings['auth_mode'] === 'token') {
            $headers['Authorization'] = 'Bearer ' . $settings['access_token'];
        } else {
            $token = base64_encode($settings['username'] . ':' . $settings['password']);
            $headers['Authorization'] = 'Basic ' . $token;
        }

        $request_body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $request_body,
            'timeout' => 45,
            'sslverify' => !empty($settings['verify_ssl']),
        ]);

        $http_code = 0;
        $response_text = '';
        $response_data = null;
        $error_message = '';

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
        } else {
            $http_code = (int) wp_remote_retrieve_response_code($response);
            $response_text = (string) wp_remote_retrieve_body($response);
            $decoded = json_decode($response_text, true);
            $response_data = is_array($decoded) ? $decoded : null;

            if ($http_code < 200 || $http_code >= 300) {
                $error_message = 'HTTP ' . $http_code;
            }
        }

        $invoice_id = $this->insert_invoice_record([
            'mode' => $mode,
            'sale_ledger_id' => intval($context['sale_ledger_id'] ?? 0),
            'payload' => $payload,
            'request_payload' => $request_body,
            'response_payload' => $response_text,
            'response_data' => $response_data,
            'http_code' => $http_code,
            'error_message' => $error_message,
            'created_by' => intval($context['created_by'] ?? 0),
        ]);

        $this->insert_log_record([
            'invoice_id' => $invoice_id,
            'action_name' => ($mode === 'issue') ? 'create_invoice' : (($mode === 'cancel') ? 'cancel_invoice' : 'create_draft'),
            'endpoint' => $url,
            'request_headers' => wp_json_encode($this->mask_headers_for_log($headers), JSON_UNESCAPED_UNICODE),
            'request_payload' => $request_body,
            'response_payload' => $response_text,
            'http_code' => $http_code,
            'error_message' => $error_message,
            'created_by' => intval($context['created_by'] ?? 0),
        ]);

        if (!empty($error_message)) {
            return [
                'success' => false,
                'message' => 'Gui API that bai: ' . $error_message,
                'invoice_record_id' => $invoice_id,
                'http_code' => $http_code,
                'response_text' => $response_text,
            ];
        }

        return [
            'success' => true,
            'message' => ($mode === 'cancel') ? 'Gui huy hoa don thanh cong.' : 'Gui len Viettel thanh cong.',
            'invoice_record_id' => $invoice_id,
            'http_code' => $http_code,
            'response' => $response_data ?: $response_text,
        ];
    }

    private function validate_before_send($settings, $payload, $mode = 'draft')
    {
        if (empty($settings['supplier_tax_code'])) {
            return 'Thieu MST nha cung cap trong cau hinh.';
        }

        if ($settings['auth_mode'] === 'token' && empty($settings['access_token'])) {
            return 'Ban chua cau hinh access token.';
        }

        if ($settings['auth_mode'] === 'basic' && (empty($settings['username']) || empty($settings['password']))) {
            return 'Ban chua cau hinh username/password.';
        }

        if ($mode === 'cancel') {
            $required = ['supplierTaxCode', 'invoiceNo', 'templateCode', 'strIssueDate', 'reason'];
            foreach ($required as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                    return 'Payload huy can truong bat buoc: ' . $field;
                }
            }

            if (mb_strlen((string) $payload['reason']) > 255) {
                return 'Truong reason toi da 255 ky tu.';
            }

            return '';
        }

        if (!isset($payload['generalInvoiceInfo']) || !isset($payload['itemInfo']) || !isset($payload['summarizeInfo'])) {
            return 'Payload can co generalInvoiceInfo, itemInfo, summarizeInfo.';
        }

        return '';
    }

    private function build_api_url($settings, $mode, $supplier_tax_code)
    {
        $base = untrailingslashit($settings['api_base_url']);
        if ($mode === 'cancel') {
            return $base . '/InvoiceAPI/InvoiceWS/deleteInvoice';
        }

        $path = ($mode === 'issue')
            ? 'InvoiceAPI/InvoiceWS/createInvoice/'
            : 'InvoiceAPI/InvoiceWS/createOrUpdateInvoiceDraft/';

        return $base . '/' . $path . rawurlencode($supplier_tax_code);
    }

    private function mask_headers_for_log($headers)
    {
        $masked = $headers;
        if (!empty($masked['Authorization'])) {
            $masked['Authorization'] = substr($masked['Authorization'], 0, 18) . '***';
        }

        return $masked;
    }

    private function extract_summary_field($payload, $field)
    {
        if (!isset($payload['summarizeInfo']) || !is_array($payload['summarizeInfo'])) {
            return null;
        }

        return isset($payload['summarizeInfo'][$field]) ? floatval($payload['summarizeInfo'][$field]) : null;
    }

    private function deep_pick(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $cursor = $data;
            $parts = explode('.', $path);
            $found = true;

            foreach ($parts as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $found = false;
                    break;
                }
                $cursor = $cursor[$part];
            }

            if ($found && $cursor !== null && $cursor !== '') {
                return is_scalar($cursor) ? (string) $cursor : wp_json_encode($cursor, JSON_UNESCAPED_UNICODE);
            }
        }

        return '';
    }

    private function insert_invoice_record($data)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return 0;
        }

        global $wpdb;

        $payload = $data['payload'];
        $response_data = is_array($data['response_data']) ? $data['response_data'] : [];

        $invoice_state = !empty($data['error_message'])
            ? 'error'
            : (($data['mode'] === 'issue') ? 'issued' : (($data['mode'] === 'cancel') ? 'canceled' : 'draft'));

        $general = isset($payload['generalInvoiceInfo']) && is_array($payload['generalInvoiceInfo']) ? $payload['generalInvoiceInfo'] : [];
        $buyer = isset($payload['buyerInfo']) && is_array($payload['buyerInfo']) ? $payload['buyerInfo'] : [];

        $wpdb->insert(
            TGS_TABLE_LOCAL_VIETTEL_INVOICE,
            [
                'blog_id' => get_current_blog_id(),
                'sale_ledger_id' => !empty($data['sale_ledger_id']) ? intval($data['sale_ledger_id']) : null,
                'local_ledger_code' => sanitize_text_field($payload['local_ledger_code'] ?? ''),
                'request_mode' => $data['mode'],
                'invoice_state' => $invoice_state,
                'viettel_invoice_id' => $this->deep_pick($response_data, ['result.invoiceId', 'data.invoiceId', 'invoiceId', 'id']),
                'viettel_invoice_no' => $this->deep_pick($response_data, ['result.invoiceNo', 'data.invoiceNo', 'invoiceNo', 'invoiceNumber']),
                'template_code' => sanitize_text_field($general['templateCode'] ?? ''),
                'invoice_series' => sanitize_text_field($general['invoiceSeries'] ?? ''),
                'buyer_name' => sanitize_text_field($buyer['buyerName'] ?? ($buyer['buyerLegalName'] ?? '')),
                'buyer_tax_code' => sanitize_text_field($buyer['buyerTaxCode'] ?? ''),
                'total_before_tax' => $this->extract_summary_field($payload, 'totalAmountWithoutTax'),
                'total_tax_amount' => $this->extract_summary_field($payload, 'totalTaxAmount'),
                'total_after_tax' => $this->extract_summary_field($payload, 'totalAmountWithTax'),
                'request_payload' => $data['request_payload'],
                'response_payload' => $data['response_payload'],
                'error_message' => $data['error_message'],
                'http_code' => intval($data['http_code']),
                'created_by' => intval($data['created_by']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%d', '%s', '%s',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    private function insert_log_record($data)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG')) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG,
            [
                'local_viettel_invoice_id' => !empty($data['invoice_id']) ? intval($data['invoice_id']) : null,
                'blog_id' => get_current_blog_id(),
                'action_name' => sanitize_text_field($data['action_name'] ?? ''),
                'endpoint' => esc_url_raw($data['endpoint'] ?? ''),
                'request_headers' => (string) ($data['request_headers'] ?? ''),
                'request_payload' => (string) ($data['request_payload'] ?? ''),
                'response_payload' => (string) ($data['response_payload'] ?? ''),
                'error_message' => (string) ($data['error_message'] ?? ''),
                'http_code' => intval($data['http_code'] ?? 0),
                'created_by' => intval($data['created_by'] ?? 0),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
        );
    }

    private function get_recent_invoices($limit = 20)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return [];
        }

        global $wpdb;
        $table = TGS_TABLE_LOCAL_VIETTEL_INVOICE;

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY local_viettel_invoice_id DESC LIMIT %d",
            intval($limit)
        );

        return $wpdb->get_results($sql, ARRAY_A);
    }

    public function handle_sale_completed($sale_data)
    {
        $settings = self::get_settings();
        if (empty($settings['auto_enabled'])) {
            return;
        }

        $payload = apply_filters('tgs_viettel_invoice_build_payload_from_sale', [], $sale_data, $settings);
        if (empty($payload) || !is_array($payload)) {
            return;
        }

        $mode = ($settings['auto_mode'] === 'issue') ? 'issue' : 'draft';

        $this->submit_invoice_payload($payload, $mode, [
            'created_by' => intval($sale_data['employee_id'] ?? 0),
            'sale_ledger_id' => intval($sale_data['sale_ledger_id'] ?? 0),
        ]);
    }

    public function get_create_view_data()
    {
        $settings = self::get_settings();

        $payload_sample = [
            'generalInvoiceInfo' => [
                'invoiceType' => '1',
                'templateCode' => $settings['default_template_code'],
                'invoiceSeries' => $settings['default_invoice_series'],
                'currencyCode' => 'VND',
                'exchangeRate' => 1,
                'adjustmentType' => '1',
                'paymentStatus' => true,
                'cusGetInvoiceRight' => true,
                'invoiceIssuedDate' => null,
                'transactionUuid' => null,
            ],
            'buyerInfo' => [
                'buyerName' => 'Khach le',
                'buyerTaxCode' => null,
                'buyerAddressLine' => 'Dia chi mau',
                'buyerPhoneNumber' => null,
                'buyerEmail' => null,
                'buyerNotGetInvoice' => '0',
            ],
            'payments' => [
                [
                    'paymentMethod' => '3',
                    'paymentMethodName' => $settings['default_payment_method'] ?: 'TM/CK',
                ],
            ],
            'itemInfo' => [
                [
                    'lineNumber' => 1,
                    'selection' => 1,
                    'itemCode' => 'SKU-MAU-001',
                    'itemName' => 'San pham mau',
                    'unitName' => 'Hop',
                    'quantity' => 1,
                    'unitPrice' => 15741,
                    'itemTotalAmountWithoutTax' => 15741,
                    'itemTotalAmountAfterDiscount' => 15741,
                    'itemTotalAmountWithTax' => 17000,
                    'taxPercentage' => 8,
                    'taxAmount' => 1259,
                    'itemNote' => null,
                    'isIncreaseItem' => null,
                ],
            ],
            'taxBreakdowns' => [
                [
                    'taxPercentage' => 8,
                    'taxableAmount' => 15741,
                    'taxAmount' => 1259,
                ],
            ],
            'summarizeInfo' => [
                'sumOfTotalLineAmountWithoutTax' => 15741,
                'totalAmountAfterDiscount' => 15741,
                'totalAmountWithoutTax' => 15741,
                'totalTaxAmount' => 1259,
                'totalAmountWithTax' => 17000,
            ],
            'metadata' => [
                [
                    'keyTag' => 'invoiceNote',
                    'stringValue' => 'Tao tu plugin TGS Viettel Invoice',
                    'valueType' => 'text',
                    'keyLabel' => 'Ghi chu',
                ],
            ],
        ];

        $cancel_payload_sample = [
            'supplierTaxCode' => $settings['supplier_tax_code'],
            'invoiceNo' => 'K24TXM4',
            'templateCode' => $settings['default_template_code'] ?: '1/770',
            'strIssueDate' => 1734075029000,
            'reason' => 'Huy hoa don de tao lai dung thong tin',
        ];

        return [
            'settings' => $settings,
            'sample_json' => wp_json_encode($payload_sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'cancel_sample_json' => wp_json_encode($cancel_payload_sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'recent_invoices' => $this->get_recent_invoices(20),
        ];
    }
}

function tgs_viettel_invoice()
{
    return TGS_Viettel_Invoice_Plugin::instance();
}

tgs_viettel_invoice();
