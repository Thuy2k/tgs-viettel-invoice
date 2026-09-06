<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HOÁ ĐƠN THAY THẾ (Viettel adjustmentType = 3).
 *
 * Dùng khi hoá đơn ĐÃ PHÁT HÀNH + đã gửi CQT nhưng SAI THÔNG TIN BÊN MUA (MST,
 * tên, địa chỉ, email…). Phát hành lại một hoá đơn "thay thế toàn bộ" cho hoá
 * đơn gốc: giữ nguyên hàng hoá / tiền / thuế của hoá đơn gốc, CHỈ đổi buyerInfo
 * cho chuẩn. Phát hành xong tự gửi CQT.
 *
 * Song song cấu trúc với TGS_Viettel_Invoice_Return_Adjustment. Chạy TRÊN site
 * của shop (API + cấu hình Viettel bám site). Bên tgs-bc-tk gọi qua
 * switch_to_blog rồi ::instance()->run_for_sale().
 *
 * CÔNG TẮC: MẶC ĐỊNH BẬT. Kill switch khi cần dừng gấp (trên site shop):
 *   update_option('tgs_viettel_replacement_enabled', 0)
 */
class TGS_Viettel_Invoice_Replacement
{
    private static $instance;
    private $plugin;

    /** adjustmentType của SInvoice: 3 = thay thế toàn bộ hoá đơn gốc. */
    const ADJ_TYPE_REPLACE = '3';

    public static function instance($plugin = null)
    {
        if (!self::$instance && $plugin) {
            self::$instance = new self($plugin);
        }
        return self::$instance;
    }

    private function __construct($plugin)
    {
        $this->plugin = $plugin;
        add_action('wp_ajax_tgs_viettel_replace_preview', [$this, 'ajax_preview']);
        add_action('wp_ajax_tgs_viettel_replace_confirm', [$this, 'ajax_confirm']);
        add_action('wp_ajax_tgs_viettel_replace_retry', [$this, 'ajax_retry']);
    }

    public static function is_enabled()
    {
        // Mặc định BẬT. Chỉ tắt khi option được đặt rõ = 0 (kill switch).
        return (int) get_option('tgs_viettel_replacement_enabled', 1) !== 0;
    }

    private function table()
    {
        if (class_exists('TGS_Viettel_Invoice_Clusters')) {
            $tables = TGS_Viettel_Invoice_Clusters::instance()->tables();
            $t = (string) ($tables['replacements'] ?? '');
            if ($t !== '') {
                return $t;
            }
        }
        global $wpdb;
        return $wpdb->base_prefix . 'tgs_viettel_invoice_replacements';
    }

