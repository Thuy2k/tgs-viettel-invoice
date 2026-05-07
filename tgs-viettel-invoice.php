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
        add_action('wp_ajax_tgs_viettel_pos_retry_invoice', [$this, 'ajax_pos_retry_invoice']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_retry_invoice', [$this, 'ajax_pos_retry_invoice']);
        add_action('wp_ajax_tgs_viettel_pos_send_invoice_email', [$this, 'ajax_pos_send_invoice_email']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_send_invoice_email', [$this, 'ajax_pos_send_invoice_email']);
        add_action('wp_ajax_tgs_viettel_pos_preview_invoice_pdf', [$this, 'ajax_pos_preview_invoice_pdf']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_preview_invoice_pdf', [$this, 'ajax_pos_preview_invoice_pdf']);
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

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE') || !defined('TGS_TABLE_LOCAL_LEDGER')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng dữ liệu cần thiết.'], 500);
            return;
        }

        global $wpdb;

        $status_filter = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'all';
        $age_filter    = isset($_POST['age_filter']) ? sanitize_text_field(wp_unslash($_POST['age_filter'])) : 'all';
        $date_from     = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '';
        $date_to       = isset($_POST['date_to'])   ? sanitize_text_field(wp_unslash($_POST['date_to']))   : '';

        // Validate YYYY-MM-DD format
        if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
        if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

        $limit = 500;
        $sale_order_type = defined('TGS_LEDGER_TYPE_SALE_ORDER') ? intval(TGS_LEDGER_TYPE_SALE_ORDER) : 10;

        $sql = "SELECT
                    l.local_ledger_id AS sale_ledger_id,
                    l.local_ledger_code,
                    l.local_ledger_item_id,
                    vi.local_viettel_invoice_id,
                    vi.invoice_state,
                    vi.issue_status,
                    vi.send_cqt_status,
                    vi.contains_under24_main_item,
                    vi.issue_transaction_uuid,
                    vi.issue_http_code,
                    vi.cqt_http_code,
                    vi.error_message,
                    vi.template_code,
                    vi.issue_response_payload,
                    COALESCE(vi.updated_at, l.updated_at) AS updated_at,
                    COALESCE(vi.created_at, l.created_at) AS created_at
                FROM " . TGS_TABLE_LOCAL_LEDGER . " l
                LEFT JOIN (
                    SELECT vi1.*
                    FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . " vi1
                    INNER JOIN (
                        SELECT sale_ledger_id, MAX(local_viettel_invoice_id) AS max_id
                        FROM " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . "
                        GROUP BY sale_ledger_id
                    ) vim ON vim.max_id = vi1.local_viettel_invoice_id
                ) vi ON vi.sale_ledger_id = l.local_ledger_id
                WHERE l.local_ledger_type = %d
                  AND (l.is_deleted = 0 OR l.is_deleted IS NULL)";

        $prepare_args = [$sale_order_type];

        if ($date_from) {
            $sql .= " AND l.created_at >= %s";
            $prepare_args[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $sql .= " AND l.created_at <= %s";
            $prepare_args[] = $date_to . ' 23:59:59';
        }

        $sql .= " ORDER BY l.local_ledger_id DESC LIMIT %d";
        $prepare_args[] = $limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $prepare_args), ARRAY_A);

                $under24_flags = $this->compute_under24_main_flags_for_sale_rows($rows);

        $age_counts = [
            'all' => 0,
            'under24' => 0,
            'over24' => 0,
        ];
        $status_counts = [
            'all' => 0,
            'success' => 0,
            'failed' => 0,
            'pending' => 0,
            'unsent' => 0,
        ];

        if (is_array($rows)) {
            foreach ($rows as &$row) {
                $row['invoice_state'] = !empty($row['invoice_state'])
                    ? $row['invoice_state']
                    : 'unsent';
                $row['issue_status'] = intval($row['issue_status'] ?? 0);
                $row['send_cqt_status'] = intval($row['send_cqt_status'] ?? 0);
                $row['issue_http_code'] = intval($row['issue_http_code'] ?? 0);
                $row['cqt_http_code'] = intval($row['cqt_http_code'] ?? 0);
                $row['invoice_no'] = $this->extract_invoice_no_from_issue_payload($row['issue_response_payload'] ?? '');
                $row['template_code'] = sanitize_text_field($row['template_code'] ?? '');
                if ($row['template_code'] === '') {
                    $row['template_code'] = '1/1156';
                }
                $row['contains_under24_main_item'] = !empty($under24_flags[intval($row['sale_ledger_id'] ?? 0)]) ? 1 : 0;
                $row['age_group'] = !empty($row['contains_under24_main_item']) ? 'under24' : 'over24';
                $state = sanitize_text_field($row['invoice_state']);

                $age_counts['all']++;
                if ($row['age_group'] === 'under24') {
                    $age_counts['under24']++;
                } else {
                    $age_counts['over24']++;
                }

                if ($age_filter === 'all' || $age_filter === $row['age_group']) {
                    $status_counts['all']++;
                    if ($state === 'done') {
                        $status_counts['success']++;
                    }
                    if (in_array($state, ['issue_error', 'cqt_error', 'validate_error', 'error'], true)) {
                        $status_counts['failed']++;
                    }
                    if (in_array($state, ['pending', 'issued'], true)) {
                        $status_counts['pending']++;
                    }
                    if ($state === 'unsent' || intval($row['send_cqt_status'] ?? 0) === 0) {
                        $status_counts['unsent']++;
                    }
                }

                $matches_status = true;
                if ($status_filter === 'success') {
                    $matches_status = ($state === 'done');
                } elseif ($status_filter === 'failed') {
                    $matches_status = in_array($state, ['issue_error', 'cqt_error', 'validate_error', 'error'], true);
                } elseif ($status_filter === 'pending') {
                    $matches_status = in_array($state, ['pending', 'issued'], true);
                } elseif ($status_filter === 'unsent') {
                    $matches_status = ($state === 'unsent' || intval($row['send_cqt_status'] ?? 0) === 0);
                }

                $matches_age = true;
                if ($age_filter === 'under24') {
                    $matches_age = !empty($row['contains_under24_main_item']);
                } elseif ($age_filter === 'over24') {
                    $matches_age = empty($row['contains_under24_main_item']);
                }

                $row['_matches_filters'] = $matches_status && $matches_age;
                unset($row['local_ledger_item_id']);
                unset($row['issue_response_payload']);
            }
            unset($row);

            $rows = array_values(array_filter($rows, static function ($row) {
                return !empty($row['_matches_filters']);
            }));

            foreach ($rows as &$row) {
                unset($row['_matches_filters']);
            }
            unset($row);
        }

        wp_send_json_success([
            'items'         => is_array($rows) ? $rows : [],
            'status_filter' => $status_filter,
            'age_filter'    => $age_filter,
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'age_counts'    => $age_counts,
            'status_counts' => $status_counts,
        ]);
    }

    /**
     * POS retry thông minh:
     * - Nếu hóa đơn đã issue thành công (có transaction UUID) => chỉ gửi lại CQT.
     * - Nếu chưa issue được => chạy lại full flow issue + send CQT.
     */
    public function ajax_pos_retry_invoice()
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
            wp_send_json_error(['message' => 'Bạn không có quyền gửi lại hóa đơn.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
            return;
        }

        $sale_ledger_id = intval($_POST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id để gửi lại.'], 400);
            return;
        }

        global $wpdb;
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, issue_status, send_cqt_status, issue_transaction_uuid, resend_count
                 FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . '
                 WHERE sale_ledger_id = %d
                 ORDER BY local_viettel_invoice_id DESC
                 LIMIT 1',
                $sale_ledger_id
            ),
            ARRAY_A
        );

        $invoice_id = intval($latest['local_viettel_invoice_id'] ?? 0);
        $transaction_uuid = sanitize_text_field($latest['issue_transaction_uuid'] ?? '');
        $issue_status = intval($latest['issue_status'] ?? 0);
        $created_by = get_current_user_id();

        if (empty($latest)) {
            $sale_code = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT local_ledger_code FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d LIMIT 1',
                    $sale_ledger_id
                )
            );

            if ($sale_code === '') {
                wp_send_json_error(['message' => 'Không tìm thấy phiếu bán để gửi lại hóa đơn.'], 404);
                return;
            }

            $latest = [
                'local_viettel_invoice_id' => 0,
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => $sale_code,
                'issue_status' => 0,
                'send_cqt_status' => 0,
                'issue_transaction_uuid' => '',
                'resend_count' => 0,
            ];
        }

        if ($invoice_id > 0) {
            $wpdb->update(
                TGS_TABLE_LOCAL_VIETTEL_INVOICE,
                [
                    'resend_count' => intval($latest['resend_count'] ?? 0) + 1,
                    'last_retry_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['local_viettel_invoice_id' => $invoice_id],
                ['%d', '%s', '%s'],
                ['%d']
            );
        }

        // Case 1: Đã issue thành công => chỉ gửi lại CQT, không tạo lại hóa đơn.
        if ($issue_status === 1 && $transaction_uuid !== '') {
            $settings = self::get_settings();
            $cqt_payload_result = $this->flow_service->build_send_cqt_payload(
                sanitize_text_field($settings['supplier_tax_code'] ?? ''),
                $transaction_uuid
            );

            if (empty($cqt_payload_result['success'])) {
                wp_send_json_error([
                    'message' => $cqt_payload_result['message'] ?? 'Không tạo được payload gửi CQT.',
                    'step' => 'build_cqt_payload',
                ], 400);
                return;
            }

            $cqt_payload = $cqt_payload_result['payload'];
            $cqt_payload['local_ledger_code'] = sanitize_text_field($latest['local_ledger_code'] ?? '');

            $cqt_result = $this->submit_invoice_payload($cqt_payload, 'send_cqt', [
                'skip_persist' => true,
                'invoice_record_id' => $invoice_id,
                'step_name' => 'send_cqt_retry',
                'action_name' => 'send_cqt_retry',
                'transaction_uuid' => $transaction_uuid,
                'created_by' => $created_by,
                'sale_ledger_id' => $sale_ledger_id,
            ]);

            if (empty($cqt_result['success'])) {
                $this->update_auto_flow_tracking($invoice_id, [
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
                    'message' => $cqt_result['message'] ?? 'Gửi lại CQT thất bại.',
                    'step' => 'send_cqt_retry',
                    'http_code' => intval($cqt_result['http_code'] ?? 0),
                ], 400);
                return;
            }

            $this->update_auto_flow_tracking($invoice_id, [
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

            wp_send_json_success([
                'message' => 'Đã gửi lại CQT thành công (không tạo lại hóa đơn).',
                'sale_ledger_id' => $sale_ledger_id,
                'mode' => 'send_cqt_only',
                'transaction_uuid' => $transaction_uuid,
            ]);
            return;
        }

        // Case 2: Chưa issue thành công => chạy lại full flow.
        if (!$this->flow_service) {
            wp_send_json_error(['message' => 'Flow service chưa khởi tạo.'], 500);
            return;
        }

        $settings = self::get_settings();
        if (empty($settings['username']) || empty($settings['supplier_tax_code'])) {
            wp_send_json_error(['message' => 'Chưa cấu hình Viettel Invoice. Vui lòng vào Cấu hình để thiết lập.'], 400);
            return;
        }

        $this->run_auto_issue_cqt_flow([
            'sale_ledger_id' => $sale_ledger_id,
            'sale_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
            'employee_id' => $created_by,
        ], $settings);

        wp_send_json_success([
            'message' => 'Đã gửi lại theo full flow do hóa đơn chưa phát hành thành công.',
            'sale_ledger_id' => $sale_ledger_id,
            'mode' => 'full_flow',
        ]);
    }

    /**
     * POS: gửi email file PDF hóa đơn từ danh sách "Thành công".
     */
    public function ajax_pos_send_invoice_email()
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
            wp_send_json_error(['message' => 'Bạn không có quyền gửi email hóa đơn.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
            return;
        }

        $sale_ledger_id = intval($_POST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id.'], 400);
            return;
        }

        $to_email = sanitize_email($_POST['to_email'] ?? 'thuy.nguyenvan2000hn@gmail.com');
        if ($to_email === '') {
            $to_email = 'thuy.nguyenvan2000hn@gmail.com';
        }

        global $wpdb;
        $created_by = get_current_user_id();
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, invoice_state, template_code, issue_response_payload
                 FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . '
                 WHERE sale_ledger_id = %d
                 ORDER BY local_viettel_invoice_id DESC
                 LIMIT 1',
                $sale_ledger_id
            ),
            ARRAY_A
        );

        if (empty($latest)) {
            wp_send_json_error(['message' => 'Không tìm thấy hóa đơn Viettel của đơn này.'], 404);
            return;
        }

        if (sanitize_text_field($latest['invoice_state'] ?? '') !== 'done') {
            wp_send_json_error(['message' => 'Chỉ gửi email cho hóa đơn đã gửi CQT thành công.'], 400);
            return;
        }

        $invoice_no = $this->extract_invoice_no_from_issue_payload($latest['issue_response_payload'] ?? '');
        if ($invoice_no === '') {
            wp_send_json_error(['message' => 'Không lấy được invoiceNo để tải file PDF.'], 400);
            return;
        }

        $template_code = '1/1156';

        $settings = self::get_settings();
        $supplier_tax_code = sanitize_text_field($settings['supplier_tax_code'] ?? '');
        if ($supplier_tax_code === '') {
            wp_send_json_error(['message' => 'Thiếu MST nhà cung cấp trong cấu hình Viettel.'], 400);
            return;
        }

        $pdf_result = $this->fetch_invoice_representation_file(
            $settings,
            $supplier_tax_code,
            $invoice_no,
            $template_code,
            'PDF'
        );

        $pdf_request_payload = [
            'supplierTaxCode' => $supplier_tax_code,
            'invoiceNo' => $invoice_no,
            'templateCode' => $template_code,
            'fileType' => 'PDF',
        ];
        $pdf_endpoint = untrailingslashit($settings['api_base_url'] ?? '') . '/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile';

        if (empty($pdf_result['success'])) {
            $this->insert_log_record([
                'invoice_id' => intval($latest['local_viettel_invoice_id'] ?? 0),
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
                'step_name' => 'send_invoice_email',
                'action_name' => 'send_invoice_email',
                'endpoint' => $pdf_endpoint,
                'request_payload' => wp_json_encode($pdf_request_payload, JSON_UNESCAPED_UNICODE),
                'response_payload' => (string) ($pdf_result['response_text'] ?? ''),
                'http_code' => intval($pdf_result['http_code'] ?? 0),
                'error_message' => sanitize_text_field($pdf_result['message'] ?? 'Không lấy được file PDF hóa đơn.'),
                'created_by' => $created_by,
            ]);

            wp_send_json_error([
                'message' => $pdf_result['message'] ?? 'Không lấy được file PDF hóa đơn.',
                'http_code' => intval($pdf_result['http_code'] ?? 0),
            ], 400);
            return;
        }

        $file_bytes = (string) ($pdf_result['file_bytes_base64'] ?? '');
        if ($file_bytes === '') {
            wp_send_json_error(['message' => 'API trả về thiếu fileToBytes.'], 400);
            return;
        }

        $binary = base64_decode($file_bytes, true);
        if ($binary === false || $binary === '') {
            wp_send_json_error(['message' => 'Không giải mã được file PDF từ API Viettel.'], 400);
            return;
        }

        $safe_invoice_no = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice_no);
        $pdf_file_name = $safe_invoice_no . '.pdf';
        $temp_file = $this->create_invoice_email_attachment_temp_file($pdf_file_name, $binary);
        if (empty($temp_file)) {
            wp_send_json_error(['message' => 'Không tạo được file đính kèm PDF tạm.'], 500);
            return;
        }

        $attachments = [$temp_file];
        $xml_file_name = '';
        $xml_result = $this->fetch_invoice_representation_file(
            $settings,
            $supplier_tax_code,
            $invoice_no,
            $template_code,
            'XML'
        );
        if (!empty($xml_result['success'])) {
            $xml_file_bytes = (string) ($xml_result['file_bytes_base64'] ?? '');
            $xml_binary = base64_decode($xml_file_bytes, true);
            if ($xml_binary !== false && $xml_binary !== '') {
                $xml_file_name = $safe_invoice_no . '.xml';
                $temp_xml_file = $this->create_invoice_email_attachment_temp_file($xml_file_name, $xml_binary);
                if (!empty($temp_xml_file)) {
                    $attachments[] = $temp_xml_file;
                }
            }
        }

        $sale_code = sanitize_text_field($latest['local_ledger_code'] ?? ('Sale #' . $sale_ledger_id));
        $issued_at = current_time('d/m/Y H:i:s');
        $invoice_search_url = 'https://vinvoice.viettel.vn/utilities/invoice-search';
        $quick_view_url = $invoice_search_url;

        $subject = 'Hóa đơn điện tử ' . $invoice_no;
        $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:#222;">'
            . '<p>Kính gửi Quý Công Ty/Khách hàng,</p>'
            . '<p>Chúng tôi xin gửi Quý khách hàng hóa đơn điện tử số <strong>' . esc_html($invoice_no) . '</strong>'
            . ' của đơn <strong>' . esc_html($sale_code) . '</strong>'
            . ' lập ngày <strong>' . esc_html($issued_at) . '</strong>'
            . ' mã số thuế bên bán <strong>' . esc_html($supplier_tax_code) . '</strong>.</p>'
            . '<p>Hóa đơn điện tử của Quý khách được gửi qua mail theo file kèm theo.</p>'
            . '<p>Quý khách có thể tra cứu lại hóa đơn điện tử tại '
            . '<a href="' . esc_url($invoice_search_url) . '">' . esc_html($invoice_search_url) . '</a>'
            . ' hoặc truy cập nhanh <a href="' . esc_url($quick_view_url) . '">tại đây</a> để tải và xem hóa đơn.</p>'
            . '<p>Xin trân trọng cảm ơn Quý khách đã sử dụng sản phẩm/dịch vụ của chúng tôi.</p>'
            . '</div>';

        $GLOBALS['tgs_resend_last_error'] = null;
        $wp_mail_failed_error = '';
        $wp_mail_failed_handler = static function ($wp_error) use (&$wp_mail_failed_error) {
            if (is_wp_error($wp_error)) {
                $wp_mail_failed_error = $wp_error->get_error_message();
            }
        };
        add_action('wp_mail_failed', $wp_mail_failed_handler, 10, 1);

        $mail_sent = wp_mail($to_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], $attachments);

        remove_action('wp_mail_failed', $wp_mail_failed_handler, 10);
        // Không xóa ngay file đính kèm: một số mail transport gửi async/queue
        // sẽ đọc file sau khi wp_mail() trả về. Cleanup theo tuổi sẽ chạy ở helper.
        $this->cleanup_old_invoice_email_attachments(2);

        if (!$mail_sent) {
            global $phpmailer;
            $mail_error_message = '';
            if (!empty($GLOBALS['tgs_resend_last_error'])) {
                $mail_error_message = (string) $GLOBALS['tgs_resend_last_error'];
            } elseif ($wp_mail_failed_error !== '') {
                $mail_error_message = $wp_mail_failed_error;
            } elseif (isset($phpmailer) && is_object($phpmailer) && !empty($phpmailer->ErrorInfo)) {
                $mail_error_message = (string) $phpmailer->ErrorInfo;
            }
            if ($mail_error_message === '') {
                $mail_error_message = 'wp_mail returned false';
            }

            $this->insert_log_record([
                'invoice_id' => intval($latest['local_viettel_invoice_id'] ?? 0),
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
                'step_name' => 'send_invoice_email',
                'action_name' => 'send_invoice_email',
                'endpoint' => 'wp_mail',
                'request_payload' => wp_json_encode([
                    'to_email' => $to_email,
                    'subject' => $subject,
                    'invoice_no' => $invoice_no,
                    'file_name' => $pdf_file_name,
                ], JSON_UNESCAPED_UNICODE),
                'response_payload' => wp_json_encode([
                    'mail_sent' => false,
                    'pdf_http_code' => intval($pdf_result['http_code'] ?? 0),
                    'xml_http_code' => intval($xml_result['http_code'] ?? 0),
                    'mail_error' => $mail_error_message,
                ], JSON_UNESCAPED_UNICODE),
                'http_code' => 0,
                'error_message' => sanitize_text_field($mail_error_message),
                'created_by' => $created_by,
            ]);

            wp_send_json_error(['message' => $mail_error_message], 500);
            return;
        }

        $this->insert_log_record([
            'invoice_id' => intval($latest['local_viettel_invoice_id'] ?? 0),
            'sale_ledger_id' => $sale_ledger_id,
            'local_ledger_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
            'step_name' => 'send_invoice_email',
            'action_name' => 'send_invoice_email',
            'endpoint' => 'wp_mail',
            'request_payload' => wp_json_encode([
                'to_email' => $to_email,
                'subject' => $subject,
                'invoice_no' => $invoice_no,
                'file_name' => $pdf_file_name,
                'xml_file_name' => $xml_file_name,
                'pdf_request' => $pdf_request_payload,
            ], JSON_UNESCAPED_UNICODE),
            'response_payload' => wp_json_encode([
                'mail_sent' => true,
                'pdf_http_code' => intval($pdf_result['http_code'] ?? 0),
                'xml_http_code' => intval($xml_result['http_code'] ?? 0),
                'pdf_file_name' => $pdf_file_name,
                'xml_file_name' => $xml_file_name,
                'pdf_api_response' => $pdf_result['response'] ?? null,
                'xml_api_response' => $xml_result['response'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'http_code' => intval($pdf_result['http_code'] ?? 0),
            'error_message' => '',
            'created_by' => $created_by,
        ]);

        wp_send_json_success([
            'message' => 'Đã gửi email hóa đơn thành công tới ' . $to_email,
            'to_email' => $to_email,
            'invoice_no' => $invoice_no,
            'sale_ledger_id' => $sale_ledger_id,
            'file_name' => $pdf_file_name,
            'xml_file_name' => $xml_file_name,
            'api_http_code' => intval($pdf_result['http_code'] ?? 0),
        ]);
    }

    /**
     * POS: xem trực tiếp PDF hóa đơn trong trình duyệt.
     */
    public function ajax_pos_preview_invoice_pdf()
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
            wp_send_json_error(['message' => 'Bạn không có quyền xem PDF hóa đơn.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng theo dõi hóa đơn Viettel.'], 500);
            return;
        }

        $sale_ledger_id = intval($_POST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id.'], 400);
            return;
        }

        global $wpdb;
        $created_by = get_current_user_id();
        $latest = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, invoice_state, template_code, issue_response_payload
                 FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . '
                 WHERE sale_ledger_id = %d
                 ORDER BY local_viettel_invoice_id DESC
                 LIMIT 1',
                $sale_ledger_id
            ),
            ARRAY_A
        );

        if (empty($latest)) {
            wp_send_json_error(['message' => 'Không tìm thấy hóa đơn Viettel của đơn này.'], 404);
            return;
        }

        if (sanitize_text_field($latest['invoice_state'] ?? '') !== 'done') {
            wp_send_json_error(['message' => 'Chỉ xem PDF cho hóa đơn đã gửi CQT thành công.'], 400);
            return;
        }

        $invoice_no = $this->extract_invoice_no_from_issue_payload($latest['issue_response_payload'] ?? '');
        if ($invoice_no === '') {
            wp_send_json_error(['message' => 'Không lấy được invoiceNo để tải file PDF.'], 400);
            return;
        }

        $template_code = '1/1156';
        $settings = self::get_settings();
        $supplier_tax_code = sanitize_text_field($settings['supplier_tax_code'] ?? '');
        if ($supplier_tax_code === '') {
            wp_send_json_error(['message' => 'Thiếu MST nhà cung cấp trong cấu hình Viettel.'], 400);
            return;
        }

        $pdf_result = $this->fetch_invoice_representation_file(
            $settings,
            $supplier_tax_code,
            $invoice_no,
            $template_code,
            'PDF'
        );

        $pdf_request_payload = [
            'supplierTaxCode' => $supplier_tax_code,
            'invoiceNo' => $invoice_no,
            'templateCode' => $template_code,
            'fileType' => 'PDF',
        ];
        $pdf_endpoint = untrailingslashit($settings['api_base_url'] ?? '') . '/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile';

        if (empty($pdf_result['success'])) {
            $this->insert_log_record([
                'invoice_id' => intval($latest['local_viettel_invoice_id'] ?? 0),
                'sale_ledger_id' => $sale_ledger_id,
                'local_ledger_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
                'step_name' => 'preview_invoice_pdf',
                'action_name' => 'preview_invoice_pdf',
                'endpoint' => $pdf_endpoint,
                'request_payload' => wp_json_encode($pdf_request_payload, JSON_UNESCAPED_UNICODE),
                'response_payload' => (string) ($pdf_result['response_text'] ?? ''),
                'http_code' => intval($pdf_result['http_code'] ?? 0),
                'error_message' => sanitize_text_field($pdf_result['message'] ?? 'Không lấy được file PDF hóa đơn.'),
                'created_by' => $created_by,
            ]);

            wp_send_json_error([
                'message' => $pdf_result['message'] ?? 'Không lấy được file PDF hóa đơn.',
                'http_code' => intval($pdf_result['http_code'] ?? 0),
            ], 400);
            return;
        }

        $file_bytes = (string) ($pdf_result['file_bytes_base64'] ?? '');
        if ($file_bytes === '') {
            wp_send_json_error(['message' => 'API trả về thiếu fileToBytes.'], 400);
            return;
        }

        $safe_invoice_no = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice_no);
        $file_name = $supplier_tax_code . '-' . $safe_invoice_no . '.pdf';

        $this->insert_log_record([
            'invoice_id' => intval($latest['local_viettel_invoice_id'] ?? 0),
            'sale_ledger_id' => $sale_ledger_id,
            'local_ledger_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
            'step_name' => 'preview_invoice_pdf',
            'action_name' => 'preview_invoice_pdf',
            'endpoint' => $pdf_endpoint,
            'request_payload' => wp_json_encode($pdf_request_payload, JSON_UNESCAPED_UNICODE),
            'response_payload' => wp_json_encode([
                'http_code' => intval($pdf_result['http_code'] ?? 0),
                'file_name' => $file_name,
                'file_size_base64' => strlen($file_bytes),
                'api_response' => $pdf_result['response'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'http_code' => intval($pdf_result['http_code'] ?? 0),
            'error_message' => '',
            'created_by' => $created_by,
        ]);

        wp_send_json_success([
            'message' => 'Đã lấy file PDF hóa đơn thành công.',
            'sale_ledger_id' => $sale_ledger_id,
            'invoice_no' => $invoice_no,
            'file_name' => $file_name,
            'mime_type' => 'application/pdf',
            'file_bytes_base64' => $file_bytes,
            'api_http_code' => intval($pdf_result['http_code'] ?? 0),
        ]);
    }

    private function fetch_invoice_representation_file(array $settings, $supplier_tax_code, $invoice_no, $template_code, $file_type = 'PDF')
    {
        $base = untrailingslashit($settings['api_base_url'] ?? '');
        if ($base === '') {
            return [
                'success' => false,
                'message' => 'Thiếu api_base_url trong cấu hình Viettel.',
            ];
        }

        $url = $base . '/InvoiceAPI/InvoiceUtilsWS/getInvoiceRepresentationFile';
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if (($settings['auth_mode'] ?? 'basic') === 'token') {
            $headers['Authorization'] = 'Bearer ' . ($settings['access_token'] ?? '');
        } else {
            $token = base64_encode(($settings['username'] ?? '') . ':' . ($settings['password'] ?? ''));
            $headers['Authorization'] = 'Basic ' . $token;
        }

        $payload = [
            'supplierTaxCode' => (string) $supplier_tax_code,
            'invoiceNo' => (string) $invoice_no,
            'templateCode' => (string) $template_code,
            'fileType' => (string) $file_type,
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 60,
            'sslverify' => !empty($settings['verify_ssl']),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $http_code = intval(wp_remote_retrieve_response_code($response));
        $response_text = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($response_text, true);

        if ($http_code < 200 || $http_code >= 300 || !is_array($decoded)) {
            return [
                'success' => false,
                'message' => 'Lấy file hóa đơn thất bại (HTTP ' . $http_code . ').',
                'http_code' => $http_code,
                'response_text' => $response_text,
            ];
        }

        $file_bytes = (string) ($decoded['fileToBytes'] ?? '');
        if ($file_bytes === '') {
            return [
                'success' => false,
                'message' => sanitize_text_field($decoded['description'] ?? $decoded['message'] ?? 'API không trả về fileToBytes.'),
                'http_code' => $http_code,
                'response_text' => $response_text,
            ];
        }

        return [
            'success' => true,
            'http_code' => $http_code,
            'file_bytes_base64' => $file_bytes,
            'response' => $decoded,
        ];
    }

    private function create_invoice_email_attachment_temp_file($file_name, $binary_content)
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return '';
        }

        $dir = trailingslashit($uploads['basedir']) . 'tgs-invoice-email-attachments';
        if (!wp_mkdir_p($dir)) {
            return '';
        }

        $safe_name = sanitize_file_name((string) $file_name);
        if ($safe_name === '') {
            $safe_name = 'invoice-attachment.bin';
        }

        $path = trailingslashit($dir) . time() . '-' . wp_generate_password(6, false, false) . '-' . $safe_name;
        $written = file_put_contents($path, $binary_content);
        if ($written === false) {
            return '';
        }

        return $path;
    }

    private function cleanup_old_invoice_email_attachments($max_age_days = 2)
    {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return;
        }

        $dir = trailingslashit($uploads['basedir']) . 'tgs-invoice-email-attachments';
        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - (max(1, intval($max_age_days)) * DAY_IN_SECONDS);
        $files = glob(trailingslashit($dir) . '*');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
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

        // send_cqt endpoint dùng form-urlencoded theo tài liệu thực tế Viettel
        if ($mode === 'send_cqt') {
            $start_date = sanitize_text_field($payload['startDate'] ?? '');
            if ($start_date === '') {
                $start_date = current_time('Y-m-d');
            }

            $form_params = [
                'supplierTaxCode'  => $payload['supplierTaxCode'] ?? '',
                'transactionUuid'  => $payload['transactionUuid'] ?? '',
                'startDate'        => $start_date,
                'endDate'          => sanitize_text_field($payload['endDate'] ?? $start_date),
            ];
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $request_body = http_build_query($form_params);
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body'    => $request_body,
                'timeout' => 45,
                'sslverify' => !empty($settings['verify_ssl']),
            ]);
        } else {
            $request_body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
            $response = wp_remote_post($url, [
                'headers' => $headers,
                'body'    => $request_body,
                'timeout' => 45,
                'sslverify' => !empty($settings['verify_ssl']),
            ]);
        }

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
            $required = ['supplierTaxCode', 'transactionUuid', 'startDate'];
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

    private function extract_invoice_no_from_issue_payload($payload)
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return '';
        }

        return sanitize_text_field($this->deep_pick($payload, [
            'result.invoiceNo',
            'data.invoiceNo',
            'invoiceNo',
        ]));
    }

    private function compute_under24_main_flags_for_sale_rows($rows)
    {
        if (
            !is_array($rows)
            || empty($rows)
            || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')
            || !defined('TGS_TABLE_LOCAL_PRODUCT_NAME')
            || !defined('TGS_TABLE_GLOBAL_MILK_UNDER24M')
        ) {
            return [];
        }

        global $wpdb;

        $sale_item_map = [];
        $all_item_ids = [];

        foreach ($rows as $row) {
            $sale_ledger_id = intval($row['sale_ledger_id'] ?? 0);
            $item_ids_json = $row['local_ledger_item_id'] ?? '';
            $item_ids = is_string($item_ids_json) ? json_decode($item_ids_json, true) : [];
            $item_ids = is_array($item_ids) ? array_values(array_filter(array_map('intval', $item_ids))) : [];
            if ($sale_ledger_id <= 0 || empty($item_ids)) {
                continue;
            }

            $sale_item_map[$sale_ledger_id] = $item_ids;
            $all_item_ids = array_merge($all_item_ids, $item_ids);
        }

        $all_item_ids = array_values(array_unique(array_filter(array_map('intval', $all_item_ids))));
        if (empty($all_item_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($all_item_ids), '%d'));
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.local_ledger_item_id, i.local_ledger_item_gift_type, p.local_product_sku
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' i
                 LEFT JOIN ' . TGS_TABLE_LOCAL_PRODUCT_NAME . ' p ON p.local_product_name_id = i.local_product_name_id
                 WHERE i.local_ledger_item_id IN (' . $placeholders . ')',
                ...$all_item_ids
            ),
            ARRAY_A
        );

        if (empty($items)) {
            return [];
        }

        $item_info_map = [];
        $main_skus = [];
        foreach ($items as $item) {
            $item_id = intval($item['local_ledger_item_id'] ?? 0);
            $is_gift = intval($item['local_ledger_item_gift_type'] ?? 0) === 1;
            $sku = sanitize_text_field($item['local_product_sku'] ?? '');
            $item_info_map[$item_id] = [
                'is_gift' => $is_gift,
                'sku' => $sku,
            ];
            if (!$is_gift && $sku !== '') {
                $main_skus[] = $sku;
            }
        }

        $main_skus = array_values(array_unique($main_skus));
        if (empty($main_skus)) {
            return [];
        }

        $sku_placeholders = implode(',', array_fill(0, count($main_skus), '%s'));
        $under24_rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT global_product_sku
                 FROM ' . TGS_TABLE_GLOBAL_MILK_UNDER24M . '
                 WHERE global_product_sku IN (' . $sku_placeholders . ')
                   AND (is_deleted = 0 OR is_deleted IS NULL)',
                ...$main_skus
            )
        );
        $under24_sku_map = array_fill_keys(array_map('strval', $under24_rows ?: []), true);

        $sale_flags = [];
        foreach ($sale_item_map as $sale_ledger_id => $item_ids) {
            $sale_flags[$sale_ledger_id] = false;
            foreach ($item_ids as $item_id) {
                $item_info = $item_info_map[$item_id] ?? null;
                if (!$item_info || !empty($item_info['is_gift'])) {
                    continue;
                }

                if (!empty($under24_sku_map[$item_info['sku'] ?? ''])) {
                    $sale_flags[$sale_ledger_id] = true;
                    break;
                }
            }
        }

        return $sale_flags;
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
            'result.transactionID',
            'result.transactionUuid',
            'result.transactionUUID',
            'result.transactionId',
            'result.invoiceNo',
            'data.transactionID',
            'data.transactionUuid',
            'data.transactionUUID',
            'data.transactionId',
            'data.invoiceNo',
            'transactionID',
            'transactionUuid',
            'transactionUUID',
            'transactionId',
            'invoiceNo',
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
