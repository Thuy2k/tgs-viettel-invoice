<?php
/**
 * Plugin Name: TGS Viettel Invoice
 * Plugin URI:  https://thegioisua.vn
 * Description: Tạo hóa đơn nháp/phát hành trên Viettel VInvoice, tích hợp vào TGS Shop Management.
 * Version:     1.1.6
 * Author:      TGS Team
 * Text Domain: tgs-viettel-invoice
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_VIETTEL_INVOICE_VERSION', '1.1.6');
define('TGS_VIETTEL_INVOICE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_VIETTEL_INVOICE_PLUGIN_URL', plugin_dir_url(__FILE__));

$tgs_viettel_global_products_file = TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-invoice-global-products.php';
if (file_exists($tgs_viettel_global_products_file)) {
    require_once $tgs_viettel_global_products_file;
}

$tgs_viettel_flow_service_file = TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-invoice-flow-service.php';
if (file_exists($tgs_viettel_flow_service_file)) {
    require_once $tgs_viettel_flow_service_file;
}

$tgs_viettel_clusters_file = TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-invoice-clusters.php';
if (file_exists($tgs_viettel_clusters_file)) {
    require_once $tgs_viettel_clusters_file;
}

$tgs_viettel_return_adjustment_file = TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-invoice-return-adjustment.php';
if (file_exists($tgs_viettel_return_adjustment_file)) {
    require_once $tgs_viettel_return_adjustment_file;
}

class TGS_Viettel_Invoice_Plugin
{
    const OPTION_SETTINGS = 'tgs_viettel_invoice_settings';
    const OPTION_COMMON_SETTINGS = 'tgs_viettel_invoice_common_settings';
    const MIGRATION_VERSION = '1.0.1';

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
        if (class_exists('TGS_Viettel_Invoice_Clusters')) {
            TGS_Viettel_Invoice_Clusters::instance();
        }
        if (class_exists('TGS_Viettel_Invoice_Flow_Service')) {
            $this->flow_service = new TGS_Viettel_Invoice_Flow_Service();
        }
        if (class_exists('TGS_Viettel_Invoice_Return_Adjustment')) {
            TGS_Viettel_Invoice_Return_Adjustment::instance($this);
        }

        add_filter('tgs_shop_dashboard_routes', [$this, 'register_routes']);
        add_action('tgs_shop_system_menu', [$this, 'render_system_menu'], 25, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_tgs_viettel_invoice_save_common_settings', [$this, 'ajax_save_common_settings']);
        add_action('wp_ajax_tgs_viettel_invoice_save_shop_settings', [$this, 'ajax_save_shop_settings']);
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
        add_action('wp_ajax_nopriv_tgs_viettel_pos_list_statuses', [$this, 'ajax_pos_list_statuses']);
        add_action('wp_ajax_tgs_viettel_pos_debug_statuses_context', [$this, 'ajax_pos_debug_statuses_context']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_debug_statuses_context', [$this, 'ajax_pos_debug_statuses_context']);
        add_action('wp_ajax_tgs_viettel_pos_get_items_for_review', [$this, 'ajax_pos_get_items_for_review']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_get_items_for_review', [$this, 'ajax_pos_get_items_for_review']);
        add_action('wp_ajax_tgs_viettel_pos_update_danger_flags', [$this, 'ajax_pos_update_danger_flags']);
        add_action('wp_ajax_nopriv_tgs_viettel_pos_update_danger_flags', [$this, 'ajax_pos_update_danger_flags']);


        /*
         * Nút "Gửi lên cục thuế" trên popup in bill POS — lối gửi BẰNG TAY cho
         * thu ngân. Ưu tiên 20 để nút xếp sau nút Xuất HĐĐT.
         *
         * ⚠️ Đang chạy SONG SONG với luồng tự động (hook tgs_sale_completed bên
         * dưới). Đơn đã tự động phát hành mà bấm nút này nữa là dễ GỬI TRÙNG —
         * xem lại flow_service có chặn đơn đã có hoá đơn hay không trước khi
         * giao cho thu ngân dùng hằng ngày.
         */
        add_action('tgs_pos_receipt_footer_buttons', [$this, 'render_cqt_receipt_button'], 20);

        /*
         * Tự động phát hành hoá đơn lên cơ quan thuế ngay khi POS chốt đơn.
         *
         * ⚠️ CÓ HAI CÔNG TẮC, hook này chỉ là công tắc thứ nhất. Bên trong
         * handle_sale_completed() còn kiểm `auto_enabled` trong cài đặt — tắt
         * cái đó thì hook chạy nhưng không gửi gì. Muốn gửi thật phải bật cả hai.
         *
         * Gửi hoá đơn lên cơ quan thuế là việc KHÔNG HOÀN TÁC được: sai thì
         * phải làm hoá đơn điều chỉnh/thay thế, không xoá đi được.
         */
        add_action('tgs_sale_completed', [$this, 'handle_sale_completed'], 20, 1);

        if (is_admin() && !wp_doing_ajax()) {
            add_action('admin_init', [$this, 'run_migration']);
        }
    }

    private function current_user_can_use_pos()
    {
        if (!class_exists('TGS_POS_Permission')) {
            $permission_file = WP_PLUGIN_DIR . '/tgs_pos/includes/class-tgs-pos-permission.php';
            if (file_exists($permission_file)) {
                require_once $permission_file;
            }
        }

        if (class_exists('TGS_POS_Permission')) {
            return TGS_POS_Permission::current_user_can_use_pos();
        }

        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();

        if ($user_id > 0 && $blog_id > 0 && class_exists('TGS_POS_Site_Permission')) {
            $manager = TGS_POS_Site_Permission::get_instance();
            if ($manager && method_exists($manager, 'is_permission_enabled') && method_exists($manager, 'user_can_access_pos_site')) {
                if (!$manager->is_permission_enabled($blog_id)) {
                    return true;
                }

                if ($manager->user_can_access_pos_site($user_id, $blog_id)) {
                    return true;
                }
            }
        }

        return current_user_can('read') || current_user_can('edit_posts') || current_user_can('manage_options');
    }

    public function bootstrap_requested_blog_context()
    {
        if (!is_multisite()) {
            return;
        }

        $requested_blog_id = intval($_REQUEST['blog_id'] ?? 0);
        if ($requested_blog_id <= 0 || $requested_blog_id === get_current_blog_id()) {
            return;
        }

        if (get_blog_details($requested_blog_id)) {
            switch_to_blog($requested_blog_id);
        }
    }

    private function get_pos_statuses_debug_context()
    {
        global $wpdb;

        $user = wp_get_current_user();
        $user_id = $user instanceof WP_User ? (int) $user->ID : 0;
        $blog_id = get_current_blog_id();
        $caps_meta_key = $wpdb->get_blog_prefix($blog_id) . 'capabilities';
        $caps_meta = $user_id > 0 ? get_user_meta($user_id, $caps_meta_key, true) : [];
        $permission_file = WP_PLUGIN_DIR . '/tgs_pos/includes/class-tgs-pos-permission.php';
        $site_permission_enabled = null;
        $site_permission_result = null;

        if (class_exists('TGS_POS_Site_Permission')) {
            $manager = TGS_POS_Site_Permission::get_instance();
            if ($manager && method_exists($manager, 'is_permission_enabled')) {
                $site_permission_enabled = $manager->is_permission_enabled($blog_id) ? 1 : 0;
            }
            if ($manager && method_exists($manager, 'user_can_access_pos_site')) {
                $site_permission_result = $manager->user_can_access_pos_site($user_id, $blog_id) ? 1 : 0;
            }
        }

        return [
            'time' => current_time('mysql'),
            'request' => [
                'action' => sanitize_text_field($_REQUEST['action'] ?? ''),
                'requested_blog_id' => intval($_REQUEST['blog_id'] ?? 0),
                'date_from' => sanitize_text_field($_REQUEST['date_from'] ?? ''),
                'date_to' => sanitize_text_field($_REQUEST['date_to'] ?? ''),
                'status' => sanitize_text_field($_REQUEST['status'] ?? ''),
                'age_filter' => sanitize_text_field($_REQUEST['age_filter'] ?? ''),
            ],
            'site' => [
                'is_multisite' => is_multisite() ? 1 : 0,
                'current_blog_id' => $blog_id,
                'caps_meta_key' => $caps_meta_key,
            ],
            'user' => [
                'id' => $user_id,
                'login' => $user instanceof WP_User ? (string) $user->user_login : '',
                'roles' => $user instanceof WP_User && is_array($user->roles) ? array_values($user->roles) : [],
                'caps_meta' => is_array($caps_meta) ? $caps_meta : [],
                'is_logged_in' => is_user_logged_in() ? 1 : 0,
                'can_read' => current_user_can('read') ? 1 : 0,
                'can_edit_posts' => current_user_can('edit_posts') ? 1 : 0,
                'can_manage_options' => current_user_can('manage_options') ? 1 : 0,
                'pos_permission_class_loaded' => class_exists('TGS_POS_Permission') ? 1 : 0,
                'pos_permission_file_exists' => file_exists($permission_file) ? 1 : 0,
                'current_user_can_use_pos' => $this->current_user_can_use_pos() ? 1 : 0,
                'site_permission_enabled' => $site_permission_enabled,
                'site_permission_result' => $site_permission_result,
            ],
            'constants' => [
                'invoice_table_defined' => defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE') ? 1 : 0,
                'ledger_table_defined' => defined('TGS_TABLE_LOCAL_LEDGER') ? 1 : 0,
                'invoice_table' => defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE') ? TGS_TABLE_LOCAL_VIETTEL_INVOICE : '',
                'ledger_table' => defined('TGS_TABLE_LOCAL_LEDGER') ? TGS_TABLE_LOCAL_LEDGER : '',
            ],
        ];
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
            // [ẨN MENU - 2026-06-02] Hướng dẫn luồng - không dùng nữa
            // 'viettel-invoice-guide' => ['bx bx-book-content text-info me-1', 'Hướng dẫn luồng'],
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

        if ($view === 'viettel-invoice-settings') {
            wp_enqueue_style(
                'tgs-viettel-invoice-clusters',
                TGS_VIETTEL_INVOICE_PLUGIN_URL . 'assets/css/clusters.css',
                [],
                TGS_VIETTEL_INVOICE_VERSION
            );
            wp_enqueue_script(
                'tgs-viettel-invoice-clusters',
                TGS_VIETTEL_INVOICE_PLUGIN_URL . 'assets/js/clusters.js',
                ['jquery', 'tgs-viettel-invoice-admin'],
                TGS_VIETTEL_INVOICE_VERSION,
                true
            );
        }

        wp_localize_script('tgs-viettel-invoice-admin', 'tgsViettelInvoice', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tgs_viettel_invoice_nonce'),
            'isSuperAdmin' => is_super_admin() ? '1' : '0',
            'isMultisite' => is_multisite() ? '1' : '0',
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
            'default_template_code' => '1/770',
            'default_invoice_series' => 'K23TXM',
            'default_payment_method' => 'TM/CK',
            'verify_ssl' => 1,
            'auto_enabled' => 0,
            'auto_mode' => 'issue',
        ];
    }

    public static function get_common_fields()
    {
        return [
            'api_base_url', 'auth_mode', 'username', 'password',
            'access_token', 'verify_ssl',
            'default_template_code', 'default_invoice_series', 'default_payment_method',
            'auto_enabled', 'auto_mode',
        ];
    }

    public static function get_shop_fields()
    {
        return [
            'company_name', 'supplier_tax_code', 'company_address', 'company_phone',
        ];
    }

    public static function get_common_settings()
    {
        if (is_multisite()) {
            $common = get_site_option(self::OPTION_COMMON_SETTINGS, []);
        } else {
            $common = get_option(self::OPTION_COMMON_SETTINGS, []);
        }
        if (!is_array($common)) {
            $common = [];
        }
        return $common;
    }

    public static function get_shop_settings()
    {
        $shop = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($shop)) {
            $shop = [];
        }
        return $shop;
    }

    public static function get_settings($blog_id = null)
    {
        $legacy = array_merge(
            self::get_default_settings(),
            self::get_common_settings(),
            self::get_shop_settings()
        );

        if (class_exists('TGS_Viettel_Invoice_Clusters')) {
            return TGS_Viettel_Invoice_Clusters::instance()->resolve(
                $blog_id === null ? get_current_blog_id() : (int) $blog_id,
                $legacy
            );
        }
        return $legacy;
    }

    public static function get_settings_for_invoice($invoice_record_id, $blog_id = null)
    {
        $blog_id = $blog_id === null ? get_current_blog_id() : (int) $blog_id;
        if (class_exists('TGS_Viettel_Invoice_Clusters') && $invoice_record_id > 0) {
            $snapshot = TGS_Viettel_Invoice_Clusters::instance()->get_snapshot_settings($blog_id, (int) $invoice_record_id);
            if (!empty($snapshot)) {
                return $snapshot;
            }
        }
        return self::get_settings($blog_id);
    }

    private function sanitize_common_settings($data)
    {
        $current = self::get_common_settings();

        $sanitized = [
            'api_base_url' => esc_url_raw(trim($data['api_base_url'] ?? '')),
            'auth_mode' => in_array(($data['auth_mode'] ?? ''), ['basic', 'token'], true) ? $data['auth_mode'] : 'basic',
            'username' => sanitize_text_field($data['username'] ?? ''),
            'password' => isset($data['password']) ? (string) $data['password'] : '',
            'access_token' => isset($data['access_token']) ? (string) $data['access_token'] : '',
            'verify_ssl' => !empty($data['verify_ssl']) ? 1 : 0,
            'default_template_code' => sanitize_text_field($data['default_template_code'] ?? ''),
            'default_invoice_series' => sanitize_text_field($data['default_invoice_series'] ?? ''),
            'default_payment_method' => sanitize_text_field($data['default_payment_method'] ?? ''),
            'auto_enabled' => !empty($data['auto_enabled']) ? 1 : 0,
            'auto_mode' => in_array(($data['auto_mode'] ?? ''), ['draft', 'issue'], true) ? $data['auto_mode'] : 'issue',
        ];

        if ($sanitized['password'] === '********') {
            $sanitized['password'] = $current['password'] ?? '';
        }

        if ($sanitized['access_token'] === '********') {
            $sanitized['access_token'] = $current['access_token'] ?? '';
        }

        if (empty($sanitized['api_base_url'])) {
            $sanitized['api_base_url'] = $current['api_base_url'] ?? '';
        }

        return $sanitized;
    }

    private function sanitize_shop_settings($data)
    {
        $sanitized = [
            'company_name' => sanitize_text_field($data['company_name'] ?? ''),
            'supplier_tax_code' => sanitize_text_field($data['supplier_tax_code'] ?? ''),
            'company_address' => sanitize_text_field($data['company_address'] ?? ''),
            'company_phone' => sanitize_text_field($data['company_phone'] ?? ''),
        ];

        return $sanitized;
    }

    public function ajax_save_common_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!is_super_admin()) {
            wp_send_json_error(['message' => 'Chỉ Super Admin mới có quyền này.'], 403);
        }

        $raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Dữ liệu không hợp lệ.'], 400);
        }

        $settings = $this->sanitize_common_settings($raw);

        if (is_multisite()) {
            update_site_option(self::OPTION_COMMON_SETTINGS, $settings);
        } else {
            update_option(self::OPTION_COMMON_SETTINGS, $settings, false);
        }

        wp_send_json_success(['message' => 'Đã lưu cấu hình chung.']);
    }

    public function ajax_save_shop_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        $raw = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        if (!is_array($raw)) {
            wp_send_json_error(['message' => 'Dữ liệu không hợp lệ.'], 400);
        }

        $settings = $this->sanitize_shop_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);

        wp_send_json_success(['message' => 'Đã lưu cấu hình cho cửa hàng hiện tại.']);
    }

    public function ajax_export_settings()
    {
        check_ajax_referer('tgs_viettel_invoice_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
        }

        $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'all';

        $data = [
            'version' => 2,
            'exported_at' => current_time('mysql'),
        ];

        if ($scope === 'common' || $scope === 'all') {
            if (is_super_admin()) {
                $data['common'] = self::get_common_settings();
            }
        }

        if ($scope === 'shop' || $scope === 'all') {
            $data['shop'] = self::get_shop_settings();
            $data['blog_id'] = get_current_blog_id();
            $data['site_name'] = get_bloginfo('name');
        }

        wp_send_json_success($data);
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

        $scope = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'auto';

        $imported = [];

        // Import common settings
        if ($scope === 'common' || ($scope === 'auto' && isset($decoded['common']))) {
            if (!is_super_admin()) {
                wp_send_json_error(['message' => 'Chỉ Super Admin mới có thể import cấu hình chung.'], 403);
            }
            $common_payload = isset($decoded['common']) && is_array($decoded['common']) ? $decoded['common'] : $decoded;
            $common_settings = $this->sanitize_common_settings($common_payload);
            if (is_multisite()) {
                update_site_option(self::OPTION_COMMON_SETTINGS, $common_settings);
            } else {
                update_option(self::OPTION_COMMON_SETTINGS, $common_settings, false);
            }
            $imported[] = 'common';
        }

        // Import shop settings
        if ($scope === 'shop' || ($scope === 'auto' && isset($decoded['shop']))) {
            $shop_payload = isset($decoded['shop']) && is_array($decoded['shop']) ? $decoded['shop'] : $decoded;
            $shop_settings = $this->sanitize_shop_settings($shop_payload);
            update_option(self::OPTION_SETTINGS, $shop_settings, false);
            $imported[] = 'shop';
        }

        // Auto-detect flat v1 format: treat as shop settings (backward compat)
        if ($scope === 'auto' && empty($imported)) {
            $shop_settings = $this->sanitize_shop_settings($decoded);
            update_option(self::OPTION_SETTINGS, $shop_settings, false);
            $imported[] = 'shop (v1)';
        }

        if (empty($imported)) {
            wp_send_json_error(['message' => 'Không tìm thấy dữ liệu cấu hình phù hợp để import.'], 400);
        }

        wp_send_json_success(['message' => 'Đã import cấu hình thành công: ' . implode(', ', $imported) . '.']);
    }

    public function run_migration()
    {
        // Use site_option on multisite, regular option on single site
        $flag_key = 'tgs_viettel_invoice_migration_done';
        $migrated = is_multisite() ? get_site_option($flag_key, false) : get_option($flag_key, false);
        if ($migrated) {
            return;
        }

        if (!is_multisite()) {
            // Single site: ensure common option exists with current data
            $common = self::get_common_settings();
            if (empty($common)) {
                $all_settings = get_option(self::OPTION_SETTINGS, []);
                if (is_array($all_settings) && !empty($all_settings)) {
                    $common_fields = self::get_common_fields();
                    $extracted = [];
                    foreach ($common_fields as $f) {
                        if (isset($all_settings[$f])) {
                            $extracted[$f] = $all_settings[$f];
                            unset($all_settings[$f]);
                        }
                    }
                    if (!empty($extracted)) {
                        update_option(self::OPTION_COMMON_SETTINGS, $extracted, false);
                        update_option(self::OPTION_SETTINGS, $all_settings, false);
                    }
                }
            }
            update_option($flag_key, true);
            return;
        }

        // Multisite migration
        if (is_super_admin()) {
            // Capture blog 1's original settings BEFORE stripping
            switch_to_blog(1);
            $main_shop_before = get_option(self::OPTION_SETTINGS, []);
            restore_current_blog();

            // Strip common fields from all sites
            $sites = get_sites(['number' => 1000]);
            foreach ($sites as $site) {
                switch_to_blog($site->blog_id);
                $shop = get_option(self::OPTION_SETTINGS, []);
                if (is_array($shop) && !empty($shop)) {
                    $changed = false;
                    $common_fields = self::get_common_fields();
                    foreach ($common_fields as $f) {
                        if (isset($shop[$f])) {
                            unset($shop[$f]);
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        update_option(self::OPTION_SETTINGS, $shop, false);
                    }
                }
                restore_current_blog();
            }

            // Save common settings from blog 1 (pre-strip data) as baseline
            $existing_common = self::get_common_settings();
            if (empty($existing_common) && !empty($main_shop_before)) {
                $common_fields = self::get_common_fields();
                $extracted = [];
                foreach ($common_fields as $f) {
                    if (isset($main_shop_before[$f])) {
                        $extracted[$f] = $main_shop_before[$f];
                    }
                }
                if (!empty($extracted)) {
                    update_site_option(self::OPTION_COMMON_SETTINGS, $extracted);
                }
            }
        }

        update_site_option($flag_key, true);
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

        $settings = self::get_settings_for_invoice($invoice_id);
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
        ], $settings, false);

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
        $this->bootstrap_requested_blog_context();
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!$this->current_user_can_use_pos()) {
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
        $this->bootstrap_requested_blog_context();
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!$this->current_user_can_use_pos()) {
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
        $document_filter = isset($_POST['document_type']) ? sanitize_key(wp_unslash($_POST['document_type'])) : 'all';
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
                    vi.total_before_tax,
                    vi.total_tax_amount,
                    vi.total_after_tax,
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
                    $defaults = self::get_default_settings();
                    $row['template_code'] = $defaults['default_template_code'] ?? '1/1156';
                }
                $row['contains_under24_main_item'] = !empty($under24_flags[intval($row['sale_ledger_id'] ?? 0)]) ? 1 : 0;
                $row['age_group'] = !empty($row['contains_under24_main_item']) ? 'under24' : 'over24';
                $row['row_key'] = 'sale:' . intval($row['sale_ledger_id'] ?? 0);
                $row['document_type'] = 'sale';
                $row['document_type_label'] = 'Hóa đơn bán';
                $row['return_scope'] = '';
                $row['return_scope_label'] = '';
                $row['return_ledger_id'] = 0;
                $row['queue_id'] = 0;
                $row['sale_code'] = (string) ($row['local_ledger_code'] ?? '');
                $row['original_invoice_no'] = '';
                $row['total_before_tax'] = floatval($row['total_before_tax'] ?? 0);
                $row['total_tax_amount'] = floatval($row['total_tax_amount'] ?? 0);
                $row['total_after_tax'] = floatval($row['total_after_tax'] ?? 0);
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

                $matches_document = ($document_filter === 'all' || $document_filter === 'sale');
                $row['_matches_filters'] = $matches_status && $matches_age && $matches_document;
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

        /*
         * Phiếu hoàn là một chứng từ thuế độc lập. Không JOIN chúng vào dòng đơn bán vì
         * một đơn có thể có nhiều lần hoàn; mỗi queue phải giữ một row_key riêng.
         */
        if ($document_filter !== 'sale' && $age_filter === 'all' && class_exists('TGS_Viettel_Invoice_Clusters')) {
            $tables = TGS_Viettel_Invoice_Clusters::instance()->tables();
            $return_table = (string) ($tables['return_adjustments'] ?? '');
            $table_exists = $return_table !== '' && $wpdb->get_var($wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($return_table)
            )) === $return_table;

            if ($table_exists) {
                $return_sql = "SELECT
                        q.id AS queue_id,
                        q.return_ledger_id,
                        q.sale_ledger_id,
                        q.status AS queue_status,
                        q.attempt_count,
                        q.original_invoice_no,
                        q.adjustment_invoice_no,
                        q.error_message,
                        q.request_payload,
                        q.created_at,
                        q.updated_at,
                        r.local_ledger_code AS return_code,
                        r.local_ledger_total_amount AS return_total_amount,
                        s.local_ledger_code AS sale_code,
                        original_vi.total_after_tax AS original_total_after_tax,
                        adjustment_vi.issue_http_code,
                        adjustment_vi.cqt_http_code,
                        adjustment_vi.total_before_tax,
                        adjustment_vi.total_tax_amount,
                        adjustment_vi.total_after_tax
                    FROM {$return_table} q
                    LEFT JOIN " . TGS_TABLE_LOCAL_LEDGER . " r ON r.local_ledger_id = q.return_ledger_id
                    LEFT JOIN " . TGS_TABLE_LOCAL_LEDGER . " s ON s.local_ledger_id = q.sale_ledger_id
                    LEFT JOIN " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . " original_vi
                        ON original_vi.local_viettel_invoice_id = q.original_invoice_record_id
                    LEFT JOIN " . TGS_TABLE_LOCAL_VIETTEL_INVOICE . " adjustment_vi
                        ON adjustment_vi.local_viettel_invoice_id = q.adjustment_invoice_record_id
                    WHERE q.blog_id = %d";
                $return_args = [get_current_blog_id()];
                if ($date_from) {
                    $return_sql .= ' AND q.created_at >= %s';
                    $return_args[] = $date_from . ' 00:00:00';
                }
                if ($date_to) {
                    $return_sql .= ' AND q.created_at <= %s';
                    $return_args[] = $date_to . ' 23:59:59';
                }
                $return_sql .= ' ORDER BY q.id DESC LIMIT %d';
                $return_args[] = $limit;
                $return_rows = $wpdb->get_results($wpdb->prepare($return_sql, $return_args), ARRAY_A);

                foreach ((array) $return_rows as $return_row) {
                    $queue_status = sanitize_key($return_row['queue_status'] ?? 'pending');
                    $state = $queue_status === 'done'
                        ? 'done'
                        : ($queue_status === 'processing' ? 'pending' : $queue_status);

                    $payload = json_decode((string) ($return_row['request_payload'] ?? ''), true);
                    $summary = is_array($payload['summarizeInfo'] ?? null) ? $payload['summarizeInfo'] : [];
                    $before = floatval($return_row['total_before_tax'] ?? 0);
                    $tax = floatval($return_row['total_tax_amount'] ?? 0);
                    $after = floatval($return_row['total_after_tax'] ?? 0);
                    if ($before == 0.0 && isset($summary['totalAmountWithoutTax'])) {
                        $before = floatval($summary['totalAmountWithoutTax']);
                    }
                    if ($tax == 0.0 && isset($summary['totalTaxAmount'])) {
                        $tax = floatval($summary['totalTaxAmount']);
                    }
                    if ($after == 0.0 && isset($summary['totalAmountWithTax'])) {
                        $after = floatval($summary['totalAmountWithTax']);
                    }
                    if ($after == 0.0) {
                        $after = abs(floatval($return_row['return_total_amount'] ?? 0));
                    }

                    $original_total = abs(floatval($return_row['original_total_after_tax'] ?? 0));
                    $scope = ($original_total > 0 && abs($after - $original_total) < 1)
                        ? 'full'
                        : 'partial';

                    $matches_status = true;
                    if ($status_filter === 'success') {
                        $matches_status = ($state === 'done');
                    } elseif ($status_filter === 'failed') {
                        $matches_status = ($state === 'error');
                    } elseif ($status_filter === 'pending') {
                        $matches_status = in_array($state, ['pending', 'blocked'], true);
                    } elseif ($status_filter === 'unsent') {
                        $matches_status = in_array($state, ['pending', 'blocked'], true);
                    }
                    if (!$matches_status) {
                        continue;
                    }

                    if ($state === 'done') {
                        $status_counts['success']++;
                    } elseif ($state === 'error') {
                        $status_counts['failed']++;
                    } else {
                        $status_counts['pending']++;
                    }
                    $status_counts['all']++;

                    $rows[] = [
                        'row_key' => 'return_adjustment:' . intval($return_row['queue_id'] ?? 0),
                        'document_type' => 'return_adjustment',
                        'document_type_label' => 'Điều chỉnh giảm',
                        'return_scope' => $scope,
                        'return_scope_label' => $scope === 'full' ? 'Hoàn toàn bộ' : 'Hoàn một phần',
                        'queue_id' => intval($return_row['queue_id'] ?? 0),
                        'return_ledger_id' => intval($return_row['return_ledger_id'] ?? 0),
                        'sale_ledger_id' => intval($return_row['sale_ledger_id'] ?? 0),
                        'local_ledger_code' => (string) ($return_row['return_code'] ?? ''),
                        'sale_code' => (string) ($return_row['sale_code'] ?? ''),
                        'original_invoice_no' => (string) ($return_row['original_invoice_no'] ?? ''),
                        'invoice_no' => (string) ($return_row['adjustment_invoice_no'] ?? ''),
                        'invoice_state' => $state,
                        'issue_status' => $state === 'done' ? 1 : 0,
                        'send_cqt_status' => $state === 'done' ? 1 : 0,
                        'issue_http_code' => intval($return_row['issue_http_code'] ?? 0),
                        'cqt_http_code' => intval($return_row['cqt_http_code'] ?? 0),
                        'error_message' => (string) ($return_row['error_message'] ?? ''),
                        'contains_under24_main_item' => null,
                        'age_group' => 'not_applicable',
                        'total_before_tax' => -abs($before),
                        'total_tax_amount' => -abs($tax),
                        'total_after_tax' => -abs($after),
                        'attempt_count' => intval($return_row['attempt_count'] ?? 0),
                        'updated_at' => (string) ($return_row['updated_at'] ?? ''),
                        'created_at' => (string) ($return_row['created_at'] ?? ''),
                    ];
                }
            }
        }

        usort($rows, static function ($a, $b) {
            $a_time = strtotime((string) ($a['updated_at'] ?? ($a['created_at'] ?? ''))) ?: 0;
            $b_time = strtotime((string) ($b['updated_at'] ?? ($b['created_at'] ?? ''))) ?: 0;
            return $b_time <=> $a_time;
        });
        $rows = array_slice($rows, 0, $limit);

        wp_send_json_success([
            'items'         => is_array($rows) ? $rows : [],
            'status_filter' => $status_filter,
            'document_type_filter' => $document_filter,
            'age_filter'    => $age_filter,
            'date_from'     => $date_from,
            'date_to'       => $date_to,
            'age_counts'    => $age_counts,
            'status_counts' => $status_counts,
        ]);
    }

    public function ajax_pos_debug_statuses_context()
    {
        $this->bootstrap_requested_blog_context();

        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error([
                'message' => 'Nonce không hợp lệ.',
                'debug' => $this->get_pos_statuses_debug_context(),
            ], 403);
            return;
        }

        wp_send_json_success([
            'debug' => $this->get_pos_statuses_debug_context(),
        ]);
    }

    /**
     * POS: Lấy danh sách item của đơn bán để review trước khi gửi CQT.
     * Trả về gift_items, has_under24_main, under24_main_skus.
     */
    public function ajax_pos_get_items_for_review()
    {
        $this->bootstrap_requested_blog_context();
        global $wpdb;

        $nonce = sanitize_text_field($_REQUEST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        $sale_ledger_id = intval($_REQUEST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id.']);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            wp_send_json_error(['message' => 'Thiếu hằng số bảng dữ liệu.']);
            return;
        }

        // Lấy item_ids + thông tin phiếu bán (bao gồm code + ngày + person_id cho preview)
        $sale = $wpdb->get_row(
            $wpdb->prepare('SELECT local_ledger_item_id, local_ledger_code, local_ledger_person_id, created_at FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d LIMIT 1', $sale_ledger_id),
            ARRAY_A
        );

        if (empty($sale)) {
            wp_send_json_error(['message' => 'Không tìm thấy đơn bán hàng.']);
            return;
        }

        $item_ids = is_string($sale['local_ledger_item_id']) ? json_decode($sale['local_ledger_item_id'], true) : [];
        $item_ids = is_array($item_ids) ? array_map('intval', array_filter($item_ids)) : [];

        if (empty($item_ids)) {
            wp_send_json_success([
                'gift_items' => [],
                'main_items' => [],
                'all_items'  => [],
                'has_under24_main' => false,
                'under24_main_skus' => [],
                'sale_code'     => (string) ($sale['local_ledger_code'] ?? ''),
                'sale_date'     => (string) ($sale['created_at'] ?? ''),
                'customer'      => ['name' => 'Khách lẻ', 'phone' => '', 'address' => '', 'tax_code' => '', 'company_name' => '', 'email' => ''],
            ]);
            return;
        }

        // --- Thông tin khách hàng cho preview hóa đơn (không lưu backend) ---
        $preview_customer = ['name' => 'Khách lẻ', 'phone' => '', 'address' => '', 'tax_code' => '', 'company_name' => '', 'email' => ''];
        if (defined('TGS_TABLE_LOCAL_LEDGER_PERSON') && !empty($sale['local_ledger_person_id'])) {
            $person_row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT local_ledger_person_name, local_ledger_person_address, local_ledger_person_phone, local_ledger_person_email, local_ledger_person_tax_code FROM ' . TGS_TABLE_LOCAL_LEDGER_PERSON . ' WHERE local_ledger_person_id = %d LIMIT 1',
                    intval($sale['local_ledger_person_id'])
                ),
                ARRAY_A
            );
            if (!empty($person_row)) {
                $preview_customer['name']         = (string) ($person_row['local_ledger_person_name'] ?? 'Khách lẻ');
                $preview_customer['phone']        = (string) ($person_row['local_ledger_person_phone'] ?? '');
                $preview_customer['address']      = (string) ($person_row['local_ledger_person_address'] ?? '');
                $preview_customer['email']        = (string) ($person_row['local_ledger_person_email'] ?? '');
                $preview_customer['tax_code']     = (string) ($person_row['local_ledger_person_tax_code'] ?? '');
            }
        }

        // Kiểm tra column is_under24_promo_danger tồn tại không
        $has_danger_col = $this->flow_service->local_ledger_item_column_exists('local_ledger_item_is_under24_promo_danger');
        $danger_col_sql = $has_danger_col ? ', i.local_ledger_item_is_under24_promo_danger' : '';
        // Kiểm tra column price_after_discount cho preview
        $has_pad_col = $this->flow_service->local_ledger_item_column_exists('local_ledger_item_price_after_discount');
        $pad_col_sql = $has_pad_col ? ', i.local_ledger_item_price_after_discount' : '';
        $has_global_product_name_id = $this->flow_service->local_ledger_item_column_exists('global_product_name_id');
        $has_local_product_sku = $this->flow_service->local_ledger_item_column_exists('local_product_sku');
        $global_id_sql = $has_global_product_name_id ? ', i.global_product_name_id' : ', 0 AS global_product_name_id';
        $sku_sql = $has_local_product_sku ? ', i.local_product_sku' : ", '' AS local_product_sku";
        $has_tax_percent = $this->flow_service->local_ledger_item_column_exists('local_ledger_item_tax_percent');
        $tax_percent_sql = $has_tax_percent ? ', i.local_ledger_item_tax_percent' : ', 0 AS local_ledger_item_tax_percent';
        $has_discount_amount = $this->flow_service->local_ledger_item_column_exists('local_ledger_item_discount_amount');
        $discount_amount_sql = $has_discount_amount ? ', i.local_ledger_item_discount_amount' : ', 0 AS local_ledger_item_discount_amount';
        $has_tax_amount = $this->flow_service->local_ledger_item_column_exists('local_ledger_item_tax_amount');
        $tax_amount_sql = $has_tax_amount ? ', i.local_ledger_item_tax_amount' : ', 0 AS local_ledger_item_tax_amount';

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.local_ledger_item_id, i.local_product_name_id,
                        i.local_ledger_item_gift_type, i.local_ledger_item_meta, i.quantity, i.price,
                        i.local_ledger_item_discount, i.local_ledger_item_discount_type'
                        . $tax_percent_sql . $discount_amount_sql . $tax_amount_sql . $danger_col_sql . $pad_col_sql . $global_id_sql . $sku_sql . '
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' i
                 WHERE i.local_ledger_item_id IN (' . $placeholders . ')
                 ORDER BY i.local_ledger_item_id ASC',
                ...$item_ids
            ),
            ARRAY_A
        );

        // Catalog sản phẩm lấy từ global. local_product_* chỉ còn là alias để UI POS cũ đọc được.
        if (class_exists('TGS_Viettel_Invoice_Global_Products')) {
            $rows = TGS_Viettel_Invoice_Global_Products::enrich_ledger_items($rows, get_current_blog_id());
        }

        if (empty($rows)) {
            wp_send_json_success([
                'gift_items' => [],
                'main_items' => [],
                'all_items'  => [],
                'has_under24_main' => false,
                'under24_main_skus' => [],
            ]);
            return;
        }

        // Tìm SKU dưới 24 tháng
        $all_skus = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['local_product_sku'] ?? ''));
            if ($sku !== '') {
                $all_skus[] = $sku;
            }
        }
        $all_skus = array_values(array_unique($all_skus));

        $under24_skus = class_exists('TGS_Viettel_Invoice_Global_Products')
            ? TGS_Viettel_Invoice_Global_Products::find_under24_skus($all_skus)
            : [];
        $under24_lookup = array_fill_keys($under24_skus, true);

        // Phân loại item
        $gift_items = [];
        $main_items = [];
        $all_items = [];
        $under24_main_skus = [];
        $has_under24_main = false;
        $stat_z_sku_count = 0;
        $stat_z_main_count = 0;
        $stat_danger_flagged_count = 0;

        foreach ($rows as $row) {
            $is_gift = intval($row['local_ledger_item_gift_type'] ?? 0) === 1;
            $sku = (string) ($row['local_product_sku'] ?? '');
            $danger = $has_danger_col ? intval($row['local_ledger_item_is_under24_promo_danger'] ?? 0) : 0;

            /*
             * CK% suy từ tiền chiết khấu — hai cột discount / discount_type đã
             * ngừng ghi, và trên dữ liệu cũ cột `discount` còn lẫn lộn giữa
             * phần trăm và tiền. Xem TGS_Money::discount_percent_of().
             */
            $disc_value = class_exists('TGS_Money')
                ? TGS_Money::discount_percent_of($row, $row['local_ledger_item_discount'] ?? 0)
                : floatval($row['local_ledger_item_discount'] ?? 0);
            $disc_type = $disc_value > 0 ? 'percent' : '';

            /*
             * Giữ lại từ nhánh kia khi gộp code: `$disc_amount` được dùng thẳng
             * ở mảng $item bên dưới ('discount_amount' => $disc_amount). Nhánh
             * tính CK% bỏ mất dòng này, và đó chính là nguồn của cảnh báo
             * "Undefined variable $disc_amount" từng thấy trong debug.log.
             */
            $disc_amount = floatval($row['local_ledger_item_discount_amount'] ?? 0);
            $price_after_disc = $has_pad_col ? floatval($row['local_ledger_item_price_after_discount'] ?? $row['price']) : floatval($row['price']);
            $tax_percent = floatval($row['local_ledger_item_tax_percent'] ?? 0);
            $tax_amount = floatval($row['local_ledger_item_tax_amount'] ?? 0);

            // Phát hiện SKU kết thúc bằng chữ Z (case-insensitive).
            // Ghi chú: phần mềm nghiệp vụ bên ngoài đang đặt đuôi Z cho các KM đặc biệt,
            // hệ thống fill sẵn "loại bỏ" để an toàn — nhân viên vẫn có thể bỏ tích nếu cần.
            $is_sku_ends_z = $is_gift && $sku !== '' && strtoupper(substr(rtrim($sku), -1)) === 'Z';

            $item = [
                'item_id'                 => intval($row['local_ledger_item_id']),
                'name'                    => (string) ($row['local_product_name'] ?? ''),
                'sku'                     => $sku,
                'unit_name'               => (string) ($row['local_product_unit'] ?? ''),
                'quantity'                => floatval($row['quantity']),
                'price'                   => floatval($row['price']),
                'discount_value'          => $disc_value,
                'discount_type'           => $disc_type,
                'discount_amount'         => $disc_amount,
                'price_after_discount'    => $price_after_disc,
                'tax_percent'             => $tax_percent,
                'tax_amount'              => $tax_amount,
                'is_gift'                 => $is_gift,
                'is_under24_promo_danger' => $danger,
                'is_sku_ends_z'           => $is_sku_ends_z,
            ];

            $all_items[] = $item;

            if (!$is_gift) {
                $main_items[] = $item;
                if (isset($under24_lookup[$sku]) && $sku !== '') {
                    $has_under24_main = true;
                    $under24_main_skus[] = $sku;
                }
                if ($is_sku_ends_z) {
                    $stat_z_main_count++;
                }
            } else {
                $gift_items[] = $item;
                if ($is_sku_ends_z) {
                    $stat_z_sku_count++;
                }
                if ($danger) {
                    $stat_danger_flagged_count++;
                }
            }
        }

        $under24_main_skus = array_values(array_unique($under24_main_skus));

        wp_send_json_success([
            'gift_items'               => $gift_items,
            'main_items'               => $main_items,
            'all_items'                => $all_items,
            'has_under24_main'         => $has_under24_main,
            'under24_main_skus'        => $under24_main_skus,
            'stat_z_sku_count'         => $stat_z_sku_count,
            'stat_z_main_count'        => $stat_z_main_count,
            'stat_danger_flagged_count' => $stat_danger_flagged_count,
            // Dữ liệu bổ sung cho preview hóa đơn real-time (không lưu backend)
            'sale_code'                => (string) ($sale['local_ledger_code'] ?? ''),
            'sale_date'                => (string) ($sale['created_at'] ?? ''),
            'customer'                 => $preview_customer,
        ]);
    }

    /**
     * POS: Cập nhật cờ is_under24_promo_danger cho các item của đơn bán.
     * Frontend gửi lên mảng item_flags = [{item_id, danger_flag}].
     */
    public function ajax_pos_update_danger_flags()
    {
        $this->bootstrap_requested_blog_context();
        global $wpdb;

        $nonce = sanitize_text_field($_REQUEST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        $sale_ledger_id = intval($_REQUEST['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu sale_ledger_id.']);
            return;
        }

        $flags_raw = sanitize_text_field($_REQUEST['item_flags'] ?? '');
        $flags = json_decode($flags_raw, true);

        if (!is_array($flags) || empty($flags)) {
            wp_send_json_success(['message' => 'Không có cờ nào cần cập nhật.', 'updated' => 0]);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            wp_send_json_error(['message' => 'Thiếu hằng số bảng dữ liệu.']);
            return;
        }

        if (!$this->flow_service->local_ledger_item_column_exists('local_ledger_item_is_under24_promo_danger')) {
            wp_send_json_error(['message' => 'Cột local_ledger_item_is_under24_promo_danger chưa tồn tại. Cần chạy migration DB.']);
            return;
        }

        $updated = 0;
        foreach ($flags as $flag_entry) {
            $item_id = intval($flag_entry['item_id'] ?? 0);
            $danger  = intval($flag_entry['danger_flag'] ?? 0) === 1 ? 1 : 0;

            if ($item_id <= 0) {
                continue;
            }

            $result = $wpdb->update(
                TGS_TABLE_LOCAL_LEDGER_ITEM,
                ['local_ledger_item_is_under24_promo_danger' => $danger],
                ['local_ledger_item_id' => $item_id],
                ['%d'],
                ['%d']
            );

            if ($result !== false) {
                $updated++;
            }
        }

        wp_send_json_success(['message' => "Đã cập nhật $updated dòng.", 'updated' => $updated]);
    }

    /**
     * POS retry thông minh:
     * - Nếu hóa đơn đã issue thành công (có transaction UUID) => chỉ gửi lại CQT.
     * - Nếu chưa issue được => chạy lại full flow issue + send CQT.
     */
    public function ajax_pos_retry_invoice()
    {
        $this->bootstrap_requested_blog_context();
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!$this->current_user_can_use_pos()) {
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
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, invoice_state, issue_status, send_cqt_status, issue_transaction_uuid, resend_count
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
        $send_cqt_status = intval($latest['send_cqt_status'] ?? 0);
        $invoice_state = sanitize_key($latest['invoice_state'] ?? '');
        $created_by = get_current_user_id();

        $process_lock = $this->acquire_sale_invoice_lock($sale_ledger_id);
        if ($process_lock === '') {
            wp_send_json_error([
                'message' => 'Hóa đơn của đơn này đang được xử lý. Vui lòng chờ và kiểm tra lại.',
                'step' => 'already_processing',
            ], 409);
            return;
        }

        // Đọc lại sau khi đã giữ khóa để tránh dùng trạng thái cũ khi hai request đến gần nhau.
        $latest = $this->get_latest_sale_invoice($sale_ledger_id);
        $invoice_id = intval($latest['local_viettel_invoice_id'] ?? 0);
        $transaction_uuid = sanitize_text_field($latest['issue_transaction_uuid'] ?? '');
        $issue_status = intval($latest['issue_status'] ?? 0);
        $send_cqt_status = intval($latest['send_cqt_status'] ?? 0);
        $invoice_state = sanitize_key($latest['invoice_state'] ?? '');

        if ($invoice_id > 0 && ($invoice_state === 'done' || $send_cqt_status === 1)) {
            wp_send_json_success([
                'message' => 'Hóa đơn đã được gửi CQT trước đó, hệ thống không gửi lại.',
                'sale_ledger_id' => $sale_ledger_id,
                'mode' => 'already_done',
                'already_sent' => true,
                'transaction_uuid' => $transaction_uuid,
            ]);
            return;
        }

        if ($invoice_id > 0 && $invoice_state === 'pending') {
            wp_send_json_error([
                'message' => 'Hóa đơn đang được xử lý. Vui lòng chờ và kiểm tra lại trước khi gửi lại.',
                'step' => 'already_processing',
            ], 409);
            return;
        }

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
            $settings = self::get_settings_for_invoice($invoice_id);
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

        $settings = self::get_settings_for_invoice($invoice_id);
        if (empty($settings['username']) || empty($settings['supplier_tax_code'])) {
            wp_send_json_error(['message' => 'Chưa cấu hình Viettel Invoice. Vui lòng vào Cấu hình để thiết lập.'], 400);
            return;
        }

        $customer_override_raw = sanitize_text_field($_POST['customer_override'] ?? '');
        $customer_override = ($customer_override_raw !== '' && $customer_override_raw !== '{}')
            ? json_decode(stripslashes($customer_override_raw), true)
            : [];
        if (!is_array($customer_override)) {
            $customer_override = [];
        }

        $flow_result = $this->run_auto_issue_cqt_flow([
            'sale_ledger_id' => $sale_ledger_id,
            'sale_code' => sanitize_text_field($latest['local_ledger_code'] ?? ''),
            'employee_id' => $created_by,
            'excluded_item_ids' => $this->parse_excluded_item_ids_from_post(),
            'customer_override' => $customer_override,
        ], $settings, false);

        if (empty($flow_result['success'])) {
            wp_send_json_error([
                'message' => $flow_result['message'] ?? 'Gửi lại hóa đơn chưa thành công.',
                'step' => $flow_result['step'] ?? 'full_flow',
            ], 400);
            return;
        }

        wp_send_json_success([
            'message' => 'Đã phát hành lại hóa đơn và gửi CQT thành công.',
            'sale_ledger_id' => $sale_ledger_id,
            'mode' => 'full_flow',
        ]);
    }

    /**
     * POS: gửi email file PDF hóa đơn từ danh sách "Thành công".
     */
    public function ajax_pos_send_invoice_email()
    {
        $this->bootstrap_requested_blog_context();
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!$this->current_user_can_use_pos()) {
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

        $template_code = sanitize_text_field($latest['template_code'] ?? '');
        if ($template_code === '') {
            $defaults = self::get_default_settings();
            $template_code = $defaults['default_template_code'] ?? '1/1156';
        }

        $settings = self::get_settings_for_invoice(intval($latest['local_viettel_invoice_id'] ?? 0));
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

        $sale_code = sanitize_text_field($latest['local_ledger_code'] ?? 'Đơn hàng');
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

    public function ajax_lookup_customer_by_tax_code()
    {
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        // Kiểm tra user đã đăng nhập (POS page đã yêu cầu login ở template level)
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bạn cần đăng nhập để tra cứu khách hàng.'], 403);
            return;
        }

        if (!defined('TGS_TABLE_LOCAL_LEDGER_PERSON')) {
            wp_send_json_error(['message' => 'Chưa tìm thấy bảng dữ liệu khách hàng.'], 500);
            return;
        }

        $tax_code = trim(sanitize_text_field($_POST['tax_code'] ?? ''));
        if ($tax_code === '') {
            wp_send_json_error(['message' => 'Vui lòng nhập mã số thuế cần tra cứu.'], 400);
            return;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT local_ledger_person_id, local_ledger_person_name, local_ledger_person_address,
                        local_ledger_person_phone, local_ledger_person_email, local_ledger_person_tax_code
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_PERSON . '
                 WHERE local_ledger_person_tax_code LIKE %s
                 LIMIT 20',
                '%' . $wpdb->esc_like($tax_code) . '%'
            ),
            ARRAY_A
        );

        $customers = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $customers[] = [
                    'id'      => intval($row['local_ledger_person_id']),
                    'name'    => (string) ($row['local_ledger_person_name'] ?? ''),
                    'phone'   => (string) ($row['local_ledger_person_phone'] ?? ''),
                    'address' => (string) ($row['local_ledger_person_address'] ?? ''),
                    'email'   => (string) ($row['local_ledger_person_email'] ?? ''),
                    'tax_code' => (string) ($row['local_ledger_person_tax_code'] ?? ''),
                ];
            }
        }

        wp_send_json_success([
            'customers' => $customers,
            'message'   => count($customers) > 0 ? 'Tìm thấy ' . count($customers) . ' khách hàng.' : 'Không tìm thấy khách hàng nào.',
        ]);
    }

    /**
     * POS: xem trực tiếp PDF hóa đơn trong trình duyệt.
     */
    public function ajax_pos_preview_invoice_pdf()
    {
        $this->bootstrap_requested_blog_context();
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (
            empty($nonce)
            || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
            return;
        }

        if (!$this->current_user_can_use_pos()) {
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

        $template_code = sanitize_text_field($latest['template_code'] ?? '');
        if ($template_code === '') {
            $defaults = self::get_default_settings();
            $template_code = $defaults['default_template_code'] ?? '1/1156';
        }

        $settings = self::get_settings_for_invoice(intval($latest['local_viettel_invoice_id'] ?? 0));
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
            // Cùng lý do với submit_invoice_payload() — xem giải thích ở đó.
            'Connection'   => 'keep-alive',
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
            // Bắt buộc 1.1 — xem giải thích dài ở submit_invoice_payload().
            // Để mặc định (1.0) là dính cURL error 56 trên máy có OpenSSL 3.
            'httpversion' => '1.1',
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
        $context_invoice_id = intval($context['invoice_record_id'] ?? 0);
        $settings = $context_invoice_id > 0
            ? self::get_settings_for_invoice($context_invoice_id)
            : self::get_settings();
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
            // Xem khối giải thích ở dưới, chỗ $http_args. Bắt buộc phải khai
            // tường minh: WordPress tự gắn `Connection: close` nếu để trống.
            'Connection'   => 'keep-alive',
        ];

        if ($settings['auth_mode'] === 'token') {
            $headers['Authorization'] = 'Bearer ' . $settings['access_token'];
            // Một số phiên bản SInvoice đọc token từ Cookie theo tài liệu v2.50.
            $headers['Cookie'] = 'access_token=' . rawurlencode($settings['access_token']);
        } else {
            $token = base64_encode($settings['username'] . ':' . $settings['password']);
            $headers['Authorization'] = 'Basic ' . $token;
        }

        /*
         * ─── PHẢI GIỮ KẾT NỐI KEEP-ALIVE, KHÔNG ĐỂ WORDPRESS TỰ ĐÓNG ────────
         *
         * Gateway Viettel, khi kết thúc một kết nối kiểu đóng-ngay, cắt thẳng
         * TCP mà KHÔNG gửi `close_notify` của TLS. Từ OpenSSL 3.0 trở đi đó là
         * lỗi cứng chứ không còn được bỏ qua:
         *
         *     cURL error 56: OpenSSL SSL_read: error:0A000126:SSL routines::
         *     unexpected eof while reading
         *
         * Khi đó wp_remote_post() trả WP_Error, http_code = 0, không đọc được
         * gì — dù Viettel đã trả lời đầy đủ. Máy nào còn OpenSSL 1.1.1 thì vẫn
         * chạy vì 1.1.1 lặng lẽ bỏ qua. Đó là lý do cùng một bản code mà web
         * thật + máy local chạy được còn server UAT (Ubuntu 22.04 / OpenSSL
         * 3.0.2) thì hỏng — rất dễ tưởng nhầm là lỗi mạng hoặc lỗi tài khoản.
         *
         * Cần CẢ HAI thứ dưới đây, thiếu một là vẫn lỗi:
         *
         *   [1] 'httpversion' => '1.1'
         *       WordPress mặc định 1.0 (`WP_Http::request()` đặt sẵn), mà
         *       HTTP/1.0 thì tự hàm ý đóng kết nối.
         *
         *   [2] header 'Connection: keep-alive' khai TƯỜNG MINH
         *       Chỉ đặt [1] là CHƯA ĐỦ: thư viện Requests của WordPress vẫn tự
         *       gắn `Connection: close` vào request. Đã đo trên server UAT —
         *       httpversion 1.1 mà để Requests tự gắn header thì vẫn lỗi 56;
         *       thêm keep-alive vào mới trả về bình thường.
         *
         * ⚠️ Đừng gỡ dòng nào trong hai dòng đó cho "gọn".
         */
        $http_args = [
            'headers'     => $headers,
            // Viettel khuyến nghị 60–90 giây cho request phát hành/điều chỉnh.
            'timeout'     => 90,
            'httpversion' => '1.1',
            'sslverify'   => !empty($settings['verify_ssl']),
        ];

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
            $http_args['headers'] = $headers;
            $response = wp_remote_post($url, $http_args + ['body' => $request_body]);
        } else {
            $request_body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
            $response = wp_remote_post($url, $http_args + ['body' => $request_body]);
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
                $api_code_raw = $response_data['message'] ?? $response_data['errorCode'] ?? '';
                $api_detail_raw = $response_data['data'] ?? $response_data['description'] ?? '';
                $api_code = is_scalar($api_code_raw) ? sanitize_text_field((string) $api_code_raw) : '';
                $api_detail = is_scalar($api_detail_raw) ? sanitize_text_field((string) $api_detail_raw) : '';
                $error_message = 'HTTP ' . $http_code;
                if ($api_code !== '') {
                    $error_message .= ' — ' . $api_code;
                }
                if ($api_detail !== '' && $api_detail !== $api_code) {
                    $error_message .= ': ' . $api_detail;
                }
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

        if ($invoice_id > 0 && class_exists('TGS_Viettel_Invoice_Clusters')) {
            TGS_Viettel_Invoice_Clusters::instance()->save_snapshot(
                get_current_blog_id(),
                $invoice_id,
                intval($context['sale_ledger_id'] ?? 0),
                $settings
            );
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
        if (!empty($settings['_cluster']['assigned']) && empty($settings['_cluster']['effective'])) {
            return 'Cụm phát hành hóa đơn của shop đang ở trạng thái ngừng hoặc ngoài thời gian hiệu lực.';
        }

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
        if (!empty($masked['Cookie'])) {
            $masked['Cookie'] = 'access_token=***';
        }

        return $masked;
    }

    /**
     * Khôi phục số hóa đơn khi createInvoice thành công nhưng response bất đồng
     * bộ chưa trả invoiceNo. UUID cũ được dùng để tra cứu, tuyệt đối không sinh
     * UUID mới vì có thể tạo trùng hóa đơn.
     */
    public function recover_invoice_number_by_transaction_uuid($invoice_record_id)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return '';
        }

        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . ' WHERE local_viettel_invoice_id = %d LIMIT 1',
            intval($invoice_record_id)
        ), ARRAY_A);
        if (empty($invoice)) {
            return '';
        }

        $existing = sanitize_text_field((string) ($invoice['viettel_invoice_no'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $transaction_uuid = sanitize_text_field((string) ($invoice['issue_transaction_uuid'] ?? ''));
        $settings = self::get_settings_for_invoice(intval($invoice_record_id));
        $supplier_tax_code = sanitize_text_field((string) ($settings['supplier_tax_code'] ?? ''));
        if ($transaction_uuid === '' || $supplier_tax_code === '') {
            return '';
        }

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Connection' => 'keep-alive',
        ];
        if (($settings['auth_mode'] ?? 'basic') === 'token') {
            $token = (string) ($settings['access_token'] ?? '');
            if ($token === '') return '';
            $headers['Authorization'] = 'Bearer ' . $token;
            $headers['Cookie'] = 'access_token=' . rawurlencode($token);
        } else {
            $username = (string) ($settings['username'] ?? '');
            $password = (string) ($settings['password'] ?? '');
            if ($username === '' || $password === '') return '';
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        }

        $url = untrailingslashit((string) $settings['api_base_url']) . '/InvoiceAPI/InvoiceWS/searchInvoiceByTransactionUuid';
        $body = http_build_query([
            'supplierTaxCode' => $supplier_tax_code,
            'transactionUuid' => $transaction_uuid,
        ]);
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $body,
            'timeout' => 90,
            'httpversion' => '1.1',
            'sslverify' => !empty($settings['verify_ssl']),
        ]);

        $http_code = is_wp_error($response) ? 0 : intval(wp_remote_retrieve_response_code($response));
        $response_text = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
        $response_data = json_decode($response_text, true);
        $error_message = is_wp_error($response) ? $response->get_error_message() : '';
        $invoice_no = is_array($response_data) ? sanitize_text_field($this->deep_pick($response_data, [
            'result.0.invoiceNo', 'result.invoiceNo', 'data.0.invoiceNo', 'data.invoiceNo', 'invoiceNo',
        ])) : '';

        $this->insert_log_record([
            'invoice_id' => intval($invoice_record_id),
            'sale_ledger_id' => intval($invoice['sale_ledger_id'] ?? 0),
            'local_ledger_code' => sanitize_text_field($invoice['local_ledger_code'] ?? ''),
            'step_name' => 'recover_invoice_no',
            'transaction_uuid' => $transaction_uuid,
            'action_name' => 'search_invoice_by_transaction_uuid',
            'endpoint' => $url,
            'request_headers' => wp_json_encode($this->mask_headers_for_log($headers), JSON_UNESCAPED_UNICODE),
            'request_payload' => $body,
            'response_payload' => $response_text,
            'http_code' => $http_code,
            'error_message' => $error_message,
            'created_by' => get_current_user_id(),
        ]);

        if ($invoice_no !== '') {
            $wpdb->update(TGS_TABLE_LOCAL_VIETTEL_INVOICE, [
                'viettel_invoice_no' => $invoice_no,
                'updated_at' => current_time('mysql'),
            ], ['local_viettel_invoice_id' => intval($invoice_record_id)]);
        }

        return $invoice_no;
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

        $has_global_product_name_id = $this->flow_service->local_ledger_item_column_exists('global_product_name_id');
        $has_local_product_sku = $this->flow_service->local_ledger_item_column_exists('local_product_sku');
        $global_id_sql = $has_global_product_name_id ? ', i.global_product_name_id' : ', 0 AS global_product_name_id';
        $sku_sql = $has_local_product_sku ? ', i.local_product_sku' : ", '' AS local_product_sku";

        $placeholders = implode(',', array_fill(0, count($all_item_ids), '%d'));
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.local_ledger_item_id, i.local_product_name_id, i.local_ledger_item_gift_type'
                    . $global_id_sql . $sku_sql . '
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' i
                 WHERE i.local_ledger_item_id IN (' . $placeholders . ')',
                ...$all_item_ids
            ),
            ARRAY_A
        );

        // ID trên ledger là ID global, SKU/name resolve qua global product source.
        if (class_exists('TGS_Viettel_Invoice_Global_Products')) {
            $items = TGS_Viettel_Invoice_Global_Products::enrich_ledger_items($items, get_current_blog_id());
        }

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

        $under24_rows = class_exists('TGS_Viettel_Invoice_Global_Products')
            ? TGS_Viettel_Invoice_Global_Products::find_under24_skus($main_skus)
            : [];
        $under24_sku_map = array_fill_keys(array_map('strval', $under24_rows), true);

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
         * Nút này dùng Alpine.js binding để gọi openItemReviewModal() (bước review trước CQT).
         */
        public function render_cqt_receipt_button()
        {
            ?>
            <button type="button"
                x-effect="refreshViettelReceiptStatus(getViettelSaleLedgerId())"
                x-on:click="handleViettelReceiptButton()"
                :disabled="isViettelReceiptButtonDisabled()"
                :class="{
                    'opacity-60 cursor-not-allowed bg-gray-500 hover:bg-gray-500': isViettelReceiptButtonDisabled(),
                    'bg-amber-600 hover:bg-amber-700': ['retry_cqt', 'retry_invoice'].includes(getViettelReceiptAction()),
                    'bg-green-600 hover:bg-green-700': getViettelReceiptAction() === 'send'
                }"
                class="flex-1 min-w-[140px] py-3 rounded-xl text-sm font-medium text-white">
                <span x-show="!viettelCQT.isSending && !viettelItemReview.isSaving" x-text="getViettelReceiptButtonText()">Gửi lên cục thuế</span>
                <span x-show="viettelCQT.isSending || viettelItemReview.isSaving" class="flex items-center justify-center gap-1">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    Đang xử lý...
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
            $this->bootstrap_requested_blog_context();
            $nonce = sanitize_text_field($_POST['nonce'] ?? '');
            if (
                empty($nonce)
                || (!wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce'))
            ) {
                wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
                return;
            }

            if (!$this->current_user_can_use_pos()) {
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

            $process_lock = $this->acquire_sale_invoice_lock($sale_ledger_id);
            if ($process_lock === '') {
                wp_send_json_error([
                    'message' => 'Hóa đơn của đơn này đang được xử lý. Vui lòng chờ và kiểm tra lại.',
                    'step' => 'already_processing',
                ], 409);
                return;
            }

            $existing_invoice = $this->get_latest_sale_invoice($sale_ledger_id);
            if (!empty($existing_invoice)) {
                $existing_state = sanitize_key($existing_invoice['invoice_state'] ?? '');
                $existing_cqt_status = intval($existing_invoice['send_cqt_status'] ?? 0);
                $existing_uuid = sanitize_text_field($existing_invoice['issue_transaction_uuid'] ?? '');

                if ($existing_state === 'done' || $existing_cqt_status === 1) {
                    wp_send_json_success([
                        'message' => 'Hóa đơn đã được gửi CQT trước đó, hệ thống không phát hành lại.',
                        'sale_ledger_id' => $sale_ledger_id,
                        'mode' => 'already_done',
                        'already_sent' => true,
                        'transaction_uuid' => $existing_uuid,
                        'tracking_id' => intval($existing_invoice['local_viettel_invoice_id'] ?? 0),
                    ]);
                    return;
                }

                wp_send_json_error([
                    'message' => $existing_state === 'pending'
                        ? 'Hóa đơn đang được xử lý. Vui lòng chờ và kiểm tra lại.'
                        : 'Lần gửi trước chưa hoàn tất. Hãy dùng nút Gửi lại để tiếp tục mà không tạo hóa đơn trùng.',
                    'step' => $existing_state === 'pending' ? 'already_processing' : 'requires_retry',
                    'requires_retry' => $existing_state !== 'pending',
                    'invoice_state' => $existing_state,
                ], 409);
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

            // Áp dụng danh sách loại trừ trực tiếp từ frontend (source of truth).
            // Xoá hết flag danger trước, rồi set true chỉ cho item trong danh sách loại trừ.
            $excluded_ids_raw = sanitize_text_field($_POST['excluded_item_ids'] ?? '');
            $excluded_ids = ($excluded_ids_raw !== '' && $excluded_ids_raw !== '[]')
                ? json_decode($excluded_ids_raw, true)
                : [];
            if (!is_array($excluded_ids)) {
                $excluded_ids = [];
            }
            $excluded_ids_map = [];
            foreach ($excluded_ids as $eid) {
                $eid = intval($eid);
                if ($eid > 0) {
                    $excluded_ids_map[$eid] = true;
                }
            }
            foreach ($source_result['payload']['items'] as &$src_item) {
                $src_item['is_under24_promo_danger'] = isset($excluded_ids_map[intval($src_item['ledger_item_id'] ?? 0)]);
            }
            unset($src_item);

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

    private function parse_excluded_item_ids_from_post()
    {
        $raw = sanitize_text_field($_POST['excluded_item_ids'] ?? '');
        if ($raw === '' || $raw === '[]') {
            return [];
        }
        $ids = json_decode($raw, true);
        if (!is_array($ids) || empty($ids)) {
            return [];
        }
        return array_values(array_filter(array_map('intval', $ids)));
    }

    private function run_auto_issue_cqt_flow($sale_data, $settings, $enforce_idempotency = true)
    {
        $sale_ledger_id = intval($sale_data['sale_ledger_id'] ?? 0);
        if ($sale_ledger_id <= 0) {
            return ['success' => false, 'step' => 'validate', 'message' => 'Thiếu mã đơn bán để gửi hóa đơn.'];
        }

        if ($enforce_idempotency) {
            $process_lock = $this->acquire_sale_invoice_lock($sale_ledger_id);
            if ($process_lock === '') {
                return [
                    'success' => false,
                    'step' => 'already_processing',
                    'message' => 'Hóa đơn của đơn này đang được xử lý.',
                ];
            }

            $existing_invoice = $this->get_latest_sale_invoice($sale_ledger_id);
            if (!empty($existing_invoice)) {
                $already_done = sanitize_key($existing_invoice['invoice_state'] ?? '') === 'done'
                    || intval($existing_invoice['send_cqt_status'] ?? 0) === 1;

                return [
                    'success' => $already_done,
                    'step' => $already_done ? 'already_done' : 'requires_retry',
                    'already_sent' => $already_done,
                    'message' => $already_done
                        ? 'Hóa đơn đã được gửi CQT trước đó, hệ thống không phát hành lại.'
                        : 'Đơn đã có lần gửi hóa đơn chưa hoàn tất; cần dùng luồng gửi lại.',
                    'invoice_id' => intval($existing_invoice['local_viettel_invoice_id'] ?? 0),
                    'transaction_uuid' => sanitize_text_field($existing_invoice['issue_transaction_uuid'] ?? ''),
                ];
            }
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
            return [
                'success' => false,
                'step' => 'prepare_source',
                'message' => $source_result['message'] ?? 'Không thể đọc dữ liệu đơn bán.',
            ];
        }

        // Áp dụng customer_override nếu có (người dùng nhập thông tin khách hàng trên giao diện).
        $customer_override = isset($sale_data['customer_override']) && is_array($sale_data['customer_override'])
            ? $sale_data['customer_override']
            : [];
        if (!empty($customer_override)) {
            $customer_fields = ['customer_name', 'customer_company_name', 'customer_tax_code', 'customer_address', 'customer_phone', 'customer_email'];
            foreach ($customer_fields as $field) {
                if (isset($customer_override[$field]) && $customer_override[$field] !== '') {
                    $source_result['payload']['customer'][$field] = sanitize_text_field($customer_override[$field]);
                }
            }
        }

        // Áp dụng danh sách loại trừ trực tiếp từ frontend (source of truth).
        // Xoá hết flag danger trước, rồi set true chỉ cho item trong danh sách loại trừ.
        $excluded_item_ids = isset($sale_data['excluded_item_ids']) && is_array($sale_data['excluded_item_ids'])
            ? $sale_data['excluded_item_ids']
            : [];
        $excluded_ids_map = [];
        foreach ($excluded_item_ids as $eid) {
            $eid = intval($eid);
            if ($eid > 0) {
                $excluded_ids_map[$eid] = true;
            }
        }
        foreach ($source_result['payload']['items'] as &$src_item) {
            $src_item['is_under24_promo_danger'] = isset($excluded_ids_map[intval($src_item['ledger_item_id'] ?? 0)]);
        }
        unset($src_item);

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
            return [
                'success' => false,
                'step' => 'filter_items',
                'message' => $filtered_result['message'] ?? 'Lỗi lọc sản phẩm gửi thuế.',
            ];
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
            return [
                'success' => false,
                'step' => 'build_issue_payload',
                'message' => $issue_payload_result['message'] ?? 'Không map được payload phát hành.',
            ];
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
            return [
                'success' => false,
                'step' => 'issue',
                'message' => $issue_result['message'] ?? 'Lỗi phát hành hóa đơn.',
            ];
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
            return [
                'success' => false,
                'step' => 'transaction_uuid',
                'message' => 'Không lấy được transactionUuid sau khi phát hành.',
            ];
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
            return [
                'success' => false,
                'step' => 'build_cqt_payload',
                'message' => $send_cqt_payload_result['message'] ?? 'Không tạo được payload gửi CQT.',
            ];
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
            return [
                'success' => false,
                'step' => 'send_cqt',
                'message' => $cqt_result['message'] ?? 'Lỗi gửi CQT.',
            ];
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

        return [
            'success' => true,
            'step' => 'done',
            'message' => 'Đã phát hành hóa đơn và gửi CQT thành công.',
            'invoice_id' => $tracking_id,
            'transaction_uuid' => $transaction_uuid,
        ];
    }

    /**
     * Khóa phát hành theo site + đơn bán trên đúng kết nối MySQL hiện tại.
     * MySQL tự nhả khóa khi request đóng kết nối; shutdown callback nhả chủ động
     * để an toàn cả khi luồng kết thúc bằng wp_send_json_*().
     */
    private function acquire_sale_invoice_lock($sale_ledger_id)
    {
        global $wpdb;

        $sale_ledger_id = intval($sale_ledger_id);
        if ($sale_ledger_id <= 0) {
            return '';
        }

        $lock_name = substr('tgs_vi_' . get_current_blog_id() . '_' . $sale_ledger_id, 0, 64);
        $acquired = intval($wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)));
        if ($acquired !== 1) {
            return '';
        }

        register_shutdown_function(static function () use ($lock_name) {
            global $wpdb;
            if (isset($wpdb)) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            }
        });

        return $lock_name;
    }

    private function get_latest_sale_invoice($sale_ledger_id)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return [];
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_viettel_invoice_id, sale_ledger_id, local_ledger_code, invoice_state,
                        issue_status, send_cqt_status, issue_transaction_uuid, resend_count
                 FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . '
                 WHERE sale_ledger_id = %d
                 ORDER BY local_viettel_invoice_id DESC
                 LIMIT 1',
                intval($sale_ledger_id)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
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

    /**
     * Phát hành một hóa đơn điều chỉnh giảm do POS hoàn hàng, sau đó gửi CQT.
     * Bản ghi điều chỉnh không gắn sale_ledger_id để không che hóa đơn gốc trong
     * các truy vấn trạng thái/preview hiện hành; quan hệ được giữ tại bảng queue.
     */
    public function issue_return_adjustment(array $payload, array $context)
    {
        if (!$this->flow_service || !defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return ['success' => false, 'message' => 'Plugin hóa đơn chưa sẵn sàng.'];
        }

        $original_id = intval($context['original_invoice_record_id'] ?? 0);
        $tracking_id = intval($context['adjustment_invoice_record_id'] ?? 0);
        $created_by = intval($context['created_by'] ?? 0);
        $return_id = intval($context['return_ledger_id'] ?? 0);
        $return_code = sanitize_text_field($payload['local_ledger_code'] ?? '');
        $transaction_uuid = sanitize_text_field($context['transaction_uuid'] ?? '');
        $totals = is_array($context['totals'] ?? null) ? $context['totals'] : [];

        if ($tracking_id <= 0) {
            global $wpdb;
            $wpdb->insert(TGS_TABLE_LOCAL_VIETTEL_INVOICE, [
                'blog_id' => get_current_blog_id(),
                'sale_ledger_id' => null,
                'local_ledger_code' => $return_code,
                'request_mode' => 'adjustment',
                'invoice_state' => 'pending',
                'issue_status' => 0,
                'send_cqt_status' => 0,
                'smart_source_payload' => wp_json_encode([
                    'return_ledger_id' => $return_id,
                    'source_sale_ledger_id' => intval($context['sale_ledger_id'] ?? 0),
                    'original_invoice_record_id' => $original_id,
                ], JSON_UNESCAPED_UNICODE),
                'request_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'created_by' => $created_by,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            $tracking_id = intval($wpdb->insert_id);
        }

        if ($tracking_id <= 0) {
            return ['success' => false, 'message' => 'Không thể tạo bản ghi theo dõi hóa đơn điều chỉnh.'];
        }

        // Điều chỉnh phải dùng đúng tài khoản/MST đã phát hành hóa đơn gốc.
        $settings = self::get_settings_for_invoice($original_id);
        if (class_exists('TGS_Viettel_Invoice_Clusters')) {
            TGS_Viettel_Invoice_Clusters::instance()->save_snapshot(
                get_current_blog_id(),
                $tracking_id,
                intval($context['sale_ledger_id'] ?? 0),
                $settings
            );
        }

        $issue_result = $this->submit_invoice_payload($payload, 'issue', [
            'skip_persist' => true,
            'step_name' => 'return_adjustment_issue',
            'invoice_record_id' => $tracking_id,
            'transaction_uuid' => $transaction_uuid,
            'created_by' => $created_by,
            'sale_ledger_id' => 0,
        ]);

        if (empty($issue_result['success'])) {
            $this->update_auto_flow_tracking($tracking_id, [
                'request_mode' => 'adjustment',
                'invoice_state' => 'issue_error',
                'issue_status' => 2,
                'issue_request_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'issue_response_payload' => wp_json_encode($issue_result, JSON_UNESCAPED_UNICODE),
                'issue_http_code' => intval($issue_result['http_code'] ?? 0),
                'issue_error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn điều chỉnh.'),
                'error_message' => sanitize_text_field($issue_result['message'] ?? 'Lỗi phát hành hóa đơn điều chỉnh.'),
                'updated_at' => current_time('mysql'),
            ]);
            return [
                'success' => false,
                'invoice_record_id' => $tracking_id,
                'message' => $issue_result['message'] ?? 'Lỗi phát hành hóa đơn điều chỉnh.',
            ];
        }

        $response = $issue_result['response'] ?? [];
        $issued_uuid = $this->extract_transaction_uuid($response);
        $invoice_no = sanitize_text_field($this->deep_pick($response, [
            'result.invoiceNo', 'data.invoiceNo', 'invoiceNo', 'invoiceNumber',
        ]));
        $this->update_auto_flow_tracking($tracking_id, [
            'request_mode' => 'adjustment',
            'invoice_state' => 'issued',
            'issue_status' => 1,
            'issue_request_payload' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'issue_response_payload' => wp_json_encode($response, JSON_UNESCAPED_UNICODE),
            'issue_http_code' => intval($issue_result['http_code'] ?? 0),
            'issue_error_message' => '',
            'issue_transaction_uuid' => $issued_uuid,
            'viettel_invoice_no' => $invoice_no,
            'issue_sent_at' => current_time('mysql'),
            'total_before_tax' => floatval($totals['total_before_tax'] ?? 0),
            'total_tax_amount' => floatval($totals['total_tax'] ?? 0),
            'total_after_tax' => floatval($totals['total_after_tax'] ?? 0),
            'template_code' => sanitize_text_field($payload['generalInvoiceInfo']['templateCode'] ?? ''),
            'invoice_series' => sanitize_text_field($payload['generalInvoiceInfo']['invoiceSeries'] ?? ''),
            'buyer_name' => sanitize_text_field($payload['buyerInfo']['buyerName'] ?? ''),
            'buyer_tax_code' => sanitize_text_field($payload['buyerInfo']['buyerTaxCode'] ?? ''),
            'error_message' => '',
            'updated_at' => current_time('mysql'),
        ]);

        if ($issued_uuid === '') {
            $message = 'Hóa đơn điều chỉnh đã phát hành nhưng không lấy được transactionUuid để gửi CQT.';
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'cqt_error',
                'send_cqt_status' => 2,
                'cqt_error_message' => $message,
                'error_message' => $message,
                'updated_at' => current_time('mysql'),
            ]);
            return ['success' => false, 'invoice_record_id' => $tracking_id, 'invoice_no' => $invoice_no, 'message' => $message];
        }

        return $this->send_return_adjustment_cqt($tracking_id, $issued_uuid, $return_code, $created_by, $invoice_no);
    }

    private function send_return_adjustment_cqt($tracking_id, $transaction_uuid, $return_code, $created_by, $invoice_no = '')
    {
        $settings = self::get_settings_for_invoice($tracking_id);
        $payload_result = $this->flow_service->build_send_cqt_payload(
            sanitize_text_field($settings['supplier_tax_code'] ?? ''),
            $transaction_uuid
        );
        if (empty($payload_result['success'])) {
            return ['success' => false, 'invoice_record_id' => $tracking_id, 'invoice_no' => $invoice_no, 'message' => $payload_result['message'] ?? 'Không dựng được payload gửi CQT.'];
        }

        $cqt_payload = $payload_result['payload'];
        $cqt_payload['local_ledger_code'] = $return_code;
        $cqt_result = $this->submit_invoice_payload($cqt_payload, 'send_cqt', [
            'skip_persist' => true,
            'step_name' => 'return_adjustment_send_cqt',
            'invoice_record_id' => $tracking_id,
            'transaction_uuid' => $transaction_uuid,
            'created_by' => $created_by,
            'sale_ledger_id' => 0,
        ]);

        if (empty($cqt_result['success'])) {
            $message = sanitize_text_field($cqt_result['message'] ?? 'Lỗi gửi hóa đơn điều chỉnh lên CQT.');
            $this->update_auto_flow_tracking($tracking_id, [
                'invoice_state' => 'cqt_error',
                'send_cqt_status' => 2,
                'cqt_request_payload' => wp_json_encode($cqt_payload, JSON_UNESCAPED_UNICODE),
                'cqt_response_payload' => wp_json_encode($cqt_result, JSON_UNESCAPED_UNICODE),
                'cqt_http_code' => intval($cqt_result['http_code'] ?? 0),
                'cqt_error_message' => $message,
                'error_message' => $message,
                'updated_at' => current_time('mysql'),
            ]);
            return ['success' => false, 'invoice_record_id' => $tracking_id, 'invoice_no' => $invoice_no, 'message' => $message];
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

        return [
            'success' => true,
            'invoice_record_id' => $tracking_id,
            'invoice_no' => $invoice_no,
            'transaction_uuid' => $transaction_uuid,
            'message' => 'Đã phát hành hóa đơn điều chỉnh giảm và gửi CQT thành công.',
        ];
    }

    /** Chỉ gửi lại CQT cho hóa đơn điều chỉnh đã issue; không phát hành bản mới. */
    public function retry_return_adjustment_cqt($tracking_id, $queue_id = 0)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return ['status' => 'error', 'message' => 'Thiếu bảng hóa đơn.'];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . ' WHERE local_viettel_invoice_id = %d AND request_mode = %s LIMIT 1',
            $tracking_id,
            'adjustment'
        ), ARRAY_A);
        if (empty($row) || intval($row['issue_status'] ?? 0) !== 1) {
            return ['status' => 'error', 'message' => 'Hóa đơn điều chỉnh chưa phát hành thành công.'];
        }

        $result = $this->send_return_adjustment_cqt(
            $tracking_id,
            sanitize_text_field($row['issue_transaction_uuid'] ?? ''),
            sanitize_text_field($row['local_ledger_code'] ?? ''),
            get_current_user_id(),
            sanitize_text_field($row['viettel_invoice_no'] ?? '')
        );
        return [
            'id' => intval($queue_id),
            'status' => !empty($result['success']) ? 'done' : 'error',
            'invoice_record_id' => $tracking_id,
            'invoice_no' => sanitize_text_field($result['invoice_no'] ?? ''),
            'message' => $result['message'] ?? '',
            'success' => !empty($result['success']),
        ];
    }

    public function get_create_view_data()
    {
        $settings = self::get_settings();
        $return_adjustments = [];
        if (class_exists('TGS_Viettel_Invoice_Return_Adjustment')) {
            $return_service = TGS_Viettel_Invoice_Return_Adjustment::instance();
            if ($return_service) {
                $return_adjustments = $return_service->list_recent(50);
            }
        }

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
            'return_adjustments' => $return_adjustments,
        ];
    }
}

function tgs_viettel_invoice()
{
    return TGS_Viettel_Invoice_Plugin::instance();
}

tgs_viettel_invoice();
