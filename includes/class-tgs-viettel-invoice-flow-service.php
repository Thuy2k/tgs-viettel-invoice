<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Viettel_Invoice_Flow_Service
{
    /**
     * Thuế suất của một dòng — KHÔNG có giá trị mặc định.
     *
     * ── VÌ SAO KHÔNG ĐOÁN ───────────────────────────────────────────────────
     *
     * Thuế suất thuộc về TỪNG MÃ HÀNG; hệ thống đang có cả 8%, 0% và chưa khai.
     * Dòng nào chưa khai thì KHÔNG được tự điền một con số rồi gửi lên cơ quan
     * thuế — đoán kiểu gì cũng sai:
     *
     *     điền 8% → khai THỪA thuế cho hàng lẽ ra miễn thuế
     *     điền 0% → khai THIẾU thuế cho hàng chịu thuế
     *
     * Hoá đơn đã phát hành thì không sửa được, nên CHẶN LẠI: báo rõ mã hàng nào
     * thiếu thuế suất để người dùng đi khai, rồi mới xuất hoá đơn. Thà chặn một
     * lần còn hơn phát hành sai rồi phải giải trình.
     *
     * ⚠️ Phân biệt rõ ba trạng thái. `0` là con số THẬT (hàng miễn thuế), khác
     * hẳn NULL/rỗng (chưa khai). Viết kiểu `?: 8` là biến 0% thành 8%.
     *
     * @return float|null  null = dòng này CHƯA khai thuế suất
     * @see tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md
     */
    public static function tax_percent_of($raw)
    {
        return ($raw === null || $raw === '') ? null : floatval($raw);
    }

    /**
     * Tìm những dòng chưa khai thuế suất.
     *
     * Trả về mô tả ngắn của từng dòng thiếu, để người dùng biết phải đi sửa mã
     * hàng nào — báo chung chung kiểu "thiếu thuế suất" thì đơn vài chục dòng
     * không ai biết bắt đầu từ đâu.
     */
    public static function lines_missing_tax($items, $field = 'tax_percent')
    {
        $thieu = [];

        foreach ((array) $items as $it) {
            $it = (array) $it;
            if (self::tax_percent_of($it[$field] ?? null) !== null) {
                continue;
            }

            $ma    = (string) ($it['sku'] ?? $it['local_product_sku'] ?? '');
            $ten   = (string) ($it['item_name'] ?? $it['local_product_name'] ?? '');
            $mo_ta = trim($ma . ($ten !== '' ? ' — ' . $ten : ''));

            $thieu[] = $mo_ta !== '' ? $mo_ta : 'dòng không rõ mã hàng';
        }

        return array_values(array_unique($thieu));
    }

    /**
     * Lớp tính tiền dùng cho mọi phép tính trong file này.
     *
     * Không tự nhân chia ở đây — công thức nằm ở một chỗ duy nhất, xem
     * tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md.
     *
     * Có hai bản cùng API: `TGS_Money` của tgs_shop_management và `TGS_POS_Money`
     * của tgs_pos. POS phải bán được ngay cả khi plugin quản trị bị tắt, nên ở
     * đây nhận cả hai thay vì gọi cứng một cái rồi chết khi thiếu.
     *
     * @return string Tên lớp, hoặc chuỗi rỗng nếu không có bản nào.
     */
    /**
     * Mặt hàng mã Z — KHÔNG được kèm lên hoá đơn thuế.
     *
     * Quy ước lấy thẳng từ TGS_POS_Order_Handler để hai plugin không hiểu khác
     * nhau. ⚠️ KHÔNG phụ thuộc cờ "hàng tặng": mã Z bán ra như hàng thường
     * (bán bù quà hỏng, đổi quà) vẫn là mã Z và vẫn không được lên hoá đơn.
     * Bản cũ đòi phải là hàng tặng nên đơn toàn mã Z bán thường đã lọt lên CQT.
     */
    public static function is_promo_split_sku($sku)
    {
        $sku = strtoupper(trim((string) $sku));
        if ($sku === '') {
            return false;
        }

        if (class_exists('TGS_POS_Order_Handler')) {
            return (bool) TGS_POS_Order_Handler::is_promo_split_sku($sku);
        }

        return substr($sku, -1) === 'Z';
    }

    /**
     * ─── ĐVT KHAI TRÊN HOÁ ĐƠN = ĐVT LÚC BÁN, KHÔNG PHẢI ĐƠN VỊ NHỎ NHẤT ───
     *
     * Kho quy mọi thứ về đơn vị nhỏ nhất để cộng tồn cho gọn: bán 1 Vỉ_4 thì
     * `quantity` trong sổ là 4 (Hộp). Nhưng hoá đơn phải khai ĐÚNG thứ khách
     * mua — "1 Vỉ_4", chứ không phải "4 Hộp". Khách cầm hoá đơn về đối chiếu
     * với bill mà thấy ĐVT khác là gọi lên shop hỏi ngay.
     *
     * POS đã lưu sẵn ĐVT bán ở 3 cột (đơn cũ thì nằm trong `..._meta`):
     *   local_ledger_item_unit_name     — 'Vỉ_4'
     *   local_ledger_item_unit_quantity — 1      (số lượng theo ĐVT bán)
     *   local_ledger_item_unit_ratio    — 4      (1 Vỉ_4 = 4 Hộp)
     *
     * TIỀN KHÔNG ĐỔI: đây thuần tuý là đổi cách diễn đạt cùng một lượng hàng.
     * Tiền hàng của dòng vẫn là số cũ, chỉ chia cho số lượng mới để ra đơn giá
     * theo ĐVT bán (SL × đơn giá vẫn ra đúng tiền hàng đó).
     *
     * DỮ LIỆU KHÔNG KHỚP THÌ LÙI VỀ ĐƠN VỊ NHỎ NHẤT. Nếu SL × tỷ lệ không ra
     * đúng `quantity` thì ba cột kia đang mâu thuẫn với sổ kho; khai theo chúng
     * là khai sai lượng hàng với cơ quan thuế. Khai theo đơn vị nhỏ nhất tuy
     * không đẹp nhưng luôn đúng lượng.
     *
     * @param array $row Dòng local_ledger_item (có thể kèm local_product_unit)
     * @return array ['unit_name' => string, 'quantity' => float, 'ratio' => float]
     */
    public static function sale_unit_view(array $row): array
    {
        $base_qty = max(0.0, floatval($row['quantity'] ?? 0));
        $catalog_unit = trim((string) ($row['local_product_unit'] ?? ''));

        $unit_name = trim((string) ($row['local_ledger_item_unit_name'] ?? ''));
        $ratio     = floatval($row['local_ledger_item_unit_ratio'] ?? 0);
        $unit_qty  = floatval($row['local_ledger_item_unit_quantity'] ?? 0);

        // Đơn cũ bán trước khi có 3 cột trên: thông tin nằm trong meta JSON
        if ($unit_name === '' || $ratio <= 0) {
            $meta = $row['local_ledger_item_meta'] ?? '';
            $meta = is_string($meta) ? json_decode($meta, true) : (is_array($meta) ? $meta : []);
            if (is_array($meta)) {
                if ($unit_name === '') {
                    $unit_name = trim((string) ($meta['unit_name'] ?? $meta['unit'] ?? ''));
                }
                if ($ratio <= 0) {
                    $ratio = floatval($meta['unit_ratio'] ?? 0);
                }
                if ($unit_qty <= 0) {
                    $unit_qty = floatval($meta['unit_quantity'] ?? 0);
                }
            }
        }

        $base_view = [
            'unit_name' => $catalog_unit !== '' ? $catalog_unit : $unit_name,
            'quantity'  => $base_qty,
            'ratio'     => 1.0,
        ];

        if ($unit_name === '' || $ratio <= 0) {
            return $base_view;
        }

        // Bán đúng bằng đơn vị nhỏ nhất: giữ nguyên số lượng, chỉ lấy tên ĐVT
        // đã lưu lúc bán (chính xác hơn tên trong danh mục).
        if (abs($ratio - 1.0) < 0.0001) {
            return ['unit_name' => $unit_name, 'quantity' => $base_qty, 'ratio' => 1.0];
        }

        if ($unit_qty <= 0) {
            $unit_qty = $base_qty / $ratio;
        }

        if (abs(($unit_qty * $ratio) - $base_qty) > 0.001) {
            return $base_view;
        }

        return ['unit_name' => $unit_name, 'quantity' => $unit_qty, 'ratio' => $ratio];
    }

    /**
     * Mã phiếu này có phải phiếu tách hàng khuyến mãi (mã Z) không.
     *
     * Đọc thẳng quy ước từ TGS_POS_Order_Handler khi có, để hai plugin không
     * bao giờ hiểu khác nhau về hậu tố; POS tắt thì rơi về mặc định 'Z'.
     */
    public static function is_promo_split_sale_code($sale_code)
    {
        $sale_code = strtoupper(trim((string) $sale_code));
        if ($sale_code === '') {
            return false;
        }

        $suffix = class_exists('TGS_POS_Order_Handler')
            ? strtoupper((string) TGS_POS_Order_Handler::promo_split_code_suffix())
            : 'Z';

        return $suffix !== '' && substr($sale_code, -strlen($suffix)) === $suffix;
    }

    /**
     * Lớp thực thi mô hình tiền. Public để luồng điều chỉnh (hoá đơn trả hàng)
     * dùng CHUNG một công thức với hoá đơn gốc — hai bên lệch nhau là hoá đơn
     * điều chỉnh không khớp hoá đơn bị điều chỉnh.
     */
    public static function money_class()
    {
        foreach (['TGS_Money', 'TGS_POS_Money'] as $cls) {
            if (class_exists($cls)) {
                return $cls;
            }
        }

        return '';
    }

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

        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
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

        /*
         * ─── CHẶN GỬI THUẾ XÉT THEO MÃ HÀNG, KHÔNG XÉT MÃ PHIẾU ─────────────
         *
         * Trước đây chặn ngay tại đây khi MÃ PHIẾU kết thúc bằng "Z", coi đó là
         * phiếu tách hàng khuyến mãi. Nhưng mã phiếu của POS sinh ngẫu nhiên
         * nên tự nó có thể kết thúc bằng Z (HD98_9SGEZ, HD98_KTMXZ — có thật),
         * và những đơn đó bị chặn oan dù bên trong không có mã hàng nào đuôi Z.
         *
         * Quy tắc đúng chỉ có một: ĐƠN CÓ BẤT KỲ DÒNG HÀNG NÀO MÃ ĐUÔI Z thì
         * không gửi thuế. Việc đó do bước lọc dòng hàng bên dưới lo (xem
         * filter_and_sort_items_for_tax) — phiếu tách thật toàn hàng mã Z nên
         * vẫn bị chặn y như cũ, còn phiếu chỉ TRÙNG TÊN thì gửi bình thường.
         *
         * Mã phiếu giờ cũng không còn sinh ra đuôi Z nữa: xem
         * TGS_POS_Ajax_Order::generate_sale_code().
         */

        $person = [];
        if (defined('TGS_TABLE_LOCAL_LEDGER_PERSON') && !empty($sale['local_ledger_person_id'])) {
            $person = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT local_ledger_person_name, local_ledger_person_address, local_ledger_person_phone, local_ledger_person_email, local_ledger_person_tax_code FROM ' . TGS_TABLE_LOCAL_LEDGER_PERSON . ' WHERE local_ledger_person_id = %d LIMIT 1',
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

        // Cố ý KHÔNG đọc `local_ledger_item_price_after_discount` — xem giải
        // thích ở vòng lặp dựng $source_items bên dưới.
        $has_under24_promo_danger = $this->local_ledger_item_column_exists('local_ledger_item_is_under24_promo_danger');
        $has_global_product_name_id = $this->local_ledger_item_column_exists('global_product_name_id');
        $has_local_product_sku = $this->local_ledger_item_column_exists('local_product_sku');
        $has_tax_percent = $this->local_ledger_item_column_exists('local_ledger_item_tax_percent');
        $has_discount_amount = $this->local_ledger_item_column_exists('local_ledger_item_discount_amount');
        // Tiền thuế đã chốt lúc bán — CHỈ để đối chiếu, không dùng dựng hoá đơn.
        $has_tax_amount = $this->local_ledger_item_column_exists('local_ledger_item_tax_amount');

        $optional_selects = [];
        if ($has_under24_promo_danger) {
            $optional_selects[] = 'i.local_ledger_item_is_under24_promo_danger';
        }
        $optional_selects[] = $has_global_product_name_id ? 'i.global_product_name_id' : '0 AS global_product_name_id';
        $optional_selects[] = $has_local_product_sku ? 'i.local_product_sku' : "'' AS local_product_sku";
        $optional_selects[] = $has_tax_percent ? 'i.local_ledger_item_tax_percent' : 'NULL AS local_ledger_item_tax_percent';
        $optional_selects[] = $has_tax_amount ? 'i.local_ledger_item_tax_amount' : '0 AS local_ledger_item_tax_amount';

        // ĐVT lúc bán — hoá đơn khai theo ĐVT này, xem sale_unit_view()
        foreach (['local_ledger_item_unit_name', 'local_ledger_item_unit_quantity', 'local_ledger_item_unit_ratio'] as $unit_col) {
            $optional_selects[] = $this->local_ledger_item_column_exists($unit_col)
                ? 'i.' . $unit_col
                : 'NULL AS ' . $unit_col;
        }
        $optional_select_sql = empty($optional_selects) ? '' : ', ' . implode(', ', $optional_selects);

        $placeholders = implode(',', array_fill(0, count($item_ids), '%d'));
        $items = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT i.local_ledger_item_id, i.local_product_name_id,
                        i.local_ledger_item_gift_type, i.local_ledger_item_meta, i.quantity, i.price'
                        . ($has_discount_amount
                            ? ', i.local_ledger_item_discount_amount'
                            : ', 0 AS local_ledger_item_discount_amount')
                        . $optional_select_sql . '
                 FROM ' . TGS_TABLE_LOCAL_LEDGER_ITEM . ' i
                 WHERE i.local_ledger_item_id IN (' . $placeholders . ')
                 ORDER BY i.local_ledger_item_id ASC',
                ...$item_ids
            ),
            ARRAY_A
        );

        // Catalog sản phẩm lấy từ global. local_product_* ở đây chỉ là alias tương thích payload cũ.
        if (class_exists('TGS_Viettel_Invoice_Global_Products')) {
            $items = TGS_Viettel_Invoice_Global_Products::enrich_ledger_items($items, get_current_blog_id());
        }

        /**
         * Cơ quan thuế (Viettel/CQT) chỉ quan tâm đến ĐƠN GIÁ SAU KHUYẾN MÃI của từng sản phẩm.
         * Họ không quan tâm đến cấu trúc khuyến mãi (% hay tiền), cũng không quan tâm nội bộ
         * shop áp dụng CTKM như thế nào. Chỉ cần biết: "bán 1 cái giá bao nhiêu".
         *
         * Công thức truyền lên API thuế:
         *   unitPrice                    = đơn giá sau CK, trước thuế (1 ĐVCB)
         *   itemTotalAmountAfterDiscount = unitPrice × quantity
         *
         * Con số đó chính là "Đơn giá sau CK trước thuế" — công thức (3) của mô
         * hình tiền, và là thứ DUY NHẤT được phép khai:
         *
         *     (quantity × price − discount_amount) ÷ quantity
         *
         * KHÔNG tự cài lại công thức ở đây. Gọi vào lớp tính tiền, vì đó là nơi
         * duy nhất giữ luật; tự nhân chia rải rác chính là cách dự án đã lệch
         * số giữa POS, báo cáo và hoá đơn trước đây.
         *
         * Lưu ý: giá khai là giá CHƯA gồm VAT. Thuế tính riêng ở bước
         * build_issue_payload_from_filtered(), và tính trên tiền hàng SAU chiết
         * khấu chứ không phải trên giá gốc.
         *
         * @see tgs_shop_management/docs/mo-hinh-tien-va-bang-local-ledger-item.md
         */
        $source_items = [];
        foreach ($items as $item) {
            /*
             * ─── ĐƠN GIÁ GỬI THUẾ LẤY TỪ TGS_Money, KHÔNG TỰ TÍNH ───────────
             *
             * Số phải khai là "đơn giá sau CK, trước thuế" — công thức (3)
             * trong tài liệu: (quantity × price − discount_amount) ÷ quantity.
             * `from_item()` thực thi đúng công thức đó từ 5 cột gốc.
             *
             * ⚠️ TUYỆT ĐỐI KHÔNG lấy `local_ledger_item_price_after_discount`.
             * Tên cột nghe như đã trừ chiết khấu, nhưng thực tế nó BẰNG ĐÚNG
             * `price` ở 108/108 dòng bán — tức vẫn là giá TRƯỚC chiết khấu.
             * Đọc cột đó là khai thiếu chiết khấu với cơ quan thuế: đơn có
             * giảm giá sẽ bị khai theo giá gốc. Chính `TGS_Money::from_item()`
             * cũng ghi rõ là CỐ Ý bỏ qua cột này. Xem bẫy 7.1 trong tài liệu.
             *
             * Hàng tặng lưu theo cách B (giá gốc + CK 100%) tự khắc ra 0.
             */
            $money = self::money_class();
            if ($money === '') {
                return [
                    'success' => false,
                    'message' => 'Thiếu lớp tính tiền (TGS_Money / TGS_POS_Money). '
                        . 'Không thể dựng số gửi cơ quan thuế nếu tự tính tay.',
                ];
            }

            $line       = $money::from_item($item);

            /*
             * ─── QUY VỀ ĐVT LÚC BÁN ─────────────────────────────────────────
             *
             * Sổ kho ghi theo đơn vị nhỏ nhất (bán 1 Vỉ_4 → quantity = 4 Hộp),
             * nhưng hoá đơn phải khai đúng thứ khách mua: "1 Vỉ_4". Xem
             * sale_unit_view().
             *
             * Đơn giá lấy bằng TIỀN HÀNG SAU CK CHIA CHO SỐ LƯỢNG THEO ĐVT BÁN
             * — vẫn đúng công thức (3), chỉ khác mẫu số. KHÔNG nhân đơn giá đơn
             * vị nhỏ nhất với tỷ lệ: đơn giá đó đã là số lẻ vô hạn tuần hoàn,
             * nhân lên rồi làm tròn 4 số là tự chuốc sai lệch. Chia thẳng từ
             * tiền hàng thì SL × đơn giá luôn khớp lại đúng tiền hàng.
             */
            $unit_view   = self::sale_unit_view($item);
            $unit_name   = (string) $unit_view['unit_name'];
            $sale_qty    = (float) $unit_view['quantity'];
            $line_amount = max(0.0, (float) $line['tien_hang_sau_ck']);

            $unit_price = $sale_qty > 0
                ? max(0.0, $line_amount / $sale_qty)
                : max(0.0, (float) $line['don_gia_gui_thue']);

            $source_items[] = [
                'ledger_item_id' => intval($item['local_ledger_item_id']),
                'product_id' => intval($item['local_product_name_id']),
                'is_gift' => intval($item['local_ledger_item_gift_type'] ?? 0) === 1,
                'is_under24_promo_danger' => intval($item['local_ledger_item_is_under24_promo_danger'] ?? 0) === 1,
                'gift_parent_sku' => $this->extract_gift_parent_sku($item['local_ledger_item_meta'] ?? ''),
                'sku' => (string) ($item['local_product_sku'] ?? ''),
                'item_name' => (string) ($item['local_product_name'] ?? ''),
                'unit_name' => $unit_name,
                'quantity' => $sale_qty,
                /*
                 * Tỷ lệ quy đổi của ĐVT bán, giữ lại trong snapshot để phiếu
                 * điều chỉnh (trả hàng) quy được số lượng hoàn về CÙNG ĐVT với
                 * hoá đơn gốc. Hoá đơn cũ phát hành trước khi có trường này thì
                 * đọc ra 0 → coi như 1, tức vẫn là đơn vị nhỏ nhất, khớp đúng
                 * cách hoá đơn đó đã khai.
                 */
                'unit_ratio' => (float) $unit_view['ratio'],
                'unit_price_after_discount' => $unit_price,
                'discount_amount' => floatval($item['local_ledger_item_discount_amount'] ?? 0),
                /*
                 * Tiền thuế ĐÃ CHỐT lúc bán. Không dùng để dựng hoá đơn (hoá
                 * đơn tự tính lại từ 5 cột gốc), chỉ để đối chiếu: lệch quá 1đ
                 * là dòng dữ liệu hỏng, phải chặn trước khi phát hành.
                 */
                'stored_tax_amount' => floatval($item['local_ledger_item_tax_amount'] ?? 0),
                /*
                 * CK% suy từ tiền chiết khấu — công thức (7). Không đọc hai cột
                 * `local_ledger_item_discount` / `..._discount_type`: chúng nằm
                 * trong danh sách cột ngừng dùng, và dữ liệu cũ trong cột
                 * `discount` lẫn lộn cả phần trăm lẫn tiền.
                 */
                'discount_percent' => (float) $line['ck_phan_tram'],
                'line_total' => (float) $line['tien_hang_sau_ck'],
                'tax_percent' => self::tax_percent_of($item['local_ledger_item_tax_percent'] ?? null),
            ];
        }

        /*
         * ─── CHẶN TRƯỚC KHI GỬI: dòng nào chưa khai thuế suất thì dừng ──────
         *
         * Phải chặn NGAY TẠI ĐÂY, không để lọt xuống dưới. Nếu để tiếp, giá trị
         * null sẽ bị floatval() biến thành 0 và hoá đơn lặng lẽ gửi đi với thuế
         * suất 0% — còn tệ hơn cả việc đoán 8%, vì không ai biết là đã sai.
         *
         * Hoá đơn đã phát hành không sửa được, nên thà dừng và báo rõ mã hàng
         * nào thiếu để người dùng đi khai.
         */
        $thieu_thue = self::lines_missing_tax($source_items);
        if (!empty($thieu_thue)) {
            return [
                'success' => false,
                'message' => 'Chưa xuất được hoá đơn: các mặt hàng sau chưa khai thuế suất — '
                    . implode('; ', $thieu_thue)
                    . '. Vào sửa thuế suất cho những mã này rồi xuất lại.',
                'missing_tax_items' => $thieu_thue,
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
                    'customer_tax_code' => (string) ($person['local_ledger_person_tax_code'] ?? ''),
                    'customer_address' => (string) ($person['local_ledger_person_address'] ?? ''),
                    'customer_phone' => (string) ($person['local_ledger_person_phone'] ?? ''),
                    'customer_email' => (string) ($person['local_ledger_person_email'] ?? ''),
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

        /*
         * ═══════════════════════════════════════════════════════════════════
         * CHỐT CHẶN CUỐI: CÓ MÃ Z TRONG ĐƠN LÀ KHÔNG PHÁT HÀNH HOÁ ĐƠN
         * ═══════════════════════════════════════════════════════════════════
         *
         * Bình thường hàng mã Z đã được tách sang phiếu riêng ngay lúc thanh
         * toán, nên phiếu đi lên thuế KHÔNG được còn dòng mã Z nào. Còn sót
         * nghĩa là có gì đó bất thường (đơn cũ chưa tách, hoặc dòng mã Z lọt
         * vào phiếu chính) — lúc đó DỪNG CẢ ĐƠN để người ta xem lại, chứ không
         * lặng lẽ bỏ dòng đó ra rồi vẫn phát hành.
         *
         * Vì sao chặt tay: hoá đơn phát hành rồi không thu hồi được, phải làm
         * hoá đơn điều chỉnh/thay thế và giải trình. Dừng lại một lần rẻ hơn
         * nhiều so với khai nhầm hàng cấm tặng kèm lên cơ quan thuế.
         *
         * Cần gửi thật thì bật filter, không phải sửa luồng:
         *   add_filter('tgs_pos_send_promo_split_to_tax', '__return_true');
         */
        $promo_skus = [];
        $countable_lines = 0;
        foreach ($items as $item) {
            if (!empty($item['is_under24_promo_danger'])) {
                continue;
            }

            $countable_lines++;
            if (self::is_promo_split_sku($item['sku'] ?? '')) {
                $promo_skus[] = (string) ($item['sku'] ?? '');
            }
        }

        if (!empty($promo_skus)
            && !apply_filters('tgs_pos_send_promo_split_to_tax', false, '', $items)) {
            $promo_skus = array_values(array_unique(array_filter($promo_skus)));
            $is_promo_only = count($promo_skus) > 0 && $countable_lines === count($promo_skus);

            return [
                'success' => false,
                'is_promo_only_sale' => $is_promo_only,
                'has_promo_item' => true,
                'promo_skus' => $promo_skus,
                'message' => $is_promo_only
                    ? 'Đơn này chỉ có hàng mã Z (' . implode(', ', array_slice($promo_skus, 0, 5))
                        . ') — không phát hành hoá đơn thuế. Bill vẫn in bình thường cho khách.'
                    : 'Phát hiện mã hàng đuôi Z trong đơn (' . implode(', ', array_slice($promo_skus, 0, 5))
                        . '). Hàng mã Z phải nằm ở phiếu tách riêng, không được kèm lên hoá đơn thuế — '
                        . 'kiểm tra lại đơn này trước khi gửi.',
            ];
        }

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

            // User đã bỏ tích loại trừ (is_under24_promo_danger = false) → tôn trọng, cho gửi.
            $user_override = isset($gift_item['is_under24_promo_danger']) && empty($gift_item['is_under24_promo_danger']);

            if (!$user_override) {
                // Bỏ quà tặng nếu xác định đi theo hàng chính dưới 24 tháng.
                if ($parent_sku !== '' && isset($under24_lookup[$parent_sku])) {
                    continue;
                }

                // Trường hợp không có parent rõ ràng: quà có SKU dưới 24m cũng loại bỏ.
                if ($gift_sku !== '' && isset($under24_lookup[$gift_sku])) {
                    continue;
                }
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

        if (empty($sorted_items)) {
            return [
                'success' => false,
                'message' => 'Không còn dòng hàng nào được phép khai trên hoá đơn của đơn này.',
            ];
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

        $item_info = [];
        $sum_without_tax = 0.0;
        $sum_tax = 0.0;
        $sum_with_tax = 0.0;
        $tax_breakdown_map = [];

        $line_number = 1;
        foreach ($items as $item) {
            $quantity = max(0.0, floatval($item['quantity'] ?? 0));
            $unit_price = max(0.0, floatval($item['unit_price_after_discount'] ?? 0));
            $tax_percent = self::tax_percent_of($item['tax_percent'] ?? null);

            if (!empty($item['is_gift'])) {
                $unit_price = 0.0;
            }

            /*
             * Tiền hàng và tiền thuế của dòng — công thức (2) và (4), do lớp
             * tính tiền thực thi. Không tự nhân chia ở đây.
             *
             * `$unit_price` ĐÃ là đơn giá sau chiết khấu, nên tham số chiết khấu
             * truyền vào 0; truyền lại discount_amount là trừ hai lần.
             *
             * Thuế tính trên tiền hàng SAU chiết khấu — giảm giá thì thuế giảm
             * theo, đúng luật và đúng cách phần mềm cũ làm.
             */
            $money = self::money_class();
            if ($money === '') {
                return [
                    'success' => false,
                    'message' => 'Thiếu lớp tính tiền (TGS_Money / TGS_POS_Money). '
                        . 'Không thể dựng số gửi cơ quan thuế nếu tự tính tay.',
                ];
            }

            $line        = $money::line($quantity, $unit_price, 0, $tax_percent);

            /*
             * ─── BA SỐ CỦA DÒNG, NEO VÀO TIỀN KHÁCH THẬT SỰ TRẢ ─────────────
             *
             * `itemTotalAmountWithTax` phải bằng ĐÚNG số đã thu của dòng đó,
             * nên nó được làm tròn TRỰC TIẾP từ `thanh_tien`, rồi tiền thuế mới
             * suy ra bằng hiệu. Nhờ vậy:
             *
             *   • without + tax = with_tax  → thoả ràng buộc Viettel đối chiếu
             *   • Σ with_tax    = đúng số tiền phiếu bán đã thu của khách
             *
             * Bản cũ làm tròn riêng `tien_hang_sau_ck` và `thue` rồi cộng lại:
             * hai phần lẻ cùng ≥ 0,5 là hoá đơn khai dôi 1đ so với số đã thu, mà
             * hoá đơn phát hành rồi thì không sửa được. Đây cũng đúng quy tắc
             * "làm tròn từng dòng rồi mới cộng" ở mục 2 của tài liệu mô hình
             * tiền, và trùng cách POS chốt `local_ledger_item_tax_amount`
             * (thuế = tiền đã thu − tiền hàng trước thuế).
             */
            $without_tax = max(0, (int) round($line['tien_hang_sau_ck']));
            $with_tax    = max(0, (int) round($line['thanh_tien']));
            $tax_amount  = max(0, $with_tax - $without_tax);

            /*
             * ─── ĐỐI CHIẾU VỚI SỐ ĐÃ CHỐT LÚC BÁN ───────────────────────────
             *
             * `local_ledger_item_tax_amount` là tiền thuế khoá tại thời điểm
             * phát sinh, tức phần thuế nằm trong số khách đã trả. Dựng lại từ
             * 5 cột gốc mà ra số khác quá 1đ thì dòng đó đang tự mâu thuẫn
             * (giá/thuế bị tách sai tỉ lệ — bẫy 7.6), KHÔNG phải chuyện làm
             * tròn. Hoá đơn phát hành rồi không sửa được nên dừng ở đây, giống
             * cách luồng này đã chặn dòng thiếu thuế suất.
             *
             * Bỏ qua khi dòng chưa có tiền thuế (đơn cũ, chờ bước vá dữ liệu
             * bù vào) — chặn cả những dòng đó thì quầy không xuất được hoá đơn.
             */
            $stored_tax = (float) ($item['stored_tax_amount'] ?? 0);
            if ($stored_tax > 0 && abs($stored_tax - $tax_amount) > 1.0) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Dòng "%s" có tiền thuế lệch với số đã chốt lúc bán (%sđ so với %sđ). '
                            . 'Kiểm tra lại đơn giá/thuế của dòng này trước khi phát hành hóa đơn.',
                        (string) ($item['sku'] ?? ''),
                        number_format($tax_amount, 0, ',', '.'),
                        number_format($stored_tax, 0, ',', '.')
                    ),
                ];
            }

            $sum_without_tax += $without_tax;
            $sum_tax         += $tax_amount;
            $sum_with_tax    += $with_tax;

            $key = (string) $tax_percent;
            if (!isset($tax_breakdown_map[$key])) {
                $tax_breakdown_map[$key] = [
                    'taxPercentage' => $tax_percent,
                    'taxableAmount' => 0,
                    'taxAmount' => 0,
                ];
            }
            $tax_breakdown_map[$key]['taxableAmount'] += $without_tax;
            $tax_breakdown_map[$key]['taxAmount'] += $tax_amount;

            $item_note = $this->build_invoice_item_note($item);
            $item_info[] = [
                'lineNumber' => $line_number,
                'selection' => 1,
                'itemCode' => (string) ($item['sku'] ?? ''),
                'itemName' => (string) ($item['item_name'] ?? ''),
                'unitName' => (string) ($item['unit_name'] ?? ''),
                'quantity' => $quantity,
                /*
                 * ─── ĐƠN GIÁ TỐI ĐA 4 CHỮ SỐ THẬP PHÂN ──────────────────────
                 *
                 * Viettel từ chối payload có đơn giá lẻ hơn 4 số:
                 *   {"code":400,"message":"INVALID_DECIMAL_POINT_PRICE",
                 *    "data":"Đơn giá của hàng hóa có phần thập phân tối đa 4 ký tự"}
                 *
                 * Đơn giá ở đây là tiền hàng sau CK chia cho số lượng, nên rất
                 * hay ra số vô hạn tuần hoàn — đơn HD80_N59XC: 388.889 / 12 =
                 * 32.407,41666… Bản cũ làm tròn 6 số nên bị chặn.
                 *
                 * KHÔNG ảnh hưởng tiền: ba con số quyết định của dòng
                 * (itemTotalAmountWithoutTax / WithTax / taxAmount) đã được chốt
                 * ở trên theo đúng số khách trả, unitPrice chỉ là số hiển thị.
                 * Chênh do làm tròn ở mức 0,0001đ × số lượng, không tới 1đ.
                 *
                 * Dùng sprintf rồi ép lại float thay vì round(): với server đặt
                 * serialize_precision cao, round(x, 4) vẫn có thể bị json_encode
                 * in ra 32407.416700000001 — đúng cái lỗi cần tránh.
                 */
                'unitPrice' => (float) sprintf('%.4F', $unit_price),
                'itemTotalAmountWithoutTax' => $without_tax,
                'itemTotalAmountAfterDiscount' => $without_tax,
                'itemTotalAmountWithTax' => $with_tax,
                'taxPercentage' => $tax_percent,
                'taxAmount' => $tax_amount,
                'itemNote' => !empty($item['is_gift']) ? 'Hàng tặng khuyến mãi' : null,
                'isIncreaseItem' => null,
            ];

            $item_info[count($item_info) - 1]['itemNote'] = $item_note !== '' ? $item_note : null;
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
                'templateCode' => !empty($settings['default_template_code']) ? $settings['default_template_code'] : '1/770',
                'invoiceSeries' => !empty($settings['default_invoice_series']) ? $settings['default_invoice_series'] : 'K23TXM',
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
                'buyerEmail' => !empty($customer['customer_email']) ? $customer['customer_email'] : null,
                'buyerNotGetInvoice' => '0',
            ],
            'payments' => [
                [
                    'paymentMethod' => '3',
                    'paymentMethodName' => $payment_method_name,
                ],
            ],
            'itemInfo' => $item_info,
            'taxBreakdowns' => array_values($tax_breakdown_map),
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

    /*
     * ─── ĐÃ XOÁ: resolve_item_line_total_without_tax() và resolve_item_tax_amount()
     *
     * Hai hàm đó tự cài lại công thức (1)(2)(4) trong khi lớp tính tiền đã có
     * sẵn, và tệ hơn: khi dòng chưa khai thuế suất thì `resolve_item_tax_amount()`
     * mặc định lấy `8.0`.
     *
     * Đúng cái bẫy mà đầu file này cảnh báo — `0` là hàng miễn thuế THẬT, khác
     * hẳn NULL là chưa khai. Điền đại 8% cho dòng chưa khai là khai THỪA thuế
     * cho hàng lẽ ra miễn thuế, mà hoá đơn đã phát hành thì không sửa được.
     * Cách xử lý đúng đã có sẵn: lines_missing_tax() chặn lại và báo rõ mã hàng.
     *
     * Cả hai chưa từng được gọi ở đâu, nhưng để lại là sớm muộn có người nối
     * dây vào. Cần tính tiền thì gọi money_class().
     */

    private function build_invoice_item_note(array $item)
    {
        $notes = [];

        if (!empty($item['is_gift'])) {
            $notes[] = 'Hang tang khuyen mai';
        }

        /*
         * CK% đã được suy sẵn từ tiền chiết khấu ở build_smart_payload_from_sale().
         * Trước đây chỗ này đọc `discount_type` / `discount_value` — hai cột
         * ngừng dùng, thậm chí không nằm trong câu SELECT, nên nhánh phần trăm
         * không bao giờ chạy.
         */
        $discount_amount = max(0, (int) round(floatval($item['discount_amount'] ?? 0)));
        $discount_percent = floatval($item['discount_percent'] ?? 0);

        if ($discount_amount > 0) {
            $note = 'Chiet khau ' . number_format($discount_amount, 0, ',', '.') . 'd';
            if ($discount_percent > 0) {
                $note .= ' (' . rtrim(rtrim(number_format($discount_percent, 2, '.', ''), '0'), '.') . '%)';
            }
            $notes[] = $note;
        }

        return implode(' | ', $notes);
    }

    private function find_under24_skus(array $skus)
    {
        $skus = array_values(array_filter(array_map('trim', $skus)));
        if (empty($skus)) {
            return [];
        }

        if (class_exists('TGS_Viettel_Invoice_Global_Products')) {
            return TGS_Viettel_Invoice_Global_Products::find_under24_skus($skus);
        }

        return [];
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
