<?php
/**
 * Plugin Name: TGS Viettel Invoice
 * Plugin URI:  https://thegioisua.vn
 * Description: Tạo hóa đơn nháp/phát hành trên Viettel VInvoice, tích hợp vào TGS Shop Management.
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

$tgs_viettel_flow_service_file = TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-invoice-flow-service.php';
if (file_exists($tgs_viettel_flow_service_file)) {
    require_once $tgs_viettel_flow_service_file;
}

class TGS_Viettel_Invoice_Plugin
{
    const OPTION_SETTINGS = 'tgs_viettel_invoice_settings';

    private static $instance = null;
    private $flow_service = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (class_exists('TGS_Viettel_Invoice_Flow_Service')) {
            $this->flow_service = new TGS_Viettel_Invoice_Flow_Service();
        }

        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);
        add_action('tgs_shop_system_menu', [$this, 'render_system_menu'], 25, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_tgs_viettel_invoice_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_send_payload', [$this, 'ajax_send_payload']);
        add_action('wp_ajax_tgs_viettel_invoice_list_statuses', [$this, 'ajax_list_statuses']);
        add_action('wp_ajax_tgs_viettel_invoice_retry_invoice', [$this, 'ajax_retry_invoice']);

        add_action('wp_ajax_tgs_viettel_send_from_sale', [$this, 'ajax_send_from_sale']);
        add_action('wp_ajax_nopriv_tgs_viettel_send_from_sale', [$this, 'ajax_send_from_sale']);
        add_action('wp_ajax_tgs_viettel_get_sale_debug_log', [$this, 'ajax_get_sale_debug_log']);
        add_action('wp_ajax_tgs_viettel_pos_list_statuses', [$this, 'ajax_pos_list_statuses']);

        // Nút "Gửi CQT" trên popup in hóa đơn POS (ưu tiên 20 để xuất sau nút Xuất HĐDT)
        add_action('tgs_pos_receipt_footer_buttons', [$this, 'render_cqt_receipt_button'], 20);

        // Theo luồng POS mới: KHÔNG tự động gửi thuế ngay khi tạo đơn sale.
        // add_action('tgs_sale_completed', [$this, 'handle_sale_completed'], 20, 1);
    }

    public function register_routes($routes)
    {
        $routes['viettel-invoice-create'] = ['Viettel Invoice', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/create-invoice.php'];
        $routes['viettel-invoice-settings'] = ['Cấu hình Viettel Invoice', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/settings.php'];
        $routes['viettel-invoice-guide'] = ['Luồng Viettel Invoice', TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'admin-views/flow-guide.php'];

        return $routes;
    }

    public function render_system_menu($current_view)
    {
        $items = [
            'viettel-invoice-create' => ['bx bx-receipt text-primary me-1', 'Viettel Invoice'],
            'viettel-invoice-settings' => ['bx bx-cog text-warning me-1', 'Cấu hình Viettel Invoice'],
            'viettel-invoice-guide' => ['bx bx-book-content text-info me-1', 'Hướng dẫn luồng'],
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
            'auto_mode' => 'issue',
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
            'auto_mode' => 'issue',
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
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        $raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Dữ liệu không hợp lệ.'], 400);
        }

        $settings = $this->sanitize_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_send_json_success([
            'message' => 'Đã lưu cấu hình cho cửa hàng hiện tại.',
        ]);
    }

    public function ajax_export_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
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
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        $json_text = isset($_POST['settings_json']) ? wp_unslash($_POST['settings_json']) : '';
        if (empty($json_text)) {
            wp_send_json_error(['message' => 'Bạn chưa nhập JSON.'], 400);
        }

        $decoded = json_decode($json_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            wp_send_json_error(['message' => 'JSON không hợp lệ: ' . json_last_error_msg()], 400);
        }

        $payload = $decoded;
        if (isset($decoded['settings']) && is_array($decoded['settings'])) {
            $payload = $decoded['settings'];
        }

        $settings = $this->sanitize_settings($payload);
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_send_json_success(['message' => 'Đã import cấu hình thành công.']);
    }

    public function ajax_send_payload()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        $mode = 'draft';
        if (isset($_POST['mode'])) {
            $input_mode = sanitize_text_field(wp_unslash($_POST['mode']));
            if (in_array($input_mode, ['draft', 'issue', 'cancel', 'send_cqt'], true)) {
                $mode = $input_mode;
            }
        }
        $payload_text = isset($_POST['payload_json']) ? wp_unslash($_POST['payload_json']) : '';

        if (empty($payload_text)) {
            wp_send_json_error(['message' => 'Payload JSON đang rỗng.'], 400);
        }

        $payload = json_decode($payload_text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            wp_send_json_error(['message' => 'Payload JSON không hợp lệ: ' . json_last_error_msg()], 400);
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

    public function ajax_list_statuses()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
        }

        global $wpdb;

        $status_filter = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'all';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        if ($limit <= 0 || $limit > 200) {
            $limit = 50;
        }

        $where = '1=1';
        if ($status_filter === 'success') {
            $where = "invoice_state = 'done'";
        } elseif ($status_filter === 'failed') {
            $where = "invoice_state IN ('issue_error', 'cqt_error', 'validate_error', 'error')";
        } elseif ($status_filter === 'pending') {
            $where = "invoice_state IN ('pending', 'issued')";
        } elseif ($status_filter === 'unsent') {
            $where = "COALESCE(send_cqt_status, 0) = 0";
        }

        $sql = "SELECT
                    local_viettel_invoice_id,
                    sale_ledger_id,
                    local_ledger_code,
                    invoice_state,
                    issue_status,
                    send_cqt_status,
                    contains_under24_main_item,
                    issue_transaction_uuid,
                    error_message,
                    created_at,
                    updated_at
                FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . "
                WHERE {$where}
                ORDER BY local_viettel_invoice_id DESC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);

        wp_send_json_success([
            'items' => is_array($rows) ? $rows : [],
            'status_filter' => $status_filter,
        ]);
    }

    public function ajax_retry_invoice()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
        }

        $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
        if ($invoice_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu invoice_id để gửi lại.'], 400);
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, resend_count FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . ' WHERE local_viettel_invoice_id = %d LIMIT 1',
                $invoice_id
            ),
            ARRAY_A
        );

        if (empty($row) || intval($row['sale_ledger_id'] ?? 0) <= 0) {
            wp_send_json_error(['message' => 'Không tìm thấy hóa đơn để gửi lại hoặc thiếu mã đơn bán hàng.'], 404);
        }

        $wpdb->update(
            TGS_TABLE_LOCAL_VIETTEL_INVOICE,
            [
                'resend_count' => intval($row['resend_count'] ?? 0) + 1,
                'last_retry_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['local_viettel_invoice_id' => $invoice_id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        $settings = self::get_settings();
        if (empty($settings['auto_enabled'])) {
            wp_send_json_error(['message' => 'Chế độ tự động đang tắt, chưa thể gửi lại tự động.'], 400);
        }

        if (!$this->flow_service) {
            wp_send_json_error(['message' => 'Thiếu dịch vụ xử lý luồng hóa đơn.'], 500);
        }

        $this->run_auto_issue_cqt_flow([
            'sale_ledger_id' => intval($row['sale_ledger_id']),
            'sale_code' => sanitize_text_field($row['local_ledger_code'] ?? ''),
            'employee_id' => get_current_user_id(),
        ], $settings);

        wp_send_json_success([
            'message' => 'Đã nhận yêu cầu gửi lại hóa đơn theo mã đơn bán.',
            'invoice_id' => $invoice_id,
            'sale_ledger_id' => intval($row['sale_ledger_id']),
        ]);
    }

    /**
     * POS/Dev debug: lấy snapshot trạng thái mới nhất + timeline log theo đơn bán.
     */
    public function ajax_get_sale_debug_log()
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem nhật ký.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE') || !defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng log Viettel Invoice.'], 500);
            return;
        }

        $sale_ledger_id = intval($_POST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id.'], 400);
            return;
        }

        $limit = intval($_POST['limit'] ?? 30);
        if ($limit <= 0 || $limit > 100) {
            $limit = 30;
        }

        global $wpdb;

        $latest_invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    local_viettel_invoice_id,
                    sale_ledger_id,
                    local_ledger_code,
                    invoice_state,
                    issue_status,
                    send_cqt_status,
                    contains_under24_main_item,
                    issue_transaction_uuid,
                    issue_http_code,
                    cqt_http_code,
                    issue_error_message,
                    cqt_error_message,
                    error_message,
                    created_at,
                    updated_at
                FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . "
                WHERE sale_ledger_id = %d
                ORDER BY local_viettel_invoice_id DESC
                LIMIT 1",
                $sale_ledger_id
            ),
            ARRAY_A
        );

        $logs = [];
        if (!empty($latest_invoice['local_viettel_invoice_id'])) {
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        local_viettel_invoice_log_id,
                        local_viettel_invoice_id,
                        sale_ledger_id,
                        local_ledger_code,
                        step_name,
                        action_name,
                        transaction_uuid,
                        endpoint,
                        http_code,
                        error_message,
                        request_payload,
                        response_payload,
                        created_at
                    FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG . "
                    WHERE local_viettel_invoice_id = %d
                    ORDER BY local_viettel_invoice_log_id DESC
                    LIMIT %d",
                    intval($latest_invoice['local_viettel_invoice_id']),
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                        local_viettel_invoice_log_id,
                        local_viettel_invoice_id,
                        sale_ledger_id,
                        local_ledger_code,
                        step_name,
                        action_name,
                        transaction_uuid,
                        endpoint,
                        http_code,
                        error_message,
                        request_payload,
                        response_payload,
                        created_at
                    FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG . "
                    WHERE sale_ledger_id = %d
                    ORDER BY local_viettel_invoice_log_id DESC
                    LIMIT %d",
                    $sale_ledger_id,
                    $limit
                ),
                ARRAY_A
            );
        }

        wp_send_json_success([
            'sale_ledger_id' => $sale_ledger_id,
            'latest_invoice' => is_array($latest_invoice) ? $latest_invoice : null,
            'logs' => is_array($logs) ? $logs : [],
        ]);
    }

    /**
     * POS: danh sách hóa đơn Viettel theo bộ lọc trạng thái để hiển thị sidebar/panel.
     */
    public function ajax_pos_list_statuses()
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!current_user_can('read')) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem danh sách.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
            return;
        }

        global $wpdb;

        $status_filter = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'all';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 40;
        if ($limit <= 0 || $limit > 120) {
            $limit = 40;
        }

        $where = '1=1';
        if ($status_filter === 'success') {
            $where = "invoice_state = 'done'";
        } elseif ($status_filter === 'failed') {
            $where = "invoice_state IN ('issue_error', 'cqt_error', 'validate_error', 'error')";
        } elseif ($status_filter === 'pending') {
            $where = "invoice_state IN ('pending', 'issued')";
        } elseif ($status_filter === 'unsent') {
            $where = "COALESCE(send_cqt_status, 0) = 0";
        }

        $sql = "SELECT
                    local_viettel_invoice_id,
                    sale_ledger_id,
                    local_ledger_code,
                    invoice_state,
                    issue_status,
                    send_cqt_status,
                    contains_under24_main_item,
                    issue_transaction_uuid,
                    issue_http_code,
                    cqt_http_code,
                    error_message,
                    created_at,
                    updated_at
                FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . "
                WHERE {$where}
                ORDER BY local_viettel_invoice_id DESC
                LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);

        wp_send_json_success([
            'items' => is_array($rows) ? $rows : [],
            'status_filter' => $status_filter,
        ]);
    }

    private function submit_invoice_payload($payload, $mode, $context = [])
    {
        $settings = self::get_settings();
        if ($mode === 'cancel' && empty($payload['supplierTaxCode']) && !empty($settings['supplier_tax_code'])) {
            $payload['supplierTaxCode'] = $settings['supplier_tax_code'];
        }
        if ($mode === 'send_cqt' && empty($payload['supplierTaxCode']) && !empty($settings['supplier_tax_code'])) {
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

        $invoice_id = intval($context['invoice_record_id'] ?? 0);
        if (empty($context['skip_persist'])) {
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
        }

        $action_name = ($mode === 'issue')
            ? 'create_invoice'
            : (($mode === 'cancel') ? 'cancel_invoice' : (($mode === 'send_cqt') ? 'send_cqt' : 'create_draft'));

        $this->insert_log_record([
            'invoice_id' => $invoice_id,
            'sale_ledger_id' => intval($context['sale_ledger_id'] ?? 0),
            'local_ledger_code' => sanitize_text_field($payload['local_ledger_code'] ?? ''),
            'step_name' => sanitize_text_field($context['step_name'] ?? $action_name),
            'transaction_uuid' => sanitize_text_field($context['transaction_uuid'] ?? ''),
            'action_name' => $action_name,
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
                'message' => 'Gửi API thất bại: ' . $error_message,
                'invoice_record_id' => $invoice_id,
                'http_code' => $http_code,
                'response_text' => $response_text,
            ];
        }

        return [
            'success' => true,
            'message' => ($mode === 'cancel')
                ? 'Gửi hủy hóa đơn thành công.'
                : (($mode === 'send_cqt') ? 'Gửi hóa đơn lên CQT thành công.' : 'Gửi lên Viettel thành công.'),
            'invoice_record_id' => $invoice_id,
            'http_code' => $http_code,
            'response' => $response_data ?: $response_text,
        ];
    }

    private function validate_before_send($settings, $payload, $mode = 'draft')
    {
        if (empty($settings['supplier_tax_code'])) {
            return 'Thiếu MST nhà cung cấp trong cấu hình.';
        }

        if ($settings['auth_mode'] === 'token' && empty($settings['access_token'])) {
            return 'Bạn chưa cấu hình access token.';
        }

        if ($settings['auth_mode'] === 'basic' && (empty($settings['username']) || empty($settings['password']))) {
            return 'Bạn chưa cấu hình username/password.';
        }

        if ($mode === 'cancel') {
            $required = ['supplierTaxCode', 'invoiceNo', 'templateCode', 'strIssueDate', 'reason'];
            foreach ($required as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                    return 'Payload hủy cần trường bắt buộc: ' . $field;
                }
            }

            if (mb_strlen((string) $payload['reason']) > 255) {
                return 'Trường reason tối đa 255 ký tự.';
            }

            return '';
        }

        if ($mode === 'send_cqt') {
            $required = ['supplierTaxCode', 'transactionUuid'];
            foreach ($required as $field) {
                if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === null) {
                    return 'Payload gửi CQT cần trường bắt buộc: ' . $field;
                }
            }
            return '';
        }

        if (!isset($payload['generalInvoiceInfo']) || !isset($payload['itemInfo']) || !isset($payload['summarizeInfo'])) {
            return 'Payload cần có generalInvoiceInfo, itemInfo, summarizeInfo.';
        }

        return '';
    }

    private function build_api_url($settings, $mode, $supplier_tax_code)
    {
        $base = untrailingslashit($settings['api_base_url']);
        if ($mode === 'cancel') {
            return $base . '/InvoiceAPI/InvoiceWS/deleteInvoice';
        }
        if ($mode === 'send_cqt') {
            return $base . '/InvoiceAPI/InvoiceWS/sendInvoiceByTransactionUuid';
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
                'sale_ledger_id' => !empty($data['sale_ledger_id']) ? intval($data['sale_ledger_id']) : null,
                'local_ledger_code' => sanitize_text_field($data['local_ledger_code'] ?? ''),
                'step_name' => sanitize_text_field($data['step_name'] ?? ''),
                'action_name' => sanitize_text_field($data['action_name'] ?? ''),
                'transaction_uuid' => sanitize_text_field($data['transaction_uuid'] ?? ''),
                'endpoint' => esc_url_raw($data['endpoint'] ?? ''),
                'request_headers' => (string) ($data['request_headers'] ?? ''),
                'request_payload' => (string) ($data['request_payload'] ?? ''),
                'response_payload' => (string) ($data['response_payload'] ?? ''),
                'error_message' => (string) ($data['error_message'] ?? ''),
                'http_code' => intval($data['http_code'] ?? 0),
                'created_by' => intval($data['created_by'] ?? 0),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
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

        /**
         * Render nút "Gửi lên cục thuế" vào popup in hóa đơn POS.
         * Nút này dùng Alpine.js binding để gọi openViettelCQTModal().
         */
        public function render_cqt_receipt_button()
        {
            ?>
            <button type="button"
                x-on:click="openViettelCQTModal()"
                :disabled="isReceiptLoading || viettelCQT.isSending"
                :class="isReceiptLoading || viettelCQT.isSending ? 'opacity-60 cursor-not-allowed' : ''"
                class="flex-1 min-w-[120px] py-3 bg-green-600 rounded-xl text-sm font-medium text-white hover:bg-green-700">
                <span x-show="!viettelCQT.isSending">Gửi lên cục thuế</span>
                <span x-show="viettelCQT.isSending" class="flex items-center justify-center gap-1">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    Đang gửi...
                </span>
            </button>
            <?php
        }

        /**
         * AJAX handler: POS gọi để phát hành hóa đơn + gửi CQT từ đơn bán hàng.
         * POST params: sale_ledger_id, force_under24 (1 = nhân viên xác nhận dù có SP dưới 24 tháng)
         */
        public function ajax_send_from_sale()
        {
            $nonce = sanitize_text_field($_POST['nonce'] ?? '');
            if (
                empty($nonce)
                || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
            ) {
                wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
                return;
            }

            if (!current_user_can('read')) {
                wp_send_json_error(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
                return;
            }

            $sale_ledger_id = intval($_POST['sale_ledger_id'] ?? 0);
            $force_under24  = !empty($_POST['force_under24']); // nhân viên đã xác nhận cảnh báo

            if ($sale_ledger_id <= 0) {
                wp_send_json_error(['message' => 'Thiếu sale_ledger_id.']);
                return;
            }

            if (!$this->flow_service) {
                wp_send_json_error(['message' => 'Flow service chưa khởi tạo.']);
                return;
            }

            $settings = self::get_settings();
            if (empty($settings['username']) || empty($settings['supplier_tax_code'])) {
                wp_send_json_error(['message' => 'Chưa cấu hình Viettel Invoice. Vui lòng vào Cấu hình để thiết lập.']);
                return;
            }

            // Bước 1: Build source payload từ đơn bán hàng
            $source_result = $this->flow_service->build_smart_payload_from_sale($sale_ledger_id);
            if (empty($source_result['success'])) {
                wp_send_json_error(['message' => $source_result['message'] ?? 'Không thể đọc dữ liệu đơn bán.', 'step' => 'build']);
                return;
            }

            // Bước 2: Lọc + sắp xếp items theo quy tắc thuế
            $filtered_result = $this->flow_service->filter_and_sort_items_for_tax($source_result['payload']);
            if (empty($filtered_result['success'])) {
                wp_send_json_error(['message' => $filtered_result['message'] ?? 'Lỗi lọc sản phẩm.', 'step' => 'filter']);
                return;
            }

            $filtered_payload = $filtered_result['payload'];

            // Nếu có SP chính dưới 24 tháng và nhân viên chưa xác nhận → trả về cảnh báo
            if (!$force_under24 && !empty($filtered_payload['contains_under24_main_item'])) {
                wp_send_json_error([
                    'message'      => 'Đơn hàng có sản phẩm chính dưới 24 tháng tuổi. Nhân viên cần xác nhận để tiếp tục.',
                    'step'         => 'under24_warning',
                    'under24_skus' => $filtered_payload['under24_main_sku_list'] ?? [],
                    'need_confirm' => true,
                ]);
                return;
            }

            // Bước 3: Build payload Viettel và phát hành hóa đơn
            $issue_payload_result = $this->flow_service->build_issue_payload_from_filtered($filtered_payload, $settings);
            if (empty($issue_payload_result['success'])) {
                wp_send_json_error(['message' => $issue_payload_result['message'] ?? 'Không map được payload phát hành.', 'step' => 'build_payload']);
                return;
            }

            $issue_payload = $issue_payload_result['payload'];
            $issue_payload['local_ledger_code'] = sanitize_text_field($filtered_payload['sale_code'] ?? '');

            $created_by = get_current_user_id();
            $tracking_id = $this->create_auto_flow_tracking(
                $source_result['payload'],
                $filtered_payload,
                $created_by,
                'pending',
                0,
                0,
                ''
            );

            $issue_result = $this->submit_invoice_payload($issue_payload, 'issue', [
                'skip_persist' => true,
                'step_name'    => 'issue',
                'invoice_record_id' => $tracking_id,
                'created_by'   => $created_by,
                'sale_ledger_id' => $sale_ledger_id,
            ]);

            if (empty($issue_result['success'])) {
                $this->update_auto_flow_tracking($tracking_id, [
                    'invoice_state' => 'issue_error',
                    'issue_status' => 2,
                    'issue_request_payload' => wp_json_encode($issue_payload, JSON_UNESCAPED_UNICODE),
                    'issue_response_payload' => wp_json_encode($issue_result, JSON_UNESCAPED_UNICODE),
                    'issue_http_code' => intval($issue_result['http_code'] ?? 0),
                    'issue_error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn.'),
                    'error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn.'),
                    'updated_at' => current_time('mysql'),
                ]);

                wp_send_json_error([
                    'message' => $issue_result['message'] ?? 'Lỗi phát hành hóa đơn.',
                    'step'    => 'issue',
                ]);
                return;
            }

            $transaction_uuid = $this->extract_transaction_uuid($issue_result['response'] ?? []);
            $issue_response_json = wp_json_encode($issue_result['response'] ?? $issue_result, JSON_UNESCAPED_UNICODE);
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'issued',
                'issue_status' => 1,
                'issue_request_payload' => wp_json_encode($issue_payload, JSON_UNESCAPED_UNICODE),
                'issue_response_payload' => $issue_response_json,
                'issue_http_code' => intval($issue_result['http_code'] ?? 0),
                'issue_error_message' => '',
                'issue_transaction_uuid' => $transaction_uuid,
                'issue_sent_at' => current_time('mysql'),
                'total_before_tax' => floatval($issue_payload_result['totals']['total_before_tax'] ?? 0),
                'total_tax_amount' => floatval($issue_payload_result['totals']['total_tax'] ?? 0),
                'total_after_tax' => floatval($issue_payload_result['totals']['total_after_tax'] ?? 0),
                'template_code' => sanitize_text_field($issue_payload['generalInvoiceInfo']['templateCode'] ?? ''),
                'invoice_series' => sanitize_text_field($issue_payload['generalInvoiceInfo']['invoiceSeries'] ?? ''),
                'buyer_name' => sanitize_text_field($issue_payload['buyerInfo']['buyerName'] ?? ''),
                'buyer_tax_code' => sanitize_text_field($issue_payload['buyerInfo']['buyerTaxCode'] ?? ''),
                'updated_at' => current_time('mysql'),
            ]);

            if ($transaction_uuid === '') {
                $this->update_auto_flow_tracking($tracking_id, [
                    'invoice_state' => 'cqt_error',
                    'send_cqt_status' => 2,
                    'cqt_error_message' => 'Không lấy được transactionUuid sau khi phát hành.',
                    'error_message' => 'Không lấy được transactionUuid sau khi phát hành.',
                    'updated_at' => current_time('mysql'),
                ]);

                wp_send_json_error(['message' => 'Phát hành thành công nhưng không lấy được transactionUuid để gửi CQT.', 'step' => 'extract_uuid']);
                return;
            }

            // Bước 4: Gửi CQT
            $cqt_payload_result = $this->flow_service->build_send_cqt_payload(
                sanitize_text_field($settings['supplier_tax_code'] ?? ''),
                $transaction_uuid
            );
            if (empty($cqt_payload_result['success'])) {
                wp_send_json_error(['message' => $cqt_payload_result['message'] ?? 'Không tạo được payload gửi CQT.', 'step' => 'build_cqt']);
                return;
            }

            $cqt_payload = $cqt_payload_result['payload'];
            $cqt_payload['local_ledger_code'] = sanitize_text_field($filtered_payload['sale_code'] ?? '');

            $cqt_result = $this->submit_invoice_payload($cqt_payload, 'send_cqt', [
                'skip_persist'   => true,
                'step_name'      => 'send_cqt',
                'invoice_record_id' => $tracking_id,
                'transaction_uuid' => $transaction_uuid,
                'created_by'     => $created_by,
                'sale_ledger_id' => $sale_ledger_id,
            ]);

            if (empty($cqt_result['success'])) {
                $this->update_auto_flow_tracking($tracking_id, [
                    'invoice_state' => 'cqt_error',
                    'send_cqt_status' => 2,
                    'cqt_request_payload' => wp_json_encode($cqt_payload, JSON_UNESCAPED_UNICODE),
                    'cqt_response_payload' => wp_json_encode($cqt_result, JSON_UNESCAPED_UNICODE),
                    'cqt_http_code' => intval($cqt_result['http_code'] ?? 0),
                    'cqt_error_message' => sanitize_text_field($cqt_result['message'] ?? 'Lỗi gửi CQT.'),
                    'error_message' => sanitize_text_field($cqt_result['message'] ?? 'Lỗi gửi CQT.'),
                    'updated_at' => current_time('mysql'),
                ]);

                wp_send_json_error([
                    'message'          => $cqt_result['message'] ?? 'Lỗi gửi CQT.',
                    'step'             => 'send_cqt',
                    'transaction_uuid' => $transaction_uuid,
                ]);
                return;
            }

            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'done',
                'send_cqt_status' => 1,
                'cqt_request_payload' => wp_json_encode($cqt_payload, JSON_UNESCAPED_UNICODE),
                'cqt_response_payload' => wp_json_encode($cqt_result['response'] ?? $cqt_result, JSON_UNESCAPED_UNICODE),
                'cqt_http_code' => intval($cqt_result['http_code'] ?? 0),
                'cqt_error_message' => '',
                'error_message' => '',
                'cqt_sent_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);

            $invoice_no = $this->deep_pick($issue_result['response'] ?? [], [
                'result.invoiceNo',
                'data.invoiceNo',
                'invoiceNo',
            ]);

            wp_send_json_success([
                'message'          => 'Phát hành và gửi CQT thành công!',
                'transaction_uuid' => $transaction_uuid,
                'invoice_no'       => $invoice_no,
                'tracking_id'      => $tracking_id,
            ]);
        }

    public function handle_sale_completed($sale_data)
    {
        $settings = self::get_settings();
        if (empty($settings['auto_enabled'])) {
            return;
        }

        if (!$this->flow_service || !is_array($sale_data)) {
            return;
        }

        $this->run_auto_issue_cqt_flow($sale_data, $settings);
    }

    private function run_auto_issue_cqt_flow($sale_data, $settings)
    {
        $sale_ledger_id = intval($sale_data['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            return;
        }

        $created_by = intval($sale_data['employee_id'] ?? 0);

        $source_result = $this->flow_service->build_smart_payload_from_sale($sale_ledger_id);
        if (empty($source_result['success'])) {
            $this->insert_log_record([
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($sale_data['sale_code'] ?? ''),
                'step_name' => 'prepare_source',
                'action_name' => 'auto_prepare_source',
                'error_message' => sanitize_text_field($source_result['message'] ?? 'Không thể đọc dữ liệu đơn bán.'),
                'created_by' => $created_by,
            ]);
            return;
        }

        $source_payload = $source_result['payload'];
        $filtered_result = $this->flow_service->filter_and_sort_items_for_tax($source_payload);
        if (empty($filtered_result['success'])) {
            $tracking_id = $this->create_auto_flow_tracking($source_payload, [], $created_by, 'validate_error', 2, 0, sanitize_text_field($filtered_result['message'] ?? 'Lỗi lọc sản phẩm gửi thuế.'));

            $this->insert_log_record([
                'invoice_id' => $tracking_id,
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($source_payload['sale_code'] ?? ''),
                'step_name' => 'filter_items',
                'action_name' => 'auto_filter_items',
                'request_payload' => wp_json_encode($source_payload, JSON_UNESCAPED_UNICODE),
                'error_message' => sanitize_text_field($filtered_result['message'] ?? 'Lỗi lọc sản phẩm gửi thuế.'),
                'created_by' => $created_by,
            ]);
            return;
        }

        $filtered_payload = $filtered_result['payload'];
        $tracking_id = $this->create_auto_flow_tracking($source_payload, $filtered_payload, $created_by, 'pending', 0, 0, '');

        $issue_payload_result = $this->flow_service->build_issue_payload_from_filtered($filtered_payload, $settings);
        if (empty($issue_payload_result['success'])) {
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'validate_error',
                'issue_status' => 2,
                'issue_error_message' => sanitize_text_field($issue_payload_result['message'] ?? 'Không map được payload phát hành.'),
                'updated_at' => current_time('mysql'),
            ]);

            $this->insert_log_record([
                'invoice_id' => $tracking_id,
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($filtered_payload['sale_code'] ?? ''),
                'step_name' => 'build_issue_payload',
                'action_name' => 'auto_build_issue_payload',
                'request_payload' => wp_json_encode($filtered_payload, JSON_UNESCAPED_UNICODE),
                'error_message' => sanitize_text_field($issue_payload_result['message'] ?? ''),
                'created_by' => $created_by,
            ]);
            return;
        }

        $issue_payload = $issue_payload_result['payload'];
        $issue_payload['local_ledger_code'] = sanitize_text_field($filtered_payload['sale_code'] ?? '');

        $issue_result = $this->submit_invoice_payload($issue_payload, 'issue', [
            'skip_persist' => true,
            'invoice_record_id' => $tracking_id,
            'step_name' => 'issue',
            'created_by' => $created_by,
            'sale_ledger_id' => $sale_ledger_id,
        ]);

        $issue_response_json = wp_json_encode($issue_result['response'] ?? $issue_result, JSON_UNESCAPED_UNICODE);
        $issue_http_code = intval($issue_result['http_code'] ?? 0);

        if (empty($issue_result['success'])) {
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'issue_error',
                'issue_status' => 2,
                'issue_request_payload' => wp_json_encode($issue_payload, JSON_UNESCAPED_UNICODE),
                'issue_response_payload' => wp_json_encode($issue_result, JSON_UNESCAPED_UNICODE),
                'issue_http_code' => $issue_http_code,
                'issue_error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn.'),
                'error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn.'),
                'updated_at' => current_time('mysql'),
            ]);
            return;
        }

        $transaction_uuid = $this->extract_transaction_uuid($issue_result['response'] ?? []);

        $this->update_auto_flow_tracking($tracking_id, [
            'invoice_state' => 'issued',
            'issue_status' => 1,
            'issue_request_payload' => wp_json_encode($issue_payload, JSON_UNESCAPED_UNICODE),
            'issue_response_payload' => $issue_response_json,
            'issue_http_code' => $issue_http_code,
            'issue_error_message' => '',
            'issue_transaction_uuid' => $transaction_uuid,
            'issue_sent_at' => current_time('mysql'),
            'total_before_tax' => floatval($issue_payload_result['totals']['total_before_tax'] ?? 0),
            'total_tax_amount' => floatval($issue_payload_result['totals']['total_tax'] ?? 0),
            'total_after_tax' => floatval($issue_payload_result['totals']['total_after_tax'] ?? 0),
            'template_code' => sanitize_text_field($issue_payload['generalInvoiceInfo']['templateCode'] ?? ''),
            'invoice_series' => sanitize_text_field($issue_payload['generalInvoiceInfo']['invoiceSeries'] ?? ''),
            'buyer_name' => sanitize_text_field($issue_payload['buyerInfo']['buyerName'] ?? ''),
            'buyer_tax_code' => sanitize_text_field($issue_payload['buyerInfo']['buyerTaxCode'] ?? ''),
            'updated_at' => current_time('mysql'),
        ]);

        if ($transaction_uuid === '') {
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'cqt_error',
                'send_cqt_status' => 2,
                'cqt_error_message' => 'Không lấy được transactionUuid sau khi phát hành.',
                'error_message' => 'Không lấy được transactionUuid sau khi phát hành.',
                'updated_at' => current_time('mysql'),
            ]);

            $this->insert_log_record([
                'invoice_id' => $tracking_id,
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($filtered_payload['sale_code'] ?? ''),
                'step_name' => 'send_cqt',
                'action_name' => 'auto_send_cqt',
                'error_message' => 'Không lấy được transactionUuid sau khi phát hành.',
                'created_by' => $created_by,
            ]);
            return;
        }

        $send_cqt_payload_result = $this->flow_service->build_send_cqt_payload(
            sanitize_text_field($settings['supplier_tax_code'] ?? ''),
            $transaction_uuid
        );

        if (empty($send_cqt_payload_result['success'])) {
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'cqt_error',
                'send_cqt_status' => 2,
                'cqt_error_message' => sanitize_text_field($send_cqt_payload_result['message'] ?? 'Không tạo được payload gửi CQT.'),
                'error_message' => sanitize_text_field($send_cqt_payload_result['message'] ?? 'Không tạo được payload gửi CQT.'),
                'updated_at' => current_time('mysql'),
            ]);
            return;
        }

        $send_cqt_payload = $send_cqt_payload_result['payload'];
        $send_cqt_payload['local_ledger_code'] = sanitize_text_field($filtered_payload['sale_code'] ?? '');

        $cqt_result = $this->submit_invoice_payload($send_cqt_payload, 'send_cqt', [
            'skip_persist' => true,
            'invoice_record_id' => $tracking_id,
            'step_name' => 'send_cqt',
            'transaction_uuid' => $transaction_uuid,
            'created_by' => $created_by,
            'sale_ledger_id' => $sale_ledger_id,
        ]);

        $cqt_http_code = intval($cqt_result['http_code'] ?? 0);
        if (empty($cqt_result['success'])) {
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'cqt_error',
                'send_cqt_status' => 2,
                'cqt_request_payload' => wp_json_encode($send_cqt_payload, JSON_UNESCAPED_UNICODE),
                'cqt_response_payload' => wp_json_encode($cqt_result, JSON_UNESCAPED_UNICODE),
                'cqt_http_code' => $cqt_http_code,
                'cqt_error_message' => sanitize_text_field($cqt_result['message'] ?? 'Lỗi gửi CQT.'),
                'error_message' => sanitize_text_field($cqt_result['message'] ?? 'Lỗi gửi CQT.'),
                'updated_at' => current_time('mysql'),
            ]);
            return;
        }

        $this->update_auto_flow_tracking($tracking_id, [
            'invoice_state' => 'done',
            'send_cqt_status' => 1,
            'cqt_request_payload' => wp_json_encode($send_cqt_payload, JSON_UNESCAPED_UNICODE),
            'cqt_response_payload' => wp_json_encode($cqt_result['response'] ?? $cqt_result, JSON_UNESCAPED_UNICODE),
            'cqt_http_code' => $cqt_http_code,
            'cqt_error_message' => '',
            'error_message' => '',
            'cqt_sent_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
    }

    private function create_auto_flow_tracking($source_payload, $filtered_payload, $created_by, $invoice_state, $issue_status, $send_cqt_status, $error_message)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return 0;
        }

        global $wpdb;

        $sale_ledger_id = intval($source_payload['sale_ledger_id'] ?? 0);
        $sale_code = sanitize_text_field($source_payload['sale_code'] ?? '');
        $contains_under24 = intval($filtered_payload['contains_under24_main_item'] ?? 0);
        $under24_list = $filtered_payload['under24_main_sku_list'] ?? [];

        $insert_data = [
            'blog_id' => get_current_blog_id(),
            'sale_ledger_id' => $sale_ledger_id,
            'local_ledger_code' => $sale_code,
            'request_mode' => 'issue',
            'invoice_state' => sanitize_text_field($invoice_state),
            'issue_status' => intval($issue_status),
            'send_cqt_status' => intval($send_cqt_status),
            'contains_under24_main_item' => $contains_under24,
            'under24_main_sku_list_json' => wp_json_encode($under24_list, JSON_UNESCAPED_UNICODE),
            'smart_source_payload' => wp_json_encode($source_payload, JSON_UNESCAPED_UNICODE),
            'smart_filtered_payload' => !empty($filtered_payload) ? wp_json_encode($filtered_payload, JSON_UNESCAPED_UNICODE) : null,
            'error_message' => sanitize_text_field($error_message),
            'created_by' => intval($created_by),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $format = ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s'];
        $ok = $wpdb->insert(TGS_TABLE_LOCAL_VIETTEL_INVOICE, $insert_data, $format);

        if ($ok === false) {
            $fallback = [
                'blog_id' => get_current_blog_id(),
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => $sale_code,
                'request_mode' => 'issue',
                'invoice_state' => sanitize_text_field($invoice_state),
                'error_message' => sanitize_text_field($error_message),
                'created_by' => intval($created_by),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];
            $wpdb->insert(TGS_TABLE_LOCAL_VIETTEL_INVOICE, $fallback, ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        }

        return intval($wpdb->insert_id);
    }

    private function update_auto_flow_tracking($tracking_id, $data)
    {
        if ($tracking_id <= 0 || empty($data) || !defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return;
        }

        global $wpdb;
        $wpdb->update(TGS_TABLE_LOCAL_VIETTEL_INVOICE, $data, ['local_viettel_invoice_id' => intval($tracking_id)]);
    }

    private function extract_transaction_uuid($response)
    {
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $response = $decoded;
            } else {
                return '';
            }
        }

        if (!is_array($response)) {
            return '';
        }

        return $this->deep_pick($response, [
            'result.transactionUuid',
            'result.transactionUUID',
            'result.transactionId',
            'data.transactionUuid',
            'data.transactionUUID',
            'data.transactionId',
            'transactionUuid',
            'transactionUUID',
            'transactionId',
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
