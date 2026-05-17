<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Viettel_Invoice_Flow_Service
{
    public function build_smart_payload_from_sale($sale_ledger_id)
    {
        global $wpdb;

        $sale_ledger_id = intval($sale_ledger_id);
        if ($sale_ledger_id <= 0) {
            return [
                'success' => false,
                'message' => 'Thiếu mã đơn bán hàng để xây dựng dữ liệu hóa đơn.',
            ];
        }

        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM') || !defined('TGS_TABLE_LOCAL_PRODUCT_NAME')) {
            return [
                'success' => false,
                'message' => 'Thiếu hằng số bảng dữ liệu từ tgs_shop_management.',
            ];
        }

        $sale = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT local_ledger_id, local_ledger_code, local_ledger_person_id, local_ledger_item_id, created_at FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d LIMIT 1',
                $sale_ledger_id
            ),
            ARRAY_A
        );

        if (empty($sale)) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy đơn bán hàng.',
            ];
        }

        $person = [];
        if (defined('TGS_TABLE_LOCAL_LEDGER_PERSON') && !empty($sale['local_ledger_person_id'])) {
            $person = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT local_ledger_person_name, local_ledger_person_address, local_ledger_person_phone FROM ' . TGS_TABLE_LOCAL_LEDGER_PERSON . ' WHERE local_ledger_person_id = %d LIMIT 1',
                    intval($sale['local_ledger_person_id'])
                ),
                ARRAY_A
            );
        }

        // Lấy danh sách item_id từ cột JSON của phiếu bán hàng
        $item_ids_json = $sale['local_ledger_item_id'] ?? '';
        $item_ids = is_string($item_ids_json) ? json_decode($item_ids_json, true) : [];
        $item_ids = is_array($item_ids) ? array_map('intval', array_filter($item_ids)) : [];

        if (empty($item_ids)) {
            return [
                'success' => false,
                'message' => 'Đơn chưa có dòng sản phẩm để gửi hóa đơn điện tử.',
            ];
        }

        $has_price_after_discount = $this->local_ledger_item_column_exists('local_ledger_item_price_after_discount');
        $has_under24_promo_danger = $this->local_ledger_item_column_exists('local_ledger_item_is_under24_promo_danger');

        $optional_selects = [];
        if ($has_price_after_discount) {
            $optional_selects[] = 'i.local_ledger_item_price_after_discount';
        }
        if ($has_under24_promo_danger) {
            $optional_selects[] = 'i.local_ledger_item_is_under24_promo_danger';
        }
        $optional_select_sql = empty($optional_selects) ? '' : ', ' . implode(', ', $optional_selects);

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.local_ledger_item_id, i.local_product_name_id, i.local_ledger_item_gift_type, i.local_ledger_item_meta, i.quantity, i.price,
                        i.local_ledger_item_discount, i.local_ledger_item_discount_type' . $optional_select_sql . ',
                        p.local_product_name, p.local_product_sku, p.local_product_unit
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' i
                 LEFT JOIN ' . TGS_TABLE_LOCAL_PRODUCT_NAME . ' p ON p.local_product_name_id = i.local_product_name_id
                 WHERE i.local_ledger_item_id IN (' . $placeholders . ')
                 ORDER BY i.local_ledger_item_id ASC',
                ...$item_ids
            ),
            ARRAY_A
        );

        /**
         * Cơ quan thuế (Viettel/CQT) chỉ quan tâm đến ĐƠN GIÁ SAU KHUYẾN MÃI của từng sản phẩm.
         * Họ không quan tâm đến cấu trúc khuyến mãi (% hay tiền), cũng không quan tâm nội bộ
         * shop áp dụng CTKM như thế nào. Chỉ cần biết: "bán 1 cái giá bao nhiêu".
         *
         * Công thức truyền lên API thuế:
         *   unitPrice                    = đơn giá sau KM (1 đơn vị)
         *   itemTotalAmountAfterDiscount = unitPrice × quantity
         *
         * Cách lấy đơn giá sau KM (theo thứ tự ưu tiên):
         *   [1] Cột local_ledger_item_price_after_discount — nếu đã được ghi rõ khi tạo phiếu (> 0)
         *   [2] Tính ngược từ: price (giá gốc chưa KM) + discount_type + discount_value
         *       - percent : price × (1 - discount% / 100)   → discount=100% thì = 0 (hàng tặng)
         *       - vnd     : price − discount_vnd             → không âm
         *       - không KM: giữ nguyên price
         *
         * Lưu ý: đơn giá truyền lên là giá CHƯA bao gồm VAT (price đã được tách VAT khi tạo phiếu).
         * VAT được tính và truyền riêng ở bước build_issue_payload_from_filtered().
         */
        $source_items = [];
        foreach ($items as $item) {
            $quantity = floatval($item['quantity']);

            // Ưu tiên 1: cột local_ledger_item_price_after_discount nếu đã được lưu (khác NULL/0)
            $explicit_price_after_discount = $has_price_after_discount
                && isset($item['local_ledger_item_price_after_discount'])
                && $item['local_ledger_item_price_after_discount'] !== null
                && $item['local_ledger_item_price_after_discount'] !== ''
                && floatval($item['local_ledger_item_price_after_discount']) > 0;

            if ($explicit_price_after_discount) {
                // Đã lưu sẵn — dùng thẳng, không cần tính lại
                $unit_price = floatval($item['local_ledger_item_price_after_discount']);
            } else {
                // Ưu tiên 2: tính từ price (giá gốc chưa KM) + cột discount
                // local_ledger_item_discount      = giá trị gốc user nhập (vd: 8 cho 8%, 20000 cho 20k VNĐ)
                // local_ledger_item_discount_type = 'percent' | 'vnd'
                $raw_price  = floatval($item['price']);
                $disc_type  = $item['local_ledger_item_discount_type'] ?? 'percent';
                $disc_value = floatval($item['local_ledger_item_discount'] ?? 0);

                if ($disc_value <= 0) {
                    // Không có khuyến mãi — đơn giá giữ nguyên
                    $unit_price = $raw_price;
                } elseif ($disc_type === 'percent') {
                    // Giảm theo %: price × (1 - disc%) — discount=100% → đơn giá = 0 (hàng tặng)
                    $unit_price = max(0.0, $raw_price * (1 - $disc_value / 100));
                } else {
                    // Giảm theo số tiền VNĐ tính trên 1 đơn vị sản phẩm
                    $unit_price = max(0.0, $raw_price - $disc_value);
                }
            }

            $source_items[] = [
                'ledger_item_id'           => intval($item['local_ledger_item_id']),
                'product_id'               => intval($item['local_product_name_id']),
                'is_gift'                  => intval($item['local_ledger_item_gift_type'] ?? 0) === 1,
                'is_under24_promo_danger'  => intval($item['local_ledger_item_is_under24_promo_danger'] ?? 0) === 1,
                'gift_parent_sku'          => $this->extract_gift_parent_sku($item['local_ledger_item_meta'] ?? ''),
                'sku'                      => (string) ($item['local_product_sku'] ?? ''),
                'item_name'                => (string) ($item['local_product_name'] ?? ''),
                'unit_name'                => (string) ($item['local_product_unit'] ?? ''),
                'quantity'                 => $quantity,
                'unit_price_after_discount' => $unit_price,   // Đơn giá sau KM → truyền vào unitPrice
                'line_total'               => $quantity * $unit_price, // = itemTotalAmountAfterDiscount
            ];
        }

        return [
            'success' => true,
            'message' => 'Đã xây dựng payload trung gian từ đơn bán hàng.',
            'payload' => [
                'blog_id' => get_current_blog_id(),
                'sale_ledger_id' => intval($sale['local_ledger_id']),
                'sale_code' => (string) ($sale['local_ledger_code'] ?? ''),
                'customer' => [
                    'customer_name' => (string) ($person['local_ledger_person_name'] ?? 'Khách lẻ'),
                    'customer_company_name' => (string) ($person['local_ledger_person_name'] ?? 'Khách lẻ'),
                    'customer_tax_code' => '',
                    'customer_address' => (string) ($person['local_ledger_person_address'] ?? ''),
                    'customer_phone' => (string) ($person['local_ledger_person_phone'] ?? ''),
                ],
                'items' => $source_items,
            ],
        ];
    }

    public function filter_and_sort_items_for_tax(array $source_payload)
    {
        $items = isset($source_payload['items']) && is_array($source_payload['items']) ? $source_payload['items'] : [];
        if (empty($items)) {
            return [
                'success' => false,
                'message' => 'Payload trung gian chưa có dữ liệu item.',
            ];
        }

        $all_skus = [];
        foreach ($items as $item) {
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku !== '') {
                $all_skus[] = $sku;
            }
        }
        $all_skus = array_values(array_unique($all_skus));

        $under24_skus = $this->find_under24_skus($all_skus);
        $under24_lookup = array_fill_keys($under24_skus, true);

        $main_normal = [];
        $main_under24 = [];
        $gift_items = [];
        $under24_main_skus = [];

        foreach ($items as $item) {
            if (!empty($item['is_under24_promo_danger'])) {
                continue;
            }

            $is_gift = !empty($item['is_gift']);
            $sku = (string) ($item['sku'] ?? '');
            $is_under24 = isset($under24_lookup[$sku]);

            if ($is_gift) {
                $gift_items[] = $item;
                continue;
            }

            if ($is_under24) {
                $main_under24[] = $item;
                if ($sku !== '') {
                    $under24_main_skus[$sku] = true;
                }
            } else {
                $main_normal[] = $item;
            }
        }

        $filtered_gifts = [];
        foreach ($gift_items as $gift_item) {
            $gift_sku = (string) ($gift_item['sku'] ?? '');
            $parent_sku = trim((string) ($gift_item['gift_parent_sku'] ?? ''));

            // Bỏ quà tặng nếu xác định đi theo hàng chính dưới 24 tháng.
            if ($parent_sku !== '' && isset($under24_lookup[$parent_sku])) {
                continue;
            }

            // Trường hợp không có parent rõ ràng: quà có SKU dưới 24m cũng loại bỏ.
            if ($gift_sku !== '' && isset($under24_lookup[$gift_sku])) {
                continue;
            }

            $filtered_gifts[] = $gift_item;
        }

        $gift_positive_price = [];
        $gift_zero_price = [];
        foreach ($filtered_gifts as $gift_item) {
            $gift_unit_price = floatval($gift_item['unit_price_after_discount'] ?? 0);
            if ($gift_unit_price > 0) {
                $gift_positive_price[] = $gift_item;
            } else {
                $gift_zero_price[] = $gift_item;
            }
        }

        // Thu tu line item gui thue (de tranh xen ke gay nham):
        // 1) Hang chinh tren 24 thang
        // 2) Hang KM don gia sau khuyen mai > 0
        // 3) Hang tang/KM don gia = 0
        // 4) Hang chinh duoi 24 thang (luon day xuong cuoi)
        $sorted_items = [];
        foreach ($main_normal as $main_item) {
            $sorted_items[] = $main_item;
        }

        foreach ($gift_positive_price as $gift_item) {
            $sorted_items[] = $gift_item;
        }

        foreach ($gift_zero_price as $gift_item) {
            $sorted_items[] = $gift_item;
        }

        foreach ($main_under24 as $item) {
            $sorted_items[] = $item;
        }

        return [
            'success' => true,
            'message' => 'Đã lọc và sắp xếp item theo quy tắc thuế.',
            'payload' => [
                'blog_id' => intval($source_payload['blog_id'] ?? get_current_blog_id()),
                'sale_ledger_id' => intval($source_payload['sale_ledger_id'] ?? 0),
                'sale_code' => (string) ($source_payload['sale_code'] ?? ''),
                'customer' => isset($source_payload['customer']) && is_array($source_payload['customer']) ? $source_payload['customer'] : [],
                'contains_under24_main_item' => !empty($under24_main_skus) ? 1 : 0,
                'under24_main_sku_list' => array_keys($under24_main_skus),
                'items' => $sorted_items,
            ],
        ];
    }

    public function build_issue_payload_from_filtered(array $filtered_payload, array $settings = [])
    {
        $items = isset($filtered_payload['items']) && is_array($filtered_payload['items']) ? $filtered_payload['items'] : [];
        if (empty($items)) {
            return [
                'success' => false,
                'message' => 'Danh sách sản phẩm sau lọc đang rỗng, không thể phát hành hóa đơn.',
            ];
        }

        $tax_percent = 8.0;
        $item_info = [];
        $sum_without_tax = 0.0;
        $sum_tax = 0.0;
        $sum_with_tax = 0.0;

        $line_number = 1;
        foreach ($items as $item) {
            $quantity = max(0.0, floatval($item['quantity'] ?? 0));
            $unit_price = max(0.0, floatval($item['unit_price_after_discount'] ?? 0));

            if (!empty($item['is_gift'])) {
                $unit_price = 0.0;
            }

            $without_tax = round($quantity * $unit_price);
            $tax_amount  = round($without_tax * $tax_percent / 100);
            $with_tax    = $without_tax + $tax_amount;

            $sum_without_tax += $without_tax;
            $sum_tax         += $tax_amount;
            $sum_with_tax    += $with_tax;

            $item_info[] = [
                'lineNumber' => $line_number,
                'selection' => 1,
                'itemCode' => (string) ($item['sku'] ?? ''),
                'itemName' => (string) ($item['item_name'] ?? ''),
                'unitName' => (string) ($item['unit_name'] ?? ''),
                'quantity' => $quantity,
                'unitPrice' => round($unit_price),
                'itemTotalAmountWithoutTax' => $without_tax,
                'itemTotalAmountAfterDiscount' => $without_tax,
                'itemTotalAmountWithTax' => $with_tax,
                'taxPercentage' => $tax_percent,
                'taxAmount' => $tax_amount,
                'itemNote' => !empty($item['is_gift']) ? 'Hàng tặng khuyến mãi' : null,
                'isIncreaseItem' => null,
            ];

            $line_number++;
        }

        $sum_without_tax = (int) $sum_without_tax;
        $sum_tax         = (int) $sum_tax;
        $sum_with_tax    = (int) $sum_with_tax;

        $customer = isset($filtered_payload['customer']) && is_array($filtered_payload['customer'])
            ? $filtered_payload['customer']
            : [];

        $payment_method_name = sanitize_text_field($settings['default_payment_method'] ?? 'TM/CK');
        if ($payment_method_name === '') {
            $payment_method_name = 'TM/CK';
        }

        $payload = [
            'local_ledger_code' => (string) ($filtered_payload['sale_code'] ?? ''),
            'generalInvoiceInfo' => [
                'invoiceType' => '1',
                'templateCode' => '1/1156',
                'invoiceSeries' => 'C25TZN',
                'currencyCode' => 'VND',
                'exchangeRate' => 1,
                'adjustmentType' => '1',
                'paymentStatus' => true,
                'cusGetInvoiceRight' => true,
                'invoiceIssuedDate' => null,
                'transactionUuid' => null,
            ],
            'buyerInfo' => [
                'buyerName' => (string) ($customer['customer_name'] ?? 'Khách lẻ'),
                'buyerLegalName' => (string) ($customer['customer_company_name'] ?? ''),
                'buyerTaxCode' => (string) ($customer['customer_tax_code'] ?? ''),
                'buyerAddressLine' => (string) ($customer['customer_address'] ?? ''),
                'buyerPhoneNumber' => (string) ($customer['customer_phone'] ?? ''),
                'buyerEmail' => null,
                'buyerNotGetInvoice' => '0',
            ],
            'payments' => [
                [
                    'paymentMethod' => '3',
                    'paymentMethodName' => $payment_method_name,
                ],
            ],
            'itemInfo' => $item_info,
            'taxBreakdowns' => [
                [
                    'taxPercentage' => $tax_percent,
                    'taxableAmount' => $sum_without_tax,
                    'taxAmount' => $sum_tax,
                ],
            ],
            'summarizeInfo' => [
                'sumOfTotalLineAmountWithoutTax' => $sum_without_tax,
                'totalAmountAfterDiscount' => $sum_without_tax,
                'totalAmountWithoutTax' => $sum_without_tax,
                'totalTaxAmount' => $sum_tax,
                'totalAmountWithTax' => $sum_with_tax,
            ],
            'metadata' => [
                [
                    'keyTag' => 'invoiceNote',
                    'stringValue' => 'Tự động phát hành từ POS',
                    'valueType' => 'text',
                    'keyLabel' => 'Ghi chú',
                ],
            ],
        ];

        return [
            'success' => true,
            'message' => 'Đã map dữ liệu sang payload phát hành Viettel.',
            'payload' => $payload,
            'totals' => [
                'total_before_tax' => $sum_without_tax,
                'total_tax' => $sum_tax,
                'total_after_tax' => $sum_with_tax,
            ],
        ];
    }

    public function build_send_cqt_payload($supplier_tax_code, $transaction_uuid)
    {
        $supplier_tax_code = trim((string) $supplier_tax_code);
        $transaction_uuid = trim((string) $transaction_uuid);
        $today = current_time('Y-m-d');

        if ($supplier_tax_code === '' || $transaction_uuid === '') {
            return [
                'success' => false,
                'message' => 'Thiếu dữ liệu để gửi CQT (supplierTaxCode hoặc transactionUuid).',
            ];
        }

        return [
            'success' => true,
            'payload' => [
                'supplierTaxCode' => $supplier_tax_code,
                'transactionUuid' => $transaction_uuid,
                'startDate' => $today,
                'endDate' => $today,
            ],
        ];
    }

    private function extract_gift_parent_sku($meta_json)
    {
        if (!is_string($meta_json) || trim($meta_json) === '') {
            return '';
        }

        $decoded = json_decode($meta_json, true);
        if (!is_array($decoded)) {
            return '';
        }

        $possible_keys = [
            'parent_sku',
            'main_sku',
            'gift_for_sku',
            'source_sku',
            'apply_sku',
        ];

        foreach ($possible_keys as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return trim($decoded[$key]);
            }
        }

        return '';
    }

    private function find_under24_skus(array $skus)
    {
        global $wpdb;

        $skus = array_values(array_filter(array_map('trim', $skus)));
        if (empty($skus) || !defined('TGS_TABLE_GLOBAL_MILK_UNDER24M')) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($skus), '%s'));
        $sql = 'SELECT global_product_sku
                FROM ' . TGS_TABLE_GLOBAL_MILK_UNDER24M . '
                WHERE global_product_sku IN (' . $placeholders . ')
                  AND (is_deleted = 0 OR is_deleted IS NULL)';

        $prepared = $wpdb->prepare($sql, $skus);
        $rows = $wpdb->get_col($prepared);

        if (empty($rows)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $rows)));
    }

    public function local_ledger_item_column_exists($column_name)
    {
        global $wpdb;

        static $column_cache = [];

        $column_name = sanitize_key($column_name);
        if ($column_name === '') {
            return false;
        }

        $table = TGS_TABLE_LOCAL_LEDGER_ITEM;
        $cache_key = $table . '|' . $column_name;
        if (array_key_exists($cache_key, $column_cache)) {
            return $column_cache[$cache_key];
        }

        $result = $wpdb->get_var(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column_name)
        );

        $column_cache[$cache_key] = !empty($result);
        return $column_cache[$cache_key];
    }
}