    /**
     * Tự tạo bảng queue nếu chưa có — phòng khi maybe_install() của cluster
     * lỡ chạy trong khoảng bump DB_VERSION mà chưa có DDL này (đã gặp).
     */
    private static $table_ready = false;
    private function ensure_table()
    {
        if (self::$table_ready) {
            return;
        }
        global $wpdb;
        $t = $this->table();
        if ($t === '') {
            return;
        }
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
            self::$table_ready = true;
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            blog_id bigint(20) unsigned NOT NULL,
            sale_ledger_id bigint(20) unsigned NOT NULL,
            original_invoice_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
            replacement_invoice_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'pending',
            attempt_count int unsigned NOT NULL DEFAULT 0,
            transaction_uuid varchar(64) NOT NULL DEFAULT '',
            original_invoice_no varchar(255) NOT NULL DEFAULT '',
            replacement_invoice_no varchar(255) NOT NULL DEFAULT '',
            corrected_buyer_json longtext NULL,
            reason text NULL,
            request_payload longtext NULL,
            response_payload longtext NULL,
            error_message text NULL,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            processed_at datetime NULL,
            PRIMARY KEY  (id),
            KEY sale_source (blog_id,sale_ledger_id),
            KEY original_invoice (original_invoice_record_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");
        self::$table_ready = true;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * ĐIỂM VÀO (từ tgs-bc-tk, sau switch_to_blog)
     * ═══════════════════════════════════════════════════════════════════════ */

    /**
     * @param int    $sale_id
     * @param array  $corrected_buyer  ['buyerName','buyerTaxCode','buyerAddressLine','buyerPhoneNumber','buyerEmail','buyerLegalName']
     * @param string $reason
     * @param int    $created_by
     * @param bool   $preview_only     true = chỉ dựng payload, KHÔNG gọi Viettel
     * @return array
     */
    public function run_for_sale($sale_id, array $corrected_buyer, $reason, $created_by, $preview_only = false)
    {
        $this->ensure_table();
        $sale_id = (int) $sale_id;
        if ($sale_id <= 0) {
            return ['success' => false, 'status' => 'error', 'message' => 'Thiếu sale_id.'];
        }
        if (!$preview_only && !self::is_enabled()) {
            return [
                'success' => false,
                'status'  => 'disabled',
                'message' => 'Chức năng "Lập hoá đơn thay thế qua Viettel" đã bị TẮT (kill switch) trên site shop này.',
            ];
        }

        $original = $this->find_original_invoice($sale_id);
        if (empty($original)) {
            return ['success' => false, 'status' => 'error', 'message' => 'Phiếu này chưa có hoá đơn điện tử đã phát hành.'];
        }
        if ((int) ($original['issue_status'] ?? 0) !== 1 || (int) ($original['send_cqt_status'] ?? 0) !== 1) {
            return [
                'success' => false,
                'status'  => 'error',
                'message' => 'Hoá đơn gốc chưa phát hành + gửi CQT thành công — không lập thay thế được.',
            ];
        }

        $corrected_buyer = $this->sanitize_buyer($corrected_buyer);
        $reason = trim(sanitize_textarea_field((string) $reason));

        $queue_id = $this->upsert_queue($sale_id, $original, $corrected_buyer, $reason, (int) $created_by);
        if ($queue_id <= 0) {
            return ['success' => false, 'status' => 'error', 'message' => 'Không tạo được bản ghi hàng đợi thay thế.'];
        }

        if ($preview_only) {
            $queue = $this->get_queue($queue_id);
            $built = $this->build_payload($queue, $original);
            if (empty($built['success'])) {
                return ['success' => false, 'status' => 'error', 'message' => (string) ($built['message'] ?? 'Không dựng được payload.')];
            }
            return [
                'success' => true,
                'status'  => 'preview_required',
                'queue_id' => $queue_id,
                'preview' => $this->preview_result($queue_id, $original, $built),
            ];
        }

        return $this->process($queue_id);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * ORCHESTRATOR
     * ═══════════════════════════════════════════════════════════════════════ */

    public function process($queue_id)
    {
        $this->ensure_table();
        $queue = $this->get_queue($queue_id);
        if (empty($queue)) {
            return ['success' => false, 'status' => 'error', 'message' => 'Không tìm thấy yêu cầu thay thế.'];
        }
        if (($queue['status'] ?? '') === 'done') {
            return [
                'success' => true,
                'id' => (int) $queue_id,
                'status' => 'done',
                'invoice_no' => (string) ($queue['replacement_invoice_no'] ?? ''),
                'message' => 'Hoá đơn thay thế đã phát hành + gửi CQT trước đó.',
            ];
        }
        if (!self::is_enabled()) {
            return ['success' => false, 'status' => 'disabled', 'message' => 'Chức năng thay thế đang tắt.'];
        }

        $original = $this->get_invoice_record((int) ($queue['original_invoice_record_id'] ?? 0));
        if (empty($original) || (int) ($original['issue_status'] ?? 0) !== 1 || (int) ($original['send_cqt_status'] ?? 0) !== 1) {
            $latest = $this->find_original_invoice((int) ($queue['sale_ledger_id'] ?? 0));
            if (!empty($latest) && (int) ($latest['issue_status'] ?? 0) === 1 && (int) ($latest['send_cqt_status'] ?? 0) === 1) {
                $original = $latest;
                $this->update_queue($queue_id, [
                    'original_invoice_record_id' => (int) $latest['local_viettel_invoice_id'],
                    'original_invoice_no' => $this->original_invoice_id($latest),
                ]);
                $queue['original_invoice_record_id'] = (int) $latest['local_viettel_invoice_id'];
            }
        }
        if (empty($original) || (int) ($original['issue_status'] ?? 0) !== 1 || (int) ($original['send_cqt_status'] ?? 0) !== 1) {
            $msg = 'Hoá đơn gốc chưa phát hành + gửi CQT thành công.';
            $this->update_queue($queue_id, ['status' => 'blocked', 'error_message' => $msg]);
            return ['success' => false, 'status' => 'blocked', 'id' => (int) $queue_id, 'message' => $msg];
        }

        // Idempotency: lần trước đã issue OK nhưng CQT lỗi → chỉ gửi lại CQT.
        $rec_id = (int) ($queue['replacement_invoice_record_id'] ?? 0);
        if ($rec_id > 0) {
            $rec = $this->get_invoice_record($rec_id);
            if ((int) ($rec['issue_status'] ?? 0) === 1 && (int) ($rec['send_cqt_status'] ?? 0) !== 1
                && method_exists($this->plugin, 'retry_replacement_cqt')) {
                $retry = $this->plugin->retry_replacement_cqt($rec_id);
                $done = !empty($retry['success']);
                $this->update_queue($queue_id, [
                    'status' => $done ? 'done' : 'error',
                    'replacement_invoice_no' => sanitize_text_field($retry['invoice_no'] ?? ''),
                    'response_payload' => wp_json_encode($retry, JSON_UNESCAPED_UNICODE),
                    'error_message' => $done ? '' : sanitize_text_field($retry['message'] ?? ''),
                    'processed_at' => $done ? current_time('mysql') : null,
                ]);
                return $retry + ['id' => (int) $queue_id, 'status' => $done ? 'done' : 'error'];
            }
        }

        $built = $this->build_payload($queue, $original);
        if (empty($built['success'])) {
            $msg = (string) ($built['message'] ?? 'Không dựng được hoá đơn thay thế.');
            $this->update_queue($queue_id, ['status' => 'error', 'error_message' => $msg]);
            return ['success' => false, 'status' => 'error', 'id' => (int) $queue_id, 'message' => $msg];
        }

        $this->update_queue($queue_id, [
            'status' => 'processing',
            'attempt_count' => (int) ($queue['attempt_count'] ?? 0) + 1,
            'transaction_uuid' => $built['transaction_uuid'],
            'request_payload' => wp_json_encode($built['payload'], JSON_UNESCAPED_UNICODE),
            'error_message' => '',
        ]);

        $issued = $this->plugin->issue_replacement($built['payload'], [
            'queue_id' => (int) $queue_id,
            'sale_ledger_id' => (int) ($queue['sale_ledger_id'] ?? 0),
            'original_invoice_record_id' => (int) ($queue['original_invoice_record_id'] ?? 0),
            'replacement_invoice_record_id' => (int) ($queue['replacement_invoice_record_id'] ?? 0),
            'transaction_uuid' => $built['transaction_uuid'],
            'created_by' => (int) ($queue['created_by'] ?? 0),
            'totals' => $built['totals'],
            'reason' => (string) ($queue['reason'] ?? ''),
        ]);

        $done = !empty($issued['success']);
        $this->update_queue($queue_id, [
            'status' => $done ? 'done' : 'error',
            'replacement_invoice_record_id' => (int) ($issued['invoice_record_id'] ?? 0),
            'replacement_invoice_no' => sanitize_text_field($issued['invoice_no'] ?? ''),
            'response_payload' => wp_json_encode($issued, JSON_UNESCAPED_UNICODE),
            'error_message' => $done ? '' : (string) ($issued['message'] ?? 'Không rõ kết quả.'),
            'processed_at' => $done ? current_time('mysql') : null,
        ]);

        return [
            'success' => $done,
            'id' => (int) $queue_id,
            'status' => $done ? 'done' : 'error',
            'invoice_record_id' => (int) ($issued['invoice_record_id'] ?? 0),
            'invoice_no' => sanitize_text_field($issued['invoice_no'] ?? ''),
            'message' => $done
                ? 'Đã phát hành hoá đơn thay thế và gửi CQT thành công.'
                : (string) ($issued['message'] ?? 'Lập hoá đơn thay thế chưa thành công.'),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * DỰNG PAYLOAD (thuần — soi được)
     * ═══════════════════════════════════════════════════════════════════════ */

    private function build_payload(array $queue, array $original)
    {
        $issue_payload = json_decode((string) ($original['issue_request_payload'] ?? ''), true);
        if (!is_array($issue_payload) || empty($issue_payload['itemInfo'])) {
            return ['success' => false, 'message' => 'Hoá đơn gốc không có payload phát hành để dựng hoá đơn thay thế.'];
        }

        $original_no = $this->original_invoice_id($original);
        if ($original_no === '') {
            return ['success' => false, 'message' => 'Không lấy được số hoá đơn gốc từ Viettel.'];
        }

        $general = is_array($issue_payload['generalInvoiceInfo'] ?? null) ? $issue_payload['generalInvoiceInfo'] : [];

        // UUID mới cho lần phát hành này; tái dùng từ queue khi retry.
        $transaction_uuid = sanitize_text_field((string) ($queue['transaction_uuid'] ?? ''));
        if ($transaction_uuid === '') {
            $transaction_uuid = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : $this->deterministic_uuid(get_current_blog_id() . ':replace:' . (int) $queue['id']);
        }

        $now_ms = (int) round(microtime(true) * 1000);
        $orig_issue_ms = $this->invoice_issue_time_ms($original);
        $reason = trim((string) ($queue['reason'] ?? ''));
        $ref = 'Thay thế cho hoá đơn số ' . $original_no
            . ' ngày ' . $this->format_issue_date($orig_issue_ms)
            . ($reason !== '' ? ' - ' . $reason : ' - điều chỉnh thông tin người mua');
        $ref = function_exists('mb_substr') ? mb_substr($ref, 0, 225) : substr($ref, 0, 225);

        // generalInvoiceInfo: giữ mẫu/seri gốc, chuyển sang loại THAY THẾ.
        $general['invoiceType'] = (string) ($general['invoiceType'] ?? '1');
        $general['templateCode'] = (string) ($original['template_code'] ?? ($general['templateCode'] ?? ''));
        $general['invoiceSeries'] = (string) ($original['invoice_series'] ?? ($general['invoiceSeries'] ?? ''));
        $general['transactionUuid'] = $transaction_uuid;
        $general['currencyCode'] = (string) ($general['currencyCode'] ?? 'VND');
        $general['exchangeRate'] = $general['exchangeRate'] ?? 1;
        $general['adjustmentType'] = self::ADJ_TYPE_REPLACE;
        $general['adjustmentInvoiceType'] = '1';
        $general['originalInvoiceId'] = $original_no;
        $general['originalInvoiceIssueDate'] = $orig_issue_ms;
        $general['adjustedNote'] = $reason !== '' ? $reason : 'Điều chỉnh thông tin người mua';
        $general['invoiceNote'] = $ref;
        $general['additionalReferenceDesc'] = $ref;
        $general['additionalReferenceDate'] = $now_ms;
        $general['autoAgreementDoc'] = true;
        $general['paymentStatus'] = $general['paymentStatus'] ?? true;
        $general['cusGetInvoiceRight'] = $general['cusGetInvoiceRight'] ?? true;
        // Để Viettel cấp lại — KHÔNG mang ngày phát hành cũ sang.
        unset($general['invoiceIssuedDate'], $general['invoiceIssuedDateStr']);

        // buyerInfo: merge chỉ những trường được sửa, giữ phần còn lại của gốc.
        $buyer = is_array($issue_payload['buyerInfo'] ?? null) ? $issue_payload['buyerInfo'] : [];
        $corrected = is_array(json_decode((string) ($queue['corrected_buyer_json'] ?? ''), true))
            ? json_decode((string) $queue['corrected_buyer_json'], true) : [];
        foreach (['buyerName', 'buyerLegalName', 'buyerTaxCode', 'buyerAddressLine', 'buyerPhoneNumber', 'buyerEmail'] as $k) {
            if (array_key_exists($k, $corrected)) {
                $v = trim((string) $corrected[$k]);
                if ($v === '') {
                    unset($buyer[$k]);
                } else {
                    $buyer[$k] = $v;
                }
            }
        }

        $totals = [
            'total_before_tax' => (float) ($issue_payload['summarizeInfo']['totalAmountWithoutTax'] ?? 0),
            'total_tax' => (float) ($issue_payload['summarizeInfo']['totalTaxAmount'] ?? 0),
            'total_after_tax' => (float) ($issue_payload['summarizeInfo']['totalAmountWithTax'] ?? 0),
        ];

        $payload = $issue_payload;
        $payload['local_ledger_code'] = (string) ($original['local_ledger_code'] ?? '');
        $payload['generalInvoiceInfo'] = $general;
        $payload['buyerInfo'] = $buyer;
        // itemInfo / taxBreakdowns / summarizeInfo / payments / metadata: GIỮ NGUYÊN của hoá đơn gốc.

        return [
            'success' => true,
            'payload' => $payload,
            'transaction_uuid' => $transaction_uuid,
            'totals' => $totals,
            'original_no' => $original_no,
            'reference' => $ref,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * QUEUE / RECORD HELPERS (song song với TGS_Viettel_Invoice_Return_Adjustment)
     * ═══════════════════════════════════════════════════════════════════════ */

    private function sanitize_buyer(array $b)
    {
        $out = [];
        $map = [
            'buyerName' => 'name', 'buyerLegalName' => 'company_name', 'buyerTaxCode' => 'tax_code',
            'buyerAddressLine' => 'address', 'buyerPhoneNumber' => 'phone', 'buyerEmail' => 'email',
        ];
        foreach ($map as $api_key => $in_key) {
            if (array_key_exists($api_key, $b)) {
                $out[$api_key] = sanitize_text_field((string) $b[$api_key]);
            } elseif (array_key_exists($in_key, $b)) {
                $out[$api_key] = sanitize_text_field((string) $b[$in_key]);
            }
        }
        if (isset($out['buyerTaxCode'])) {
            $out['buyerTaxCode'] = preg_replace('/[^0-9\-]/', '', $out['buyerTaxCode']);
        }
        return $out;
    }

    private function find_original_invoice($sale_id)
    {
        if (!defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return [];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . '
             WHERE sale_ledger_id = %d
               AND request_mode IN (%s, %s)
               AND issue_status = 1
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY local_viettel_invoice_id DESC LIMIT 1',
            (int) $sale_id,
            'issue',
            'replacement'
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private function upsert_queue($sale_id, array $original, array $corrected_buyer, $reason, $created_by)
    {
        global $wpdb;
        $table = $this->table();
        if ($table === '') {
            return 0;
        }
        $now = current_time('mysql');
        $orig_rec = (int) ($original['local_viettel_invoice_id'] ?? 0);
        $orig_no  = $this->original_invoice_id($original);

        // Còn một yêu cầu CHƯA done cho đúng hoá đơn gốc này → dùng lại (đổi buyer/reason).
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table}
              WHERE blog_id = %d AND sale_ledger_id = %d AND original_invoice_record_id = %d
                AND status <> 'done'
              ORDER BY id DESC LIMIT 1",
            get_current_blog_id(), (int) $sale_id, $orig_rec
        ), ARRAY_A);
        if (!empty($existing['id'])) {
            $wpdb->update($table, [
                'status' => 'pending',
                'original_invoice_no' => $orig_no,
                'corrected_buyer_json' => wp_json_encode($corrected_buyer, JSON_UNESCAPED_UNICODE),
                'reason' => $reason,
                'error_message' => '',
                'updated_at' => $now,
            ], ['id' => (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $ok = $wpdb->insert($table, [
            'blog_id' => get_current_blog_id(),
            'sale_ledger_id' => (int) $sale_id,
            'original_invoice_record_id' => $orig_rec,
            'status' => 'pending',
            'original_invoice_no' => $orig_no,
            'corrected_buyer_json' => wp_json_encode($corrected_buyer, JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'created_by' => (int) $created_by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function get_queue($queue_id)
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d AND blog_id = %d LIMIT 1',
            (int) $queue_id,
            get_current_blog_id()
        ), ARRAY_A);
    }

    public function update_queue($queue_id, array $data)
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table(), $data, ['id' => (int) $queue_id]);
    }

    public function list_recent($limit = 50)
    {
        global $wpdb;
        $table = $this->table();
        if ($table === '') {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d ORDER BY id DESC LIMIT %d",
            get_current_blog_id(),
            max(1, min(200, (int) $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function get_invoice_record($invoice_id)
    {
        if ((int) $invoice_id <= 0 || !defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return [];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . ' WHERE local_viettel_invoice_id = %d LIMIT 1',
            (int) $invoice_id
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private function original_invoice_id(array $original)
    {
        $number = trim((string) ($original['viettel_invoice_no'] ?? ''));
        if ($number === '') {
            $resp = json_decode((string) ($original['issue_response_payload'] ?? ''), true);
            $number = $this->find_value($resp, ['invoiceNo', 'invoiceNumber']);
        }
        if ($number === '' && $this->plugin && method_exists($this->plugin, 'recover_invoice_number_by_transaction_uuid')) {
            $number = (string) $this->plugin->recover_invoice_number_by_transaction_uuid(
                (int) ($original['local_viettel_invoice_id'] ?? 0)
            );
        }
        return $number;
    }

    private function find_value($data, array $keys)
    {
        if (!is_array($data)) {
            return '';
        }
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                return sanitize_text_field((string) $data[$key]);
            }
        }
        foreach ($data as $value) {
            $found = $this->find_value($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    private function invoice_issue_time_ms(array $original)
    {
        $date = (string) ($original['issue_sent_at'] ?? ($original['created_at'] ?? ''));
        $ts = $date !== '' ? strtotime($date) : false;
        return ($ts !== false ? $ts : time()) * 1000;
    }

    private function format_issue_date($ms)
    {
        $ms = (float) $ms;
        return $ms <= 0 ? '' : date_i18n('d/m/Y', (int) round($ms / 1000));
    }

    private function deterministic_uuid($seed)
    {
        $hex = md5('tgs-viettel-replace:' . $seed);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function preview_result($queue_id, array $original, array $built)
    {
        $payload = is_array($built['payload'] ?? null) ? $built['payload'] : [];
        $general = is_array($payload['generalInvoiceInfo'] ?? null) ? $payload['generalInvoiceInfo'] : [];
        $buyer   = is_array($payload['buyerInfo'] ?? null) ? $payload['buyerInfo'] : [];

        return [
            'id' => (int) $queue_id,
            'status' => 'preview_required',
            'message' => 'Kiểm tra hoá đơn thay thế trước khi gửi Viettel/CQT.',
            'preview' => [
                'adjustment_type' => self::ADJ_TYPE_REPLACE,
                'adjustment_label' => 'Hoá đơn thay thế',
                'original_invoice_no' => (string) ($built['original_no'] ?? ''),
                'original_template_code' => (string) ($original['template_code'] ?? ''),
                'original_invoice_series' => (string) ($original['invoice_series'] ?? ''),
                'original_issue_date' => $this->format_issue_date($general['originalInvoiceIssueDate'] ?? 0),
                'reference' => (string) ($general['additionalReferenceDesc'] ?? ''),
                'template_code' => (string) ($general['templateCode'] ?? ''),
                'invoice_series' => (string) ($general['invoiceSeries'] ?? ''),
                'buyer' => [
                    'name' => (string) ($buyer['buyerName'] ?? ''),
                    'company_name' => (string) ($buyer['buyerLegalName'] ?? ''),
                    'tax_code' => (string) ($buyer['buyerTaxCode'] ?? ''),
                    'address' => (string) ($buyer['buyerAddressLine'] ?? ''),
                    'phone' => (string) ($buyer['buyerPhoneNumber'] ?? ''),
                    'email' => (string) ($buyer['buyerEmail'] ?? ''),
                ],
                'items' => array_values(is_array($payload['itemInfo'] ?? null) ? $payload['itemInfo'] : []),
                'tax_breakdowns' => array_values(is_array($payload['taxBreakdowns'] ?? null) ? $payload['taxBreakdowns'] : []),
                'totals' => is_array($built['totals'] ?? null) ? $built['totals'] : [],
            ],
        ];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     * AJAX (dùng khi làm trên màn DS Gửi Thuế của shop — chưa nối UI ở đó)
     * ═══════════════════════════════════════════════════════════════════════ */

    private function guard_ajax()
    {
        if ($this->plugin && method_exists($this->plugin, 'bootstrap_requested_blog_context')) {
            $this->plugin->bootstrap_requested_blog_context();
        }
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_viettel_invoice_nonce')
            && !wp_verify_nonce($nonce, 'tgs_pos_nonce')
            && !wp_verify_nonce($nonce, 'tmd_pos_nonce')
            && !wp_verify_nonce($nonce, 'tgs_bctk_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }
        if (!current_user_can('manage_options')
            && !current_user_can('manage_tgs_invoice_cluster_settings')
            && !(class_exists('TGS_POS_Permission') && TGS_POS_Permission::current_user_can_use_pos())) {
            wp_send_json_error(['message' => 'Bạn không có quyền lập hoá đơn thay thế.'], 403);
        }
    }

    private function read_buyer_from_post()
    {
        $raw = json_decode((string) wp_unslash($_POST['buyer'] ?? '[]'), true);
        return is_array($raw) ? $raw : [];
    }

    public function ajax_preview()
    {
        $this->guard_ajax();
        $res = $this->run_for_sale(
            (int) ($_POST['sale_id'] ?? 0),
            $this->read_buyer_from_post(),
            (string) wp_unslash($_POST['reason'] ?? ''),
            get_current_user_id(),
            true
        );
        !empty($res['success']) ? wp_send_json_success($res) : wp_send_json_error($res, 400);
    }

    public function ajax_confirm()
    {
        $this->guard_ajax();
        $res = $this->run_for_sale(
            (int) ($_POST['sale_id'] ?? 0),
            $this->read_buyer_from_post(),
            (string) wp_unslash($_POST['reason'] ?? ''),
            get_current_user_id(),
            false
        );
        !empty($res['success']) ? wp_send_json_success($res) : wp_send_json_error($res, 400);
    }

    public function ajax_retry()
    {
        $this->guard_ajax();
        $queue_id = (int) ($_POST['queue_id'] ?? 0);
        if ($queue_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu queue_id.'], 400);
        }
        $res = $this->process($queue_id);
        !empty($res['success']) ? wp_send_json_success($res) : wp_send_json_error($res, 400);
    }
}
