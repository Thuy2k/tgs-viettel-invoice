<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Network-wide invoice issuer clusters.
 *
 * A cluster is an invoice issuer profile, not merely an organisation branch.
 */
class TGS_Viettel_Invoice_Clusters
{
    const DB_VERSION = '1.0.0';
    const DB_OPTION = 'tgs_viettel_invoice_cluster_db_version';
    const NONCE_ACTION = 'tgs_viettel_invoice_nonce';

    private static $instance;
    private $resolved = [];

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Run on init as POS/front-end may issue an invoice before an admin opens settings after an upgrade.
        add_action('init', [$this, 'maybe_install'], 1);
        add_action('admin_init', [$this, 'register_capabilities']);

        $actions = [
            'list' => 'ajax_list',
            'get' => 'ajax_get',
            'save' => 'ajax_save',
            'deactivate' => 'ajax_deactivate',
            'search_shops' => 'ajax_search_shops',
            'unassigned_shops' => 'ajax_unassigned_shops',
            'test_connection' => 'ajax_test_connection',
            'audit' => 'ajax_audit',
            'migration_preview' => 'ajax_migration_preview',
        ];
        foreach ($actions as $action => $method) {
            add_action('wp_ajax_tgs_viettel_invoice_cluster_' . $action, [$this, $method]);
        }
    }

    public function tables()
    {
        global $wpdb;
        return [
            'clusters' => $wpdb->base_prefix . 'tgs_viettel_invoice_clusters',
            'shops' => $wpdb->base_prefix . 'tgs_viettel_invoice_cluster_shops',
            'users' => $wpdb->base_prefix . 'tgs_viettel_invoice_cluster_users',
            'audit' => $wpdb->base_prefix . 'tgs_viettel_invoice_cluster_audit',
            'snapshots' => $wpdb->base_prefix . 'tgs_viettel_invoice_config_snapshots',
        ];
    }

    public function maybe_install()
    {
        $version = is_multisite() ? get_site_option(self::DB_OPTION, '') : get_option(self::DB_OPTION, '');
        if ($version === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = $this->tables();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$t['clusters']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code varchar(80) NOT NULL,
            name varchar(190) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            supplier_tax_code varchar(32) NOT NULL DEFAULT '',
            legal_name varchar(255) NOT NULL DEFAULT '',
            legal_address text NULL,
            legal_phone varchar(50) NOT NULL DEFAULT '',
            settings_json longtext NULL,
            secret_payload longtext NULL,
            config_version bigint(20) unsigned NOT NULL DEFAULT 1,
            effective_from datetime NULL,
            effective_to datetime NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY supplier_tax_code (supplier_tax_code)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t['shops']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cluster_id bigint(20) unsigned NOT NULL,
            blog_id bigint(20) unsigned NOT NULL,
            assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY blog_id (blog_id),
            KEY cluster_id (cluster_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t['users']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cluster_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            access_level varchar(20) NOT NULL DEFAULT 'viewer',
            assigned_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cluster_user (cluster_id,user_id),
            KEY user_id (user_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t['audit']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(80) NOT NULL,
            before_json longtext NULL,
            after_json longtext NULL,
            request_id varchar(64) NOT NULL DEFAULT '',
            ip_address varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY cluster_id (cluster_id),
            KEY blog_id (blog_id),
            KEY created_at (created_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$t['snapshots']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL,
            invoice_record_id bigint(20) unsigned NOT NULL,
            sale_ledger_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cluster_code varchar(80) NOT NULL DEFAULT '',
            config_version bigint(20) unsigned NOT NULL DEFAULT 0,
            settings_json longtext NOT NULL,
            credential_fingerprint varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_source (blog_id,invoice_record_id),
            KEY sale_source (blog_id,sale_ledger_id),
            KEY cluster_id (cluster_id)
        ) {$charset};");

        if (is_multisite()) {
            update_site_option(self::DB_OPTION, self::DB_VERSION);
        } else {
            update_option(self::DB_OPTION, self::DB_VERSION, false);
        }
    }

    public function register_capabilities()
    {
        $caps = [
            'manage_tgs_invoice_clusters',
            'manage_tgs_invoice_cluster_settings',
            'view_tgs_invoice_cluster_reports',
            'manage_tgs_invoice_shop_overrides',
            'view_tgs_invoice_audit',
        ];
        $role = get_role('administrator');
        if ($role) {
            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    public function can_manage_all()
    {
        if (is_multisite()) {
            return is_super_admin();
        }
        return current_user_can('manage_tgs_invoice_clusters') || current_user_can('manage_options');
    }

    public function can_access_cluster($cluster_id, $write = false)
    {
        if ($this->can_manage_all()) {
            return true;
        }
        $cap = $write ? 'manage_tgs_invoice_cluster_settings' : 'view_tgs_invoice_cluster_reports';
        if (!current_user_can($cap)) {
            return false;
        }
        global $wpdb;
        $table = $this->tables()['users'];
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE cluster_id = %d AND user_id = %d LIMIT 1",
            $cluster_id,
            get_current_user_id()
        ));
    }

    private function verify_ajax($manage_all = false)
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $allowed = $manage_all
            ? $this->can_manage_all()
            : ($this->can_manage_all() || current_user_can('manage_tgs_invoice_cluster_settings') || current_user_can('view_tgs_invoice_cluster_reports'));
        if (!$allowed) {
            wp_send_json_error(['message' => 'Bạn không có quyền quản lý cụm hóa đơn.'], 403);
        }
    }

    public function resolve($blog_id, array $legacy_settings)
    {
        $blog_id = max(1, (int) $blog_id);
        if (isset($this->resolved[$blog_id])) {
            return array_merge($legacy_settings, $this->resolved[$blog_id]);
        }

        global $wpdb;
        $t = $this->tables();
        $now = current_time('mysql');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT c.* FROM {$t['shops']} cs INNER JOIN {$t['clusters']} c ON c.id = cs.cluster_id
             WHERE cs.blog_id = %d LIMIT 1",
            $blog_id
        ), ARRAY_A);

        if (!$row) {
            $legacy_settings['_cluster'] = [
                'assigned' => false,
                'legacy_fallback' => true,
                'blog_id' => $blog_id,
            ];
            return $legacy_settings;
        }

        $settings = json_decode((string) $row['settings_json'], true);
        $settings = is_array($settings) ? $settings : [];
        $secrets = $this->decrypt_secrets((string) $row['secret_payload']);
        $cluster_settings = array_merge($settings, $secrets, [
            'company_name' => (string) $row['legal_name'],
            'supplier_tax_code' => (string) $row['supplier_tax_code'],
            'company_address' => (string) $row['legal_address'],
            'company_phone' => (string) $row['legal_phone'],
            '_cluster' => [
                'assigned' => true,
                'legacy_fallback' => false,
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'config_version' => (int) $row['config_version'],
                'blog_id' => $blog_id,
                'status' => (string) $row['status'],
                'effective' => ($row['status'] === 'active'
                    && (empty($row['effective_from']) || $row['effective_from'] <= $now)
                    && (empty($row['effective_to']) || $row['effective_to'] >= $now)),
            ],
        ]);
        $this->resolved[$blog_id] = $cluster_settings;
        return array_merge($legacy_settings, $cluster_settings);
    }

    public function get_snapshot_settings($blog_id, $invoice_record_id)
    {
        global $wpdb;
        $table = $this->tables()['snapshots'];
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT settings_json FROM {$table} WHERE blog_id = %d AND invoice_record_id = %d LIMIT 1",
            (int) $blog_id,
            (int) $invoice_record_id
        ));
        if (!$json) {
            return [];
        }
        $settings = json_decode((string) $json, true);
        if (!is_array($settings)) {
            return [];
        }
        if (!empty($settings['_encrypted_secrets'])) {
            $settings = array_merge($settings, $this->decrypt_secrets($settings['_encrypted_secrets']));
            unset($settings['_encrypted_secrets']);
        }
        return $settings;
    }

    public function save_snapshot($blog_id, $invoice_record_id, $sale_ledger_id, array $settings)
    {
        if ($invoice_record_id <= 0) {
            return;
        }
        global $wpdb;
        $table = $this->tables()['snapshots'];
        $cluster = isset($settings['_cluster']) && is_array($settings['_cluster']) ? $settings['_cluster'] : [];
        $secret = [
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'access_token' => (string) ($settings['access_token'] ?? ''),
        ];
        unset($settings['username'], $settings['password'], $settings['access_token']);
        $settings['_encrypted_secrets'] = $this->encrypt_secrets($secret);
        $fingerprint = hash('sha256', implode('|', $secret));
        $wpdb->replace($table, [
            'blog_id' => (int) $blog_id,
            'invoice_record_id' => (int) $invoice_record_id,
            'sale_ledger_id' => (int) $sale_ledger_id,
            'cluster_id' => (int) ($cluster['id'] ?? 0),
            'cluster_code' => sanitize_text_field($cluster['code'] ?? ''),
            'config_version' => (int) ($cluster['config_version'] ?? 0),
            'settings_json' => wp_json_encode($settings, JSON_UNESCAPED_UNICODE),
            'credential_fingerprint' => $fingerprint,
            'created_at' => current_time('mysql'),
        ]);
    }

    private function crypto_key()
    {
        $source = defined('AUTH_KEY') ? AUTH_KEY : wp_salt('auth');
        return hash('sha256', $source . '|tgs-viettel-invoice', true);
    }

    private function encrypt_secrets(array $secrets)
    {
        $plain = wp_json_encode($secrets, JSON_UNESCAPED_UNICODE);
        if (!function_exists('openssl_encrypt')) return '';
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return '';
        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt_secrets($payload)
    {
        if (!$payload) {
            return [];
        }
        if (strpos($payload, 'gcm:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) < 29) {
                return [];
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $decoded = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        } else {
            return [];
        }
        $data = json_decode((string) $decoded, true);
        return is_array($data) ? $data : [];
    }

    private function public_cluster(array $row, $with_details = false)
    {
        $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
        $settings = is_array($settings) ? $settings : [];
        $result = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'supplier_tax_code' => (string) $row['supplier_tax_code'],
            'legal_name' => (string) $row['legal_name'],
            'legal_address' => (string) $row['legal_address'],
            'legal_phone' => (string) $row['legal_phone'],
            'config_version' => (int) $row['config_version'],
            'effective_from' => $row['effective_from'],
            'effective_to' => $row['effective_to'],
            'updated_at' => $row['updated_at'],
        ];
        if ($with_details) {
            $secrets = $this->decrypt_secrets((string) ($row['secret_payload'] ?? ''));
            $result['settings'] = $settings;
            $result['has_password'] = !empty($secrets['password']);
            $result['has_access_token'] = !empty($secrets['access_token']);
            $result['username'] = (string) ($secrets['username'] ?? '');
        } else {
            $result['template_code'] = (string) ($settings['default_template_code'] ?? '');
            $result['invoice_series'] = (string) ($settings['default_invoice_series'] ?? '');
        }
        return $result;
    }

    public function ajax_list()
    {
        $this->verify_ajax();
        global $wpdb;
        $t = $this->tables();
        $where = '';
        $args = [];
        if (!$this->can_manage_all()) {
            $where = " INNER JOIN {$t['users']} cu ON cu.cluster_id = c.id AND cu.user_id = %d";
            $args[] = get_current_user_id();
        }
        $sql = "SELECT c.*, COUNT(DISTINCT cs.blog_id) shop_count FROM {$t['clusters']} c {$where}
                LEFT JOIN {$t['shops']} cs ON cs.cluster_id = c.id GROUP BY c.id ORDER BY c.status = 'active' DESC, c.name ASC";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $items = [];
        foreach ((array) $rows as $row) {
            $item = $this->public_cluster($row);
            $item['shop_count'] = (int) $row['shop_count'];
            $items[] = $item;
        }
        wp_send_json_success(['items' => $items, 'can_manage_all' => $this->can_manage_all()]);
    }

    public function ajax_get()
    {
        $this->verify_ajax();
        $id = (int) ($_POST['cluster_id'] ?? 0);
        if (!$this->can_access_cluster($id, false)) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem cụm này.'], 403);
        }
        global $wpdb;
        $t = $this->tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clusters']} WHERE id = %d LIMIT 1", $id), ARRAY_A);
        if (!$row) {
            wp_send_json_error(['message' => 'Không tìm thấy cụm.'], 404);
        }
        $shops = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM {$t['shops']} WHERE cluster_id = %d ORDER BY blog_id", $id));
        $users = $wpdb->get_results($wpdb->prepare("SELECT user_id, access_level FROM {$t['users']} WHERE cluster_id = %d", $id), ARRAY_A);
        $item = $this->public_cluster($row, true);
        $item['shop_ids'] = array_map('intval', $shops);
        $item['users'] = $users;
        wp_send_json_success($item);
    }

    private function clean_settings($raw)
    {
        return [
            'api_base_url' => esc_url_raw(trim($raw['api_base_url'] ?? '')),
            'auth_mode' => in_array(($raw['auth_mode'] ?? ''), ['basic', 'token'], true) ? $raw['auth_mode'] : 'basic',
            'verify_ssl' => !empty($raw['verify_ssl']) ? 1 : 0,
            'default_template_code' => sanitize_text_field($raw['default_template_code'] ?? ''),
            'default_invoice_series' => sanitize_text_field($raw['default_invoice_series'] ?? ''),
            'default_payment_method' => sanitize_text_field($raw['default_payment_method'] ?? ''),
            'auto_enabled' => !empty($raw['auto_enabled']) ? 1 : 0,
            'auto_mode' => in_array(($raw['auto_mode'] ?? ''), ['draft', 'issue'], true) ? $raw['auto_mode'] : 'issue',
        ];
    }

    private function validate_cluster_data(array $data, array $settings, array $secrets)
    {
        $errors = [];
        if ($data['name'] === '') $errors[] = 'Thiếu tên cụm.';
        if ($data['code'] === '') $errors[] = 'Thiếu mã cụm.';
        if ($data['status'] === 'active') {
            if ($data['supplier_tax_code'] === '') $errors[] = 'Thiếu MST người bán.';
            if ($data['legal_name'] === '') $errors[] = 'Thiếu tên pháp lý người bán.';
            if (empty($settings['api_base_url'])) $errors[] = 'Thiếu API base URL.';
            if (empty($settings['default_template_code'])) $errors[] = 'Thiếu template code.';
            if (empty($settings['default_invoice_series'])) $errors[] = 'Thiếu invoice series.';
            if ($settings['auth_mode'] === 'token' && empty($secrets['access_token'])) $errors[] = 'Thiếu access token.';
            if ($settings['auth_mode'] === 'basic' && (empty($secrets['username']) || empty($secrets['password']))) $errors[] = 'Thiếu username hoặc password.';
        }
        return $errors;
    }

    public function ajax_save()
    {
        $this->verify_ajax();
        global $wpdb;
        $t = $this->tables();
        $id = (int) ($_POST['cluster_id'] ?? 0);
        if ($id > 0 && !$this->can_access_cluster($id, true)) {
            wp_send_json_error(['message' => 'Bạn không có quyền sửa cụm này.'], 403);
        }
        if ($id <= 0 && !$this->can_manage_all()) {
            wp_send_json_error(['message' => 'Chỉ Admin tổng được tạo cụm.'], 403);
        }

        $raw = isset($_POST['cluster']) && is_array($_POST['cluster']) ? wp_unslash($_POST['cluster']) : [];
        $shop_ids = isset($_POST['shop_ids']) ? json_decode(wp_unslash($_POST['shop_ids']), true) : [];
        $shop_ids = array_values(array_unique(array_filter(array_map('intval', is_array($shop_ids) ? $shop_ids : []))));
        $requested_users = isset($_POST['users']) ? json_decode(wp_unslash($_POST['users']), true) : [];
        $requested_users = is_array($requested_users) ? $requested_users : [];
        $force_move = !empty($_POST['force_move']) && $this->can_manage_all();
        $existing = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clusters']} WHERE id = %d", $id), ARRAY_A) : null;
        $existing_secrets = $existing ? $this->decrypt_secrets((string) $existing['secret_payload']) : [];
        $settings = $this->clean_settings($raw);
        $secrets = [
            'username' => sanitize_text_field($raw['username'] ?? ($existing_secrets['username'] ?? '')),
            'password' => (($raw['password'] ?? '') === '' || ($raw['password'] ?? '') === '********') ? ($existing_secrets['password'] ?? '') : (string) $raw['password'],
            'access_token' => (($raw['access_token'] ?? '') === '' || ($raw['access_token'] ?? '') === '********') ? ($existing_secrets['access_token'] ?? '') : (string) $raw['access_token'],
        ];
        $data = [
            'code' => sanitize_key($raw['code'] ?? ''),
            'name' => sanitize_text_field($raw['name'] ?? ''),
            'status' => in_array(($raw['status'] ?? ''), ['draft', 'active', 'inactive'], true) ? $raw['status'] : 'draft',
            'supplier_tax_code' => preg_replace('/[^0-9-]/', '', (string) ($raw['supplier_tax_code'] ?? '')),
            'legal_name' => sanitize_text_field($raw['legal_name'] ?? ''),
            'legal_address' => sanitize_textarea_field($raw['legal_address'] ?? ''),
            'legal_phone' => sanitize_text_field($raw['legal_phone'] ?? ''),
            'settings_json' => wp_json_encode($settings, JSON_UNESCAPED_UNICODE),
            'secret_payload' => $this->encrypt_secrets($secrets),
            'effective_from' => !empty($raw['effective_from']) ? sanitize_text_field($raw['effective_from']) : null,
            'effective_to' => !empty($raw['effective_to']) ? sanitize_text_field($raw['effective_to']) : null,
            'updated_by' => get_current_user_id(),
            'updated_at' => current_time('mysql'),
        ];
        $errors = $this->validate_cluster_data($data, $settings, $secrets);
        if (($data['status'] === 'active' || array_filter($secrets)) && !function_exists('openssl_encrypt')) {
            $errors[] = 'Máy chủ thiếu OpenSSL nên không thể lưu an toàn tài khoản Viettel.';
        }
        if ($errors) {
            wp_send_json_error(['message' => implode(' ', $errors), 'errors' => $errors], 400);
        }
        if ($data['status'] === 'active' && !$shop_ids) {
            wp_send_json_error(['message' => 'Cụm active phải có ít nhất một shop.'], 400);
        }
        $requested_version = (int) ($raw['config_version'] ?? 0);
        if ($existing && $requested_version !== (int) $existing['config_version']) {
            wp_send_json_error(['message' => 'Cấu hình đã được người khác cập nhật. Hãy tải lại trước khi lưu.', 'code' => 'version_conflict'], 409);
        }

        if ($existing && !$this->can_manage_all()) {
            $current_shop_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT blog_id FROM {$t['shops']} WHERE cluster_id = %d ORDER BY blog_id",
                $id
            )));
            sort($current_shop_ids);
            $compare_shop_ids = $shop_ids;
            sort($compare_shop_ids);
            if ($current_shop_ids !== $compare_shop_ids) {
                wp_send_json_error(['message' => 'Chỉ Admin tổng được thay đổi shop thuộc cụm.'], 403);
            }
        }

        if ($shop_ids) {
            $placeholders = implode(',', array_fill(0, count($shop_ids), '%d'));
            $params = array_merge($shop_ids, [$id]);
            $conflicts = $wpdb->get_results($wpdb->prepare(
                "SELECT cs.blog_id, cs.cluster_id, c.name cluster_name FROM {$t['shops']} cs
                 INNER JOIN {$t['clusters']} c ON c.id = cs.cluster_id
                 WHERE cs.blog_id IN ({$placeholders}) AND cs.cluster_id <> %d",
                $params
            ), ARRAY_A);
            if ($conflicts && !$force_move) {
                wp_send_json_error(['message' => 'Có shop đang thuộc cụm khác. Hãy dùng thao tác chuyển cụm.', 'code' => 'shop_conflict', 'conflicts' => $conflicts], 409);
            }
            if ($conflicts && $force_move) {
                $pending = [];
                foreach ($conflicts as $conflict) {
                    if ($this->shop_has_pending_invoice((int) $conflict['blog_id'])) {
                        $pending[] = (int) $conflict['blog_id'];
                    }
                }
                if ($pending) {
                    wp_send_json_error([
                        'message' => 'Không thể chuyển cụm vì shop đang có hóa đơn chờ xử lý CQT: #' . implode(', #', $pending) . '.',
                        'code' => 'pending_invoices',
                        'blog_ids' => $pending,
                    ], 409);
                }
            }
        }

        $before = $existing ? $this->public_cluster($existing, true) : [];
        $wpdb->query('START TRANSACTION');
        try {
            if ($existing) {
                $data['config_version'] = (int) $existing['config_version'] + 1;
                $ok = $wpdb->update($t['clusters'], $data, ['id' => $id]);
            } else {
                $data['config_version'] = 1;
                $data['created_by'] = get_current_user_id();
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($t['clusters'], $data);
                $id = (int) $wpdb->insert_id;
            }
            if ($ok === false || $id <= 0) {
                throw new Exception($wpdb->last_error ?: 'Không lưu được cụm.');
            }
            $wpdb->delete($t['shops'], ['cluster_id' => $id], ['%d']);
            if ($force_move && $shop_ids) {
                $placeholders = implode(',', array_fill(0, count($shop_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$t['shops']} WHERE blog_id IN ({$placeholders}) AND cluster_id <> %d",
                    array_merge($shop_ids, [$id])
                ));
            }
            foreach ($shop_ids as $blog_id) {
                $site = get_site($blog_id);
                if (!$site || !empty($site->deleted) || !empty($site->archived)) {
                    throw new Exception('Shop #' . $blog_id . ' không tồn tại hoặc đã ngừng hoạt động.');
                }
                $inserted = $wpdb->insert($t['shops'], [
                    'cluster_id' => $id,
                    'blog_id' => $blog_id,
                    'assigned_by' => get_current_user_id(),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
                if (!$inserted) {
                    throw new Exception('Không gán được shop #' . $blog_id . ': ' . $wpdb->last_error);
                }
            }
            if ($this->can_manage_all()) {
                $wpdb->delete($t['users'], ['cluster_id' => $id], ['%d']);
                foreach ($requested_users as $user_access) {
                    $user_id = (int) ($user_access['user_id'] ?? 0);
                    $access_level = sanitize_key($user_access['access_level'] ?? 'viewer');
                    if ($user_id <= 0 || !get_user_by('id', $user_id)) continue;
                    if (!in_array($access_level, ['viewer', 'accountant', 'manager'], true)) $access_level = 'viewer';
                    $wpdb->insert($t['users'], [
                        'cluster_id' => $id,
                        'user_id' => $user_id,
                        'access_level' => $access_level,
                        'assigned_by' => get_current_user_id(),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        $this->resolved = [];
        $after_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['clusters']} WHERE id = %d", $id), ARRAY_A);
        $this->audit($id, 0, $existing ? 'update_cluster' : 'create_cluster', $before, $this->public_cluster($after_row, true));
        wp_send_json_success(['message' => 'Đã lưu cụm hóa đơn.', 'cluster_id' => $id, 'config_version' => (int) $after_row['config_version']]);
    }

    public function ajax_deactivate()
    {
        $this->verify_ajax(true);
        $id = (int) ($_POST['cluster_id'] ?? 0);
        global $wpdb;
        $table = $this->tables()['clusters'];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Không tìm thấy cụm.'], 404);
        $wpdb->update($table, ['status' => 'inactive', 'config_version' => (int) $row['config_version'] + 1, 'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql')], ['id' => $id]);
        $this->audit($id, 0, 'deactivate_cluster', $this->public_cluster($row), ['status' => 'inactive']);
        wp_send_json_success(['message' => 'Đã ngừng cụm. Việc phát hành của các shop trong cụm sẽ bị chặn cho tới khi cụm được kích hoạt lại hoặc shop được chuyển cụm.']);
    }

    private function site_item($site, array $assignments)
    {
        $id = (int) $site->blog_id;
        $details = get_blog_details($id);
        return [
            'blog_id' => $id,
            'name' => $details ? (string) $details->blogname : ('Shop #' . $id),
            'domain' => (string) $site->domain,
            'path' => (string) $site->path,
            'cluster_id' => isset($assignments[$id]) ? (int) $assignments[$id]['cluster_id'] : 0,
            'cluster_name' => isset($assignments[$id]) ? (string) $assignments[$id]['cluster_name'] : '',
        ];
    }

    private function shop_has_pending_invoice($blog_id)
    {
        global $wpdb;
        $table = $wpdb->get_blog_prefix((int) $blog_id) . 'local_viettel_invoice';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) return false;
        return (bool) $wpdb->get_var(
            "SELECT local_viettel_invoice_id FROM {$table}
             WHERE invoice_state IN ('pending','issued','issue_error','cqt_error')
                OR (COALESCE(issue_status,0) = 1 AND COALESCE(send_cqt_status,0) <> 1)
             LIMIT 1"
        );
    }

    public function ajax_search_shops()
    {
        $this->verify_ajax();
        $search = sanitize_text_field(wp_unslash($_POST['search'] ?? ''));
        $selected = json_decode(wp_unslash($_POST['selected_ids'] ?? '[]'), true);
        $selected = array_values(array_filter(array_map('intval', is_array($selected) ? $selected : [])));
        global $wpdb;
        $t = $this->tables();
        $rows = $wpdb->get_results("SELECT cs.blog_id, cs.cluster_id, c.name cluster_name FROM {$t['shops']} cs INNER JOIN {$t['clusters']} c ON c.id = cs.cluster_id", ARRAY_A);
        $assignments = [];
        foreach ($rows as $row) $assignments[(int) $row['blog_id']] = $row;
        $sites = get_sites(['number' => 1000, 'deleted' => 0, 'archived' => 0, 'spam' => 0]);
        $items = [];
        $needle = function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search);
        foreach ($sites as $site) {
            $item = $this->site_item($site, $assignments);
            $haystack = $item['blog_id'] . ' ' . $item['name'] . ' ' . $item['domain'] . $item['path'];
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack) : strtolower($haystack);
            if ($needle !== '' && strpos($haystack, $needle) === false && !in_array($item['blog_id'], $selected, true)) continue;
            $items[] = $item;
            if (count($items) >= 200) break;
        }
        wp_send_json_success(['items' => $items]);
    }

    public function ajax_unassigned_shops()
    {
        $this->verify_ajax();
        global $wpdb;
        $table = $this->tables()['shops'];
        $assigned = array_map('intval', $wpdb->get_col("SELECT blog_id FROM {$table}"));
        $items = [];
        foreach (get_sites(['number' => 1000, 'deleted' => 0, 'archived' => 0, 'spam' => 0]) as $site) {
            if (!in_array((int) $site->blog_id, $assigned, true)) $items[] = $this->site_item($site, []);
        }
        wp_send_json_success(['items' => $items, 'count' => count($items)]);
    }

    public function ajax_migration_preview()
    {
        $this->verify_ajax(true);
        $common = is_multisite()
            ? get_site_option(TGS_Viettel_Invoice_Plugin::OPTION_COMMON_SETTINGS, [])
            : get_option(TGS_Viettel_Invoice_Plugin::OPTION_COMMON_SETTINGS, []);
        $common = is_array($common) ? $common : [];
        $groups = [];
        $incomplete = [];
        foreach (get_sites(['number' => 1000, 'deleted' => 0, 'archived' => 0, 'spam' => 0]) as $site) {
            $blog_id = (int) $site->blog_id;
            $shop = get_blog_option($blog_id, TGS_Viettel_Invoice_Plugin::OPTION_SETTINGS, []);
            $shop = is_array($shop) ? $shop : [];
            $merged = array_merge(TGS_Viettel_Invoice_Plugin::get_default_settings(), $common, $shop);
            $tax_code = sanitize_text_field($merged['supplier_tax_code'] ?? '');
            $username = sanitize_text_field($merged['username'] ?? '');
            $template = sanitize_text_field($merged['default_template_code'] ?? '');
            $series = sanitize_text_field($merged['default_invoice_series'] ?? '');
            if ($tax_code === '' || $username === '' || $template === '' || $series === '') {
                $incomplete[] = $blog_id;
            }
            $fingerprint = hash('sha256', implode('|', [
                $tax_code,
                $username,
                (string) ($merged['password'] ?? ''),
                (string) ($merged['access_token'] ?? ''),
                $template,
                $series,
            ]));
            if (!isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'supplier_tax_code' => $tax_code,
                    'username' => $username,
                    'template_code' => $template,
                    'invoice_series' => $series,
                    'shop_ids' => [],
                ];
            }
            $groups[$fingerprint]['shop_ids'][] = $blog_id;
        }
        $items = array_values($groups);
        usort($items, function ($a, $b) { return count($b['shop_ids']) <=> count($a['shop_ids']); });
        wp_send_json_success([
            'groups' => $items,
            'group_count' => count($items),
            'incomplete_shop_ids' => $incomplete,
            'message' => 'Đây chỉ là đề xuất theo MST + tài khoản + mẫu số + ký hiệu; kế toán phải duyệt trước khi tạo cụm.',
        ]);
    }

    public function ajax_test_connection()
    {
        $this->verify_ajax();
        $id = (int) ($_POST['cluster_id'] ?? 0);
        if (!$this->can_access_cluster($id, true)) wp_send_json_error(['message' => 'Bạn không có quyền kiểm tra cụm này.'], 403);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables()['clusters']} WHERE id = %d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Không tìm thấy cụm.'], 404);
        $settings = array_merge((array) json_decode($row['settings_json'], true), $this->decrypt_secrets($row['secret_payload']));
        $response = wp_remote_get($settings['api_base_url'], ['timeout' => 15, 'sslverify' => !empty($settings['verify_ssl']), 'httpversion' => '1.1', 'headers' => ['Connection' => 'keep-alive']]);
        if (is_wp_error($response)) wp_send_json_error(['message' => 'Không kết nối được: ' . $response->get_error_message()], 400);
        $code = (int) wp_remote_retrieve_response_code($response);
        $this->audit($id, 0, 'test_connection', [], ['http_code' => $code]);
        wp_send_json_success(['message' => 'Đã kết nối tới máy chủ API (HTTP ' . $code . '). Đây chưa phải thao tác phát hành hóa đơn.', 'http_code' => $code]);
    }

    public function ajax_audit()
    {
        $this->verify_ajax();
        $id = (int) ($_POST['cluster_id'] ?? 0);
        if (!$this->can_access_cluster($id, false)) wp_send_json_error(['message' => 'Bạn không có quyền xem lịch sử cụm.'], 403);
        global $wpdb;
        $table = $this->tables()['audit'];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, blog_id, user_id, action, request_id, ip_address, created_at FROM {$table} WHERE cluster_id = %d ORDER BY id DESC LIMIT 100", $id), ARRAY_A);
        wp_send_json_success(['items' => $rows]);
    }

    private function audit($cluster_id, $blog_id, $action, $before, $after)
    {
        global $wpdb;
        $request_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('vi_', true);
        $wpdb->insert($this->tables()['audit'], [
            'cluster_id' => (int) $cluster_id,
            'blog_id' => (int) $blog_id,
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'before_json' => wp_json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_json' => wp_json_encode($after, JSON_UNESCAPED_UNICODE),
            'request_id' => $request_id,
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'created_at' => current_time('mysql'),
        ]);
    }
}
