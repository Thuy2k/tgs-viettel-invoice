<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Đồng bộ phiếu hoàn POS với hóa đơn điều chỉnh giảm trên Viettel.
 *
 * Phiếu hoàn kho/tiền đã COMMIT trước khi lớp này chạy. Vì vậy lỗi API chỉ được
 * ghi nhận để kế toán xử lý lại, tuyệt đối không rollback kho hoặc tiền.
 */
class TGS_Viettel_Invoice_Return_Adjustment
{
    private static $instance;
    private $plugin;

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
        add_action('tgs_pos_return_committed', [$this, 'handle_return_committed'], 10, 2);
        add_action('wp_ajax_tgs_viettel_retry_return_adjustment', [$this, 'ajax_retry']);
        add_action('wp_ajax_tgs_viettel_confirm_return_adjustment', [$this, 'ajax_confirm']);
        add_action('wp_ajax_tgs_viettel_preview_return_adjustment', [$this, 'ajax_preview']);
    }

    private function table()
    {
        if (class_exists('TGS_Viettel_Invoice_Clusters')) {
            $tables = TGS_Viettel_Invoice_Clusters::instance()->tables();
            return (string) ($tables['return_adjustments'] ?? '');
        }

        global $wpdb;
        return $wpdb->base_prefix . 'tgs_viettel_invoice_return_adjustments';
    }

    /**
     * Nhận kết quả bằng tham chiếu để POS có thể hiển thị trạng thái thuế ngay.
     */
    public function handle_return_committed(&$result, $args)
    {
        if (!is_array($result)) {
            return;
        }

        $return_id = intval($result['return_ledger_id'] ?? 0);
        $sale_id = intval($args['sale_ledger_id'] ?? 0);
        if ($return_id <= 0 || $sale_id <= 0) {
            return;
        }

        $original = $this->find_original_invoice($sale_id);
        if (empty($original)) {
            $result['tax_adjustment'] = [
                'status' => 'not_required',
                'message' => 'Đơn chưa phát hành hóa đơn điện tử; không cần lập hóa đơn điều chỉnh.',
            ];
            return;
        }

        $issue_status = intval($original['issue_status'] ?? 0);
        $cqt_status = intval($original['send_cqt_status'] ?? 0);
        $invoice_state = sanitize_key($original['invoice_state'] ?? '');

        $queue_status = ($issue_status === 1 && $cqt_status === 1 && $invoice_state === 'done')
            ? 'pending'
            : 'blocked';

        $queue_id = $this->upsert_queue($return_id, $sale_id, $original, $queue_status, intval($args['operator_id'] ?? 0));
        if ($queue_id <= 0) {
            $result['tax_adjustment'] = [
                'status' => 'error',
                'message' => 'Hoàn kho/tiền đã xong nhưng không thể tạo yêu cầu điều chỉnh thuế.',
            ];
            return;
        }

        if ($queue_status === 'blocked') {
            $message = $issue_status === 1
                ? 'Hóa đơn gốc đã phát hành nhưng chưa gửi CQT thành công. Cần đối soát hóa đơn gốc trước khi điều chỉnh.'
                : 'Hóa đơn gốc chưa xác định phát hành thành công. Kế toán cần kiểm tra trước khi điều chỉnh.';
            $this->update_queue($queue_id, ['error_message' => $message]);
            $result['tax_adjustment'] = [
                'id' => $queue_id,
                'status' => 'blocked',
                'message' => $message,
            ];
            $result['message'] .= ' ' . $message;
            return;
        }

        $queue = $this->get_queue($queue_id);
        if (($queue['status'] ?? '') === 'done') {
            $result['tax_adjustment'] = [
                'id' => $queue_id,
                'status' => 'done',
                'invoice_no' => (string) ($queue['adjustment_invoice_no'] ?? ''),
                'message' => 'Hóa đơn điều chỉnh đã được gửi CQT trước đó.',
            ];
            return;
        }

        /*
         * Không tự phát hành ngay sau khi PHH đã commit. POS phải cho người dùng
         * xem phần hàng/tiền/thuế điều chỉnh và bấm xác nhận riêng. Nhờ vậy thao
         * tác hoàn kho/tiền vẫn an toàn, còn API thuế không chạy "âm thầm".
         */
        $built = $this->build_payload($queue, $original);
        if (empty($built['success'])) {
            $message = 'Hoàn kho/tiền đã xong nhưng ' . (string) ($built['message'] ?? 'không dựng được preview thuế.');
            $this->update_queue($queue_id, ['status' => 'error', 'error_message' => $message]);
            $result['tax_adjustment'] = ['id' => $queue_id, 'status' => 'error', 'message' => $message];
            $result['message'] .= ' ' . $message;
            return;
        }

        $this->update_queue($queue_id, [
            'status' => 'pending',
            'transaction_uuid' => $built['transaction_uuid'],
            'request_payload' => wp_json_encode($built['payload'], JSON_UNESCAPED_UNICODE),
            'error_message' => '',
        ]);
        $result['tax_adjustment'] = $this->preview_result($queue_id, $original, $built);
        $result['message'] .= ' Phiếu điều chỉnh thuế đang chờ kiểm tra và xác nhận gửi.';
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
               AND request_mode = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY local_viettel_invoice_id DESC LIMIT 1',
            $sale_id,
            'issue'
        ), ARRAY_A);

        return is_array($row) ? $row : [];
    }

    private function upsert_queue($return_id, $sale_id, array $original, $status, $created_by)
    {
        global $wpdb;
        $table = $this->table();
        if ($table === '') {
            return 0;
        }

        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE blog_id = %d AND return_ledger_id = %d LIMIT 1",
            get_current_blog_id(),
            $return_id
        ), ARRAY_A);
        $existing = intval($existing_row['id'] ?? 0);
        if ($existing > 0) {
            if (($existing_row['status'] ?? '') !== 'done') {
                $wpdb->update($table, [
                    'status' => $status,
                    'original_invoice_record_id' => intval($original['local_viettel_invoice_id'] ?? 0),
                    'original_invoice_no' => $this->original_invoice_id($original),
                    'updated_at' => current_time('mysql'),
                ], ['id' => $existing]);
            }
            return $existing;
        }

        $now = current_time('mysql');
        $ok = $wpdb->insert($table, [
            'blog_id' => get_current_blog_id(),
            'return_ledger_id' => $return_id,
            'sale_ledger_id' => $sale_id,
            'original_invoice_record_id' => intval($original['local_viettel_invoice_id'] ?? 0),
            'status' => $status,
            'original_invoice_no' => $this->original_invoice_id($original),
            'created_by' => $created_by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $ok ? intval($wpdb->insert_id) : 0;
    }

    public function process($queue_id)
    {
        global $wpdb;
        $table = $this->table();
        $queue = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND blog_id = %d LIMIT 1",
            $queue_id,
            get_current_blog_id()
        ), ARRAY_A);
        if (empty($queue)) {
            return ['status' => 'error', 'message' => 'Không tìm thấy yêu cầu điều chỉnh thuế.'];
        }

        if (($queue['status'] ?? '') === 'done') {
            return [
                'id' => intval($queue_id),
                'status' => 'done',
                'invoice_no' => (string) ($queue['adjustment_invoice_no'] ?? ''),
                'message' => 'Hóa đơn điều chỉnh đã được gửi CQT trước đó.',
            ];
        }

        $original = $this->get_invoice_record(intval($queue['original_invoice_record_id'] ?? 0));
        if (empty($original) || intval($original['issue_status'] ?? 0) !== 1 || intval($original['send_cqt_status'] ?? 0) !== 1) {
            // Có thể kế toán đã phát hành/gửi lại thành công thành một bản ghi mới
            // sau lúc phiếu hoàn bị blocked. Luôn làm mới liên kết trước khi dừng.
            $latest_original = $this->find_original_invoice(intval($queue['sale_ledger_id'] ?? 0));
            if (!empty($latest_original) && intval($latest_original['issue_status'] ?? 0) === 1 && intval($latest_original['send_cqt_status'] ?? 0) === 1) {
                $original = $latest_original;
                $queue['original_invoice_record_id'] = intval($latest_original['local_viettel_invoice_id'] ?? 0);
                $this->update_queue($queue_id, [
                    'original_invoice_record_id' => intval($latest_original['local_viettel_invoice_id'] ?? 0),
                    'original_invoice_no' => $this->original_invoice_id($latest_original),
                ]);
            }
        }
        if (empty($original) || intval($original['issue_status'] ?? 0) !== 1 || intval($original['send_cqt_status'] ?? 0) !== 1) {
            $message = 'Chưa thể điều chỉnh: hóa đơn gốc chưa phát hành và gửi CQT thành công.';
            $this->update_queue($queue_id, ['status' => 'blocked', 'error_message' => $message]);
            return ['id' => intval($queue_id), 'status' => 'blocked', 'message' => $message];
        }

        // Đồng bộ lại số hóa đơn gốc cho cả queue cũ từng lưu nhầm chuỗi
        // invoiceSeries + invoiceNo (ví dụ K23TXMK26TXM2422).
        $correct_original_invoice_no = $this->original_invoice_id($original);
        if ($correct_original_invoice_no !== ''
            && $correct_original_invoice_no !== (string) ($queue['original_invoice_no'] ?? '')) {
            $queue['original_invoice_no'] = $correct_original_invoice_no;
            $this->update_queue($queue_id, ['original_invoice_no' => $correct_original_invoice_no]);
        }

        // Không tự phát hành lại khi lần trước đã issue thành công nhưng gửi CQT lỗi.
        $adjustment_record_id = intval($queue['adjustment_invoice_record_id'] ?? 0);
        if ($adjustment_record_id > 0) {
            $adjustment = $this->get_invoice_record($adjustment_record_id);
            if (intval($adjustment['issue_status'] ?? 0) === 1 && intval($adjustment['send_cqt_status'] ?? 0) !== 1) {
                $retry = $this->plugin->retry_return_adjustment_cqt($adjustment_record_id, $queue_id);
                if (is_array($retry)) {
                    $done = ($retry['status'] ?? '') === 'done';
                    $this->update_queue($queue_id, [
                        'status' => $done ? 'done' : 'error',
                        'adjustment_invoice_no' => sanitize_text_field($retry['invoice_no'] ?? ''),
                        'response_payload' => wp_json_encode($retry, JSON_UNESCAPED_UNICODE),
                        'error_message' => $done ? '' : sanitize_text_field($retry['message'] ?? ''),
                        'processed_at' => $done ? current_time('mysql') : null,
                    ]);
                    return $retry;
                }
                return ['id' => intval($queue_id), 'status' => 'error', 'message' => 'Không gửi lại được hóa đơn điều chỉnh lên CQT.'];
            }
        }

        $built = $this->build_payload($queue, $original);
        if (empty($built['success'])) {
            $message = (string) ($built['message'] ?? 'Không dựng được hóa đơn điều chỉnh.');
            $this->update_queue($queue_id, ['status' => 'error', 'error_message' => $message]);
            return ['id' => intval($queue_id), 'status' => 'error', 'message' => 'Hoàn kho/tiền đã xong nhưng ' . $message];
        }

        $this->update_queue($queue_id, [
            'status' => 'processing',
            'attempt_count' => intval($queue['attempt_count'] ?? 0) + 1,
            'transaction_uuid' => $built['transaction_uuid'],
            'request_payload' => wp_json_encode($built['payload'], JSON_UNESCAPED_UNICODE),
            'error_message' => '',
        ]);

        $issued = $this->plugin->issue_return_adjustment($built['payload'], [
            'queue_id' => intval($queue_id),
            'sale_ledger_id' => intval($queue['sale_ledger_id']),
            'return_ledger_id' => intval($queue['return_ledger_id']),
            'original_invoice_record_id' => intval($queue['original_invoice_record_id']),
            'adjustment_invoice_record_id' => intval($queue['adjustment_invoice_record_id'] ?? 0),
            'transaction_uuid' => $built['transaction_uuid'],
            'created_by' => intval($queue['created_by'] ?? 0),
            'totals' => $built['totals'],
        ]);

        $status = !empty($issued['success']) ? 'done' : 'error';
        $message = (string) ($issued['message'] ?? 'Không xác định được kết quả điều chỉnh thuế.');
        $this->update_queue($queue_id, [
            'status' => $status,
            'adjustment_invoice_record_id' => intval($issued['invoice_record_id'] ?? 0),
            'adjustment_invoice_no' => sanitize_text_field($issued['invoice_no'] ?? ''),
            'response_payload' => wp_json_encode($issued, JSON_UNESCAPED_UNICODE),
            'error_message' => $status === 'done' ? '' : $message,
            'processed_at' => $status === 'done' ? current_time('mysql') : null,
        ]);

        return [
            'id' => intval($queue_id),
            'status' => $status,
            'invoice_record_id' => intval($issued['invoice_record_id'] ?? 0),
            'invoice_no' => sanitize_text_field($issued['invoice_no'] ?? ''),
            'message' => $status === 'done'
                ? 'Đã lập hóa đơn điều chỉnh giảm và gửi CQT thành công.'
                : 'Hoàn kho/tiền đã xong nhưng điều chỉnh thuế chưa thành công: ' . $message,
        ];
    }

    private function build_payload(array $queue, array $original)
    {
        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            return ['success' => false, 'message' => 'thiếu bảng dữ liệu phiếu hoàn'];
        }

        global $wpdb;
        $return = $wpdb->get_row($wpdb->prepare(
            'SELECT local_ledger_code, local_ledger_note, local_ledger_item_id FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d LIMIT 1',
            intval($queue['return_ledger_id'])
        ), ARRAY_A);
        if (empty($return)) {
            return ['success' => false, 'message' => 'không tìm thấy phiếu hoàn'];
        }

        $item_ids = json_decode((string) ($return['local_ledger_item_id'] ?? ''), true);
        $item_ids = is_array($item_ids) ? array_values(array_filter(array_map('intval', $item_ids))) : [];
        if (empty($item_ids)) {
            return ['success' => false, 'message' => 'phiếu hoàn không có dòng hàng'];
        }

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $return_items = $wpdb->get_results($wpdb->prepare(
            'SELECT local_ledger_item_id, quantity, price, local_ledger_item_tax_percent,
                    local_ledger_item_tax_amount, local_ledger_item_meta
             FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . '
             WHERE local_ledger_item_id IN (' . $placeholders . ')
             ORDER BY local_ledger_item_id ASC',
            ...$item_ids
        ), ARRAY_A);

        $filtered = json_decode((string) ($original['smart_filtered_payload'] ?? ''), true);
        $original_items = is_array($filtered['items'] ?? null) ? $filtered['items'] : [];
        $original_map = [];
        foreach ($original_items as $item) {
            $source_id = intval($item['ledger_item_id'] ?? 0);
            if ($source_id > 0) {
                $original_map[$source_id] = $item;
            }
        }
        if (empty($original_map)) {
            return ['success' => false, 'message' => 'hóa đơn gốc thiếu snapshot dòng hàng để điều chỉnh an toàn'];
        }

        $items = [];
        $tax_breakdowns = [];
        $sum_before = 0;
        $sum_tax = 0;
        $line = 1;
        foreach ($return_items as $return_item) {
            $meta = json_decode((string) ($return_item['local_ledger_item_meta'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $source_id = intval($meta['source_ledger_item_id'] ?? 0);
            if ($source_id <= 0 || empty($original_map[$source_id])) {
                // Dòng không xuất trên hóa đơn gốc (ví dụ quà bị loại) không được tự ý điều chỉnh thuế.
                continue;
            }

            $source = $original_map[$source_id];

            /*
             * ─── QUY SỐ LƯỢNG HOÀN VỀ ĐÚNG ĐVT CỦA HOÁ ĐƠN GỐC ──────────────
             *
             * Phiếu hoàn ghi theo đơn vị nhỏ nhất (4 Hộp), còn hoá đơn gốc khai
             * theo ĐVT bán (1 Vỉ_4) với đơn giá của trọn 1 Vỉ_4. Lấy thẳng số
             * lượng của phiếu hoàn nhân với đơn giá đó là điều chỉnh giảm gấp 4
             * lần số thật — mà cơ quan thuế đối chiếu gốc với điều chỉnh.
             *
             * Tỷ lệ lấy từ chính snapshot của hoá đơn gốc, không tính lại từ
             * bảng giá: bảng giá có thể đã đổi ĐVT sau ngày bán, còn hoá đơn
             * gốc thì đứng yên. Hoá đơn phát hành trước khi có trường này đọc
             * ra 0 → coi như 1, đúng bằng cách hoá đơn đó đã khai.
             */
            $unit_ratio = (float) ($source['unit_ratio'] ?? 0);
            if ($unit_ratio <= 0) {
                $unit_ratio = 1.0;
            }

            $quantity = max(0, floatval($return_item['quantity'] ?? 0)) / $unit_ratio;
            $unit_price = max(0, floatval($source['unit_price_after_discount'] ?? 0));
            $tax_percent = TGS_Viettel_Invoice_Flow_Service::tax_percent_of($source['tax_percent'] ?? null);
            if ($quantity <= 0 || $tax_percent === null) {
                continue;
            }

            /*
             * Dựng tiền GIỐNG HỆT hoá đơn gốc, chỉ đổi số lượng thành số lượng
             * hoàn: cùng đơn giá đã khai, cùng lớp tính tiền, cùng cách neo vào
             * tiền khách trả (with_tax làm tròn từ `thanh_tien`, thuế là hiệu).
             *
             * Bản cũ lấy `before` tự nhân rồi cộng với `tax_amount` lưu ở dòng
             * hoàn — hai nguồn khác nhau nên số điều chỉnh giảm có thể lệch 1đ
             * so với phần tương ứng của hoá đơn gốc, mà cơ quan thuế đối chiếu
             * gốc với điều chỉnh.
             */
            $money = TGS_Viettel_Invoice_Flow_Service::money_class();
            if ($money === '') {
                return [
                    'success' => false,
                    'message' => 'thiếu lớp tính tiền (TGS_Money / TGS_POS_Money) để dựng hóa đơn điều chỉnh',
                ];
            }

            $money_line = $money::line($quantity, $unit_price, 0, $tax_percent);
            $before   = max(0, (int) round($money_line['tien_hang_sau_ck']));
            $with_tax = max(0, (int) round($money_line['thanh_tien']));
            $tax      = max(0, $with_tax - $before);
            $items[] = [
                'lineNumber' => $line++,
                'selection' => 1,
                'itemCode' => (string) ($source['sku'] ?? ''),
                'itemName' => (string) ($source['item_name'] ?? ''),
                'unitName' => (string) ($source['unit_name'] ?? ''),
                'quantity' => $quantity,
                // Tối đa 4 số thập phân, xem chú thích ở
                // TGS_Viettel_Invoice_Flow_Service: lẻ hơn là Viettel trả về
                // INVALID_DECIMAL_POINT_PRICE và không phát hành được.
                'unitPrice' => (float) sprintf('%.4F', $unit_price),
                'itemTotalAmountWithoutTax' => $before,
                'itemTotalAmountAfterDiscount' => $before,
                'itemTotalAmountWithTax' => $with_tax,
                'taxPercentage' => $tax_percent,
                'taxAmount' => $tax,
                'isIncreaseItem' => false,
                'itemNote' => 'Điều chỉnh giảm do trả hàng - ' . (string) ($return['local_ledger_code'] ?? ''),
            ];

            $key = (string) $tax_percent;
            if (!isset($tax_breakdowns[$key])) {
                $tax_breakdowns[$key] = [
                    'taxPercentage' => $tax_percent,
                    'taxableAmount' => 0,
                    'taxAmount' => 0,
                    'taxableAmountPos' => false,
                    'taxAmountPos' => false,
                ];
            }
            $tax_breakdowns[$key]['taxableAmount'] += $before;
            $tax_breakdowns[$key]['taxAmount'] += $tax;
            $sum_before += $before;
            $sum_tax += $tax;
        }

        if (empty($items)) {
            return ['success' => false, 'message' => 'không có dòng hoàn nào từng xuất trên hóa đơn gốc'];
        }

        $issue_payload = json_decode((string) ($original['issue_request_payload'] ?? ''), true);
        $issue_payload = is_array($issue_payload) ? $issue_payload : [];
        $original_general = is_array($issue_payload['generalInvoiceInfo'] ?? null) ? $issue_payload['generalInvoiceInfo'] : [];
        $invoice_id = $this->original_invoice_id($original);
        if ($invoice_id === '') {
            return ['success' => false, 'message' => 'không lấy được số hóa đơn gốc từ Viettel'];
        }

        $now_ms = intval(round(microtime(true) * 1000));
        // Sinh UUID v4 đúng tài liệu ở lần đầu và tái dùng từ queue cho mọi retry.
        $transaction_uuid = sanitize_text_field((string) ($queue['transaction_uuid'] ?? ''));
        if ($transaction_uuid === '') {
            $transaction_uuid = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : $this->deterministic_uuid(get_current_blog_id() . ':' . intval($queue['return_ledger_id']));
        }
        $reason = trim((string) ($return['local_ledger_note'] ?? ''));
        $reference = 'Phiếu hoàn ' . (string) ($return['local_ledger_code'] ?? '') . ($reason !== '' ? ': ' . $reason : '');
        $reference = function_exists('mb_substr') ? mb_substr($reference, 0, 225) : substr($reference, 0, 225);

        $general = [
            'invoiceType' => (string) ($original_general['invoiceType'] ?? '1'),
            'templateCode' => (string) ($original['template_code'] ?? ($original_general['templateCode'] ?? '')),
            'invoiceSeries' => (string) ($original['invoice_series'] ?? ($original_general['invoiceSeries'] ?? '')),
            'transactionUuid' => $transaction_uuid,
            'currencyCode' => 'VND',
            'exchangeRate' => 1,
            /*
             * Hoàn một phần và hoàn hết nhưng KHÔNG có nội dung hóa đơn mới đều
             * là điều chỉnh giảm phần đã bán (type 5). Type 3 chỉ dùng khi có
             * một hóa đơn đúng mới để thay thế toàn bộ; không phát hành hóa đơn
             * thay thế rỗng cho ca khách trả hết hàng.
             */
            'adjustmentType' => '5',
            'adjustmentInvoiceType' => '1',
            'originalInvoiceId' => $invoice_id,
            'originalInvoiceIssueDate' => $this->invoice_issue_time_ms($original),
            'adjustedNote' => $reason !== '' ? $reason : 'Khách hoàn trả hàng',
            'invoiceNote' => $reference,
            'additionalReferenceDesc' => $reference,
            'additionalReferenceDate' => $now_ms,
            'autoAgreementDoc' => true,
            'paymentStatus' => true,
            'cusGetInvoiceRight' => true,
        ];

        $sum_with_tax = $sum_before + $sum_tax;
        $payload = [
            'local_ledger_code' => (string) ($return['local_ledger_code'] ?? ''),
            'generalInvoiceInfo' => $general,
            'buyerInfo' => is_array($issue_payload['buyerInfo'] ?? null) ? $issue_payload['buyerInfo'] : [],
            'payments' => is_array($issue_payload['payments'] ?? null) ? $issue_payload['payments'] : [],
            'itemInfo' => $items,
            'taxBreakdowns' => array_values($tax_breakdowns),
            'summarizeInfo' => [
                'sumOfTotalLineAmountWithoutTax' => $sum_before,
                'totalAmountAfterDiscount' => $sum_before,
                'totalAmountWithoutTax' => $sum_before,
                'totalTaxAmount' => $sum_tax,
                'totalAmountWithTax' => $sum_with_tax,
                'isTotalAmountPos' => false,
                'isTotalTaxAmountPos' => false,
                'isTotalAmtWithoutTaxPos' => false,
                'discountAmount' => 0,
                'isDiscountAmtPos' => false,
            ],
            'metadata' => [[
                'keyTag' => 'invoiceNote',
                'stringValue' => $reference,
                'valueType' => 'text',
                'keyLabel' => 'Ghi chú',
            ]],
        ];

        // Phân loại toàn bộ theo lũy kế tất cả PHH của đơn, không chỉ PHH hiện tại.
        $returned_by_source = $this->returned_quantities_for_sale(intval($queue['sale_ledger_id'] ?? 0));
        $is_full_return = !empty($original_map);
        foreach ($original_map as $source_id => $source) {
            /*
             * So sánh PHẢI CÙNG ĐVT. `$source['quantity']` là số lượng theo ĐVT
             * bán đã khai trên hoá đơn (1 Vỉ_4), còn phiếu hoàn cộng theo đơn vị
             * nhỏ nhất (4 Hộp) — đem so thẳng thì hoàn 1 Hộp/4 đã bị coi là hoàn
             * hết đơn, và hoá đơn sẽ bị huỷ thay vì điều chỉnh giảm.
             */
            $source_ratio = (float) ($source['unit_ratio'] ?? 0);
            if ($source_ratio <= 0) {
                $source_ratio = 1.0;
            }

            $source_qty = max(0, floatval($source['quantity'] ?? 0));
            $returned_qty = max(0, floatval($returned_by_source[$source_id] ?? 0)) / $source_ratio;

            if ($source_qty > 0 && $returned_qty + 0.00001 < $source_qty) {
                $is_full_return = false;
                break;
            }
        }

        return [
            'success' => true,
            'payload' => $payload,
            'transaction_uuid' => $transaction_uuid,
            'totals' => ['total_before_tax' => $sum_before, 'total_tax' => $sum_tax, 'total_after_tax' => $sum_with_tax],
            'return_scope' => $is_full_return ? 'full' : 'partial',
        ];
    }

    private function returned_quantities_for_sale($sale_id)
    {
        if ($sale_id <= 0 || !defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            return [];
        }

        global $wpdb;
        $item_lists = $wpdb->get_col($wpdb->prepare(
            'SELECT local_ledger_item_id FROM ' . TGS_TABLE_LOCAL_LEDGER . '
             WHERE local_ledger_parent_id = %d
               AND local_ledger_type = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)',
            intval($sale_id),
            defined('TGS_LEDGER_TYPE_CUSTOMER_RETURN') ? intval(TGS_LEDGER_TYPE_CUSTOMER_RETURN) : 11
        ));

        $item_ids = [];
        foreach ($item_lists as $json) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                $item_ids = array_merge($item_ids, array_map('intval', $decoded));
            }
        }
        $item_ids = array_values(array_unique(array_filter($item_ids)));
        if (empty($item_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT quantity, local_ledger_item_meta FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . '
             WHERE local_ledger_item_id IN (' . $placeholders . ')
               AND (is_deleted = 0 OR is_deleted IS NULL)',
            ...$item_ids
        ), ARRAY_A);

        $quantities = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['local_ledger_item_meta'] ?? ''), true);
            $source_id = intval(is_array($meta) ? ($meta['source_ledger_item_id'] ?? 0) : 0);
            if ($source_id > 0) {
                $quantities[$source_id] = ($quantities[$source_id] ?? 0) + max(0, floatval($row['quantity'] ?? 0));
            }
        }
        return $quantities;
    }

    private function get_queue($queue_id)
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d AND blog_id = %d LIMIT 1',
            intval($queue_id),
            get_current_blog_id()
        ), ARRAY_A);
    }

    private function preview_result($queue_id, array $original, array $built)
    {
        $payload = is_array($built['payload'] ?? null) ? $built['payload'] : [];
        $general = is_array($payload['generalInvoiceInfo'] ?? null) ? $payload['generalInvoiceInfo'] : [];

        return [
            'id' => intval($queue_id),
            'status' => 'preview_required',
            'message' => 'Kiểm tra hóa đơn điều chỉnh giảm trước khi gửi Viettel/CQT.',
            'preview' => [
                'return_scope' => (string) ($built['return_scope'] ?? 'partial'),
                'return_scope_label' => ($built['return_scope'] ?? '') === 'full'
                    ? 'Hoàn toàn bộ hóa đơn'
                    : 'Hoàn một phần hóa đơn',
                'adjustment_type' => (string) ($general['adjustmentType'] ?? '5'),
                'adjustment_label' => 'Hóa đơn điều chỉnh giảm',
                'original_invoice_no' => $this->original_invoice_id($original),
                'reference' => (string) ($general['additionalReferenceDesc'] ?? ''),
                'items' => array_values(is_array($payload['itemInfo'] ?? null) ? $payload['itemInfo'] : []),
                'tax_breakdowns' => array_values(is_array($payload['taxBreakdowns'] ?? null) ? $payload['taxBreakdowns'] : []),
                'totals' => is_array($built['totals'] ?? null) ? $built['totals'] : [],
            ],
        ];
    }

    private function get_invoice_record($invoice_id)
    {
        if ($invoice_id <= 0 || !defined('TGS_TABLE_LOCAL_VIETTEL_INVOICE')) {
            return [];
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_TABLE_LOCAL_VIETTEL_INVOICE . ' WHERE local_viettel_invoice_id = %d LIMIT 1',
            $invoice_id
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private function original_invoice_id(array $original)
    {
        $number = trim((string) ($original['viettel_invoice_no'] ?? ''));
        if ($number === '') {
            $response = json_decode((string) ($original['issue_response_payload'] ?? ''), true);
            $number = $this->find_value($response, ['invoiceNo', 'invoiceNumber']);
        }
        if ($number === '' && $this->plugin && method_exists($this->plugin, 'recover_invoice_number_by_transaction_uuid')) {
            $number = $this->plugin->recover_invoice_number_by_transaction_uuid(
                intval($original['local_viettel_invoice_id'] ?? 0)
            );
        }

        /*
         * SInvoice định nghĩa originalInvoiceId là invoiceNo do API phát hành
         * trả về (ví dụ K26TXM2422 / C24TGS0000001). invoiceSeries là một trường
         * độc lập trong generalInvoiceInfo, không được ghép thêm vào invoiceNo.
         */
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
        $timestamp = $date !== '' ? strtotime($date) : false;
        return ($timestamp !== false ? $timestamp : time()) * 1000;
    }

    private function deterministic_uuid($seed)
    {
        $hex = md5('tgs-viettel-return:' . $seed);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    public function update_queue($queue_id, array $data)
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table(), $data, ['id' => intval($queue_id)]);
    }

    public function list_recent($limit = 50)
    {
        global $wpdb;
        $table = $this->table();
        $limit = max(1, min(200, intval($limit)));
        if ($table === '') {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d ORDER BY id DESC LIMIT %d",
            get_current_blog_id(),
            $limit
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function ajax_retry()
    {
        if ($this->plugin && method_exists($this->plugin, 'bootstrap_requested_blog_context')) {
            $this->plugin->bootstrap_requested_blog_context();
        }
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_viettel_invoice_nonce') && !wp_verify_nonce($nonce, 'tgs_pos_nonce') && !wp_verify_nonce($nonce, 'tmd_pos_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }
        if (!current_user_can('manage_options') && !current_user_can('manage_tgs_invoice_cluster_settings')) {
            wp_send_json_error(['message' => 'Bạn không có quyền gửi lại hóa đơn điều chỉnh.'], 403);
        }

        $queue_id = intval($_POST['queue_id'] ?? 0);
        if ($queue_id <= 0) {
            wp_send_json_error(['message' => 'Thiếu queue_id.'], 400);
        }
        $result = $this->process($queue_id);
        if (($result['status'] ?? '') === 'done') {
            wp_send_json_success($result);
        }
        wp_send_json_error($result, 400);
    }

    /**
     * Trả dữ liệu preview cho danh sách thuế POS mà không phát hành hóa đơn.
     * Việc gửi thật chỉ diễn ra sau khi người dùng xác nhận trong modal.
     */
    public function ajax_preview()
    {
        if ($this->plugin && method_exists($this->plugin, 'bootstrap_requested_blog_context')) {
            $this->plugin->bootstrap_requested_blog_context();
        }
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_viettel_invoice_nonce')
            && !wp_verify_nonce($nonce, 'tgs_pos_nonce')
            && !wp_verify_nonce($nonce, 'tmd_pos_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }

        $can_use_pos = class_exists('TGS_POS_Permission')
            ? TGS_POS_Permission::current_user_can_use_pos()
            : (is_user_logged_in() && current_user_can('read'));
        if (!$can_use_pos) {
            wp_send_json_error(['message' => 'Bạn không có quyền xem hóa đơn điều chỉnh.'], 403);
        }

        $queue_id = intval($_POST['queue_id'] ?? 0);
        $queue = $queue_id > 0 ? $this->get_queue($queue_id) : [];
        if (empty($queue)) {
            wp_send_json_error(['message' => 'Không tìm thấy yêu cầu điều chỉnh của shop hiện tại.'], 404);
        }

        $original = $this->get_invoice_record(intval($queue['original_invoice_record_id'] ?? 0));
        if (empty($original)
            || intval($original['issue_status'] ?? 0) !== 1
            || intval($original['send_cqt_status'] ?? 0) !== 1) {
            $latest_original = $this->find_original_invoice(intval($queue['sale_ledger_id'] ?? 0));
            if (!empty($latest_original)) {
                $original = $latest_original;
            }
        }
        if (empty($original)) {
            wp_send_json_error(['message' => 'Không tìm thấy hóa đơn bán gốc để dựng preview.'], 404);
        }

        $built = $this->build_payload($queue, $original);
        if (empty($built['success'])) {
            wp_send_json_error([
                'message' => (string) ($built['message'] ?? 'Không dựng được preview hóa đơn điều chỉnh.'),
            ], 400);
        }

        $preview = $this->preview_result($queue_id, $original, $built);
        if (($queue['status'] ?? '') === 'done') {
            $preview['status'] = 'done';
            $preview['invoice_no'] = (string) ($queue['adjustment_invoice_no'] ?? '');
            $preview['message'] = 'Hóa đơn điều chỉnh giảm đã được gửi CQT thành công.';
        } elseif (($queue['status'] ?? '') === 'blocked'
            && (intval($original['issue_status'] ?? 0) !== 1 || intval($original['send_cqt_status'] ?? 0) !== 1)) {
            $preview['status'] = 'blocked';
            $preview['message'] = (string) ($queue['error_message'] ?? 'Hóa đơn gốc chưa sẵn sàng để điều chỉnh.');
        }
        wp_send_json_success($preview);
    }

    public function ajax_confirm()
    {
        if ($this->plugin && method_exists($this->plugin, 'bootstrap_requested_blog_context')) {
            $this->plugin->bootstrap_requested_blog_context();
        }
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'tgs_viettel_invoice_nonce')
            && !wp_verify_nonce($nonce, 'tgs_pos_nonce')
            && !wp_verify_nonce($nonce, 'tmd_pos_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }

        $can_use_pos = class_exists('TGS_POS_Permission')
            ? TGS_POS_Permission::current_user_can_use_pos()
            : (is_user_logged_in() && current_user_can('read'));
        if (!$can_use_pos) {
            wp_send_json_error(['message' => 'Bạn không có quyền xác nhận hóa đơn điều chỉnh.'], 403);
        }

        $queue_id = intval($_POST['queue_id'] ?? 0);
        if ($queue_id <= 0 || empty($this->get_queue($queue_id))) {
            wp_send_json_error(['message' => 'Không tìm thấy yêu cầu điều chỉnh của shop hiện tại.'], 404);
        }

        $result = $this->process($queue_id);
        if (($result['status'] ?? '') === 'done') {
            wp_send_json_success($result);
        }
        wp_send_json_error($result, 400);
    }
}
