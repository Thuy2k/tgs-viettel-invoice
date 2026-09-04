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

    /*
     * ─── KCT: MÃ THUẾ SUẤT GỬI CHO VIETTEL ────────────────────────────────────
     *
     * Hàng KHÔNG CHỊU THUẾ không phải "thuế suất 0%" — hai thứ kê khai khác
     * nhau. Viettel nhận mã ÂM ở `taxPercentage` để diễn đạt chuyện này.
     *
     * ĐÃ THỬ TRÊN HÓA ĐƠN THẬT (24/08/2026, ký hiệu 1C26TNH số 13937):
     *
     *     -1  →  KKKNT  (không kê khai nộp thuế)   ← KHÔNG phải cái ta cần
     *     -2  →  KCT    (không chịu thuế)
     *
     * Gửi -1 thì hoá đơn in ra chữ "KKKNT" và dòng tổng
     * "Tổng tiền không chịu thuế" bỏ trống — sai bản chất kê khai.
     *
     * Đổi được bằng filter nếu Viettel đổi quy ước, không phải sửa code:
     *
     *     add_filter('tgs_viettel_invoice_kct_tax_code', fn() => -1);
     */
    const KCT_TAX_CODE = -2;

    /*
     * ─── MÃ CHỨNG TỪ: MÃ PHIẾU BÁN PHẢI ĐI LÊN VIETTEL ──────────────────────
     *
     * Kế toán cần đối chiếu hoá đơn Viettel với phiếu bán bên mình, nên mã
     * phiếu (CNTESTAA10008…) phải nằm trong file *Báo cáo bán hàng chi tiết*
     * tải từ trang Viettel. Phần mềm cũ (MST 0106933743-020) để ở cột
     * "Mã chứng từ"; bên mình HIỆN CHƯA CÓ cột đó, lý do ở dưới.
     *
     * ─── ĐÃ ĐO ĐƯỢC GÌ (MST test 0100109106-507) ───────────────────────────
     *
     * [1] Khối `metadata` đi vào phần *Thông tin khác* (TTKhac) của hoá đơn.
     *     Đọc lại bằng getInvoiceRepresentationFile fileType 'ZIP' (fileType
     *     'XML' bị FILE_TYPE_INVALID) rồi mở file .xml trong gói.
     *
     * [2] keyTag 'invoiceNote' là tag riêng của Viettel: khai keyLabel gì cũng
     *     bị ép nhãn "Ghi chú" (hoá đơn C26TNH17644).
     *
     * [3] Tag lạ KHÔNG bị từ chối và KHÔNG in ra file PDF: bắn 12 tag
     *     (C26TNH18240) rồi 20 tag (C26TNH18246) đều HTTP 200, XML lưu đủ.
     *
     * [4] NHƯNG báo cáo Excel chỉ hiện những trường mà MẪU HOÁ ĐƠN CÓ KHAI.
     *     Bản xuất "tất cả ký hiệu" (129 cột) cho thấy mỗi cột thông tin khác
     *     chỉ có dữ liệu ở đúng một ký hiệu: "Ghi chú" ↔ C26TNH, "Biển số xe/
     *     Trọng tải/Giờ vào" ↔ C26MAA, "Lớp" ↔ K26TOQ, "Lệnh điều động nội
     *     bộ" ↔ K26NXA. Xuất lại sau khi có hai hoá đơn dò: KHÔNG dòng nào
     *     hiện 20 tag kia — cả file không có nổi một ký tự '#'.
     *
     * ⇒ Mẫu C26TNH chỉ khai mỗi "Ghi chú", nên ĐÓ LÀ Ô DUY NHẤT dùng được.
     *   Vì vậy Ghi chú mang ĐÚNG mã phiếu, không ghép thêm chữ, để kế toán
     *   VLOOKUP thẳng sang báo cáo bán hàng bên mình. Câu "Tự động phát hành
     *   từ POS" chỉ còn là dự phòng cho phiếu không đọc được mã.
     *
     * ─── KHI LÊN MST THẬT THÌ LÀM GÌ ───────────────────────────────────────
     *
     * Lúc Viettel dựng mẫu hoá đơn cho MST của công ty, YÊU CẦU KHAI THÊM
     * trường "Mã chứng từ" và xin luôn mã trường. Khi đó chỉ cần:
     *
     *     add_filter('tgs_viettel_invoice_document_code_key_tags', fn() => ['<mã trường Viettel cấp>']);
     *
     * và trả Ghi chú về câu cũ nếu muốn. Ba tag để sẵn dưới đây là phòng khi
     * mẫu mới khai sẵn một trong số đó — gửi thừa vô hại (đã đo ở [3]).
     */
    const DOCUMENT_CODE_LABEL = 'Mã chứng từ';
    const DOCUMENT_CODE_NOTE_FALLBACK = 'Tự động phát hành từ POS';
    const DOCUMENT_CODE_KEY_TAGS = ['maChungTu', 'documentCode', 'docNo'];

    /*
     * Có nhét mã phiếu vào ô "Ghi chú" nữa không. BẬT — và CỨ ĐỂ BẬT.
     *
     * Chủ đích: mã phiếu nằm ở CẢ HAI ô, "Ghi chú" lẫn "Mã chứng từ", cho
     * chắc. Lý do:
     *
     *   • Mẫu test chỉ khai mỗi trường "Ghi chú" — không nhét vào đấy thì kế
     *     toán không thấy mã phiếu ở đâu cả.
     *   • Mẫu thật có cột "Mã chứng từ", NHƯNG chưa đo được là Viettel khớp
     *     dữ liệu theo TÊN trường hay theo mã trường nội bộ. Nếu khớp theo mã
     *     trường mà tag mình đoán không trùng thì cột đó trống — lúc ấy ô Ghi
     *     chú là chỗ duy nhất còn mã phiếu.
     *
     * Đánh đổi: ô Ghi chú không còn câu "Tự động phát hành từ POS", và ghi chú
     * của hoá đơn điều chỉnh không còn câu "Phiếu hoàn X: lý do" (lý do vẫn
     * nằm nguyên ở invoiceNote/additionalReferenceDesc/adjustedNote trong
     * generalInvoiceInfo, in ra hoá đơn được).
     *
     * Chỉ tắt khi đã chắc cột "Mã chứng từ" ăn và muốn trả ô Ghi chú về câu cũ:
     *     add_filter('tgs_viettel_invoice_document_code_in_note', '__return_false');
     */
    const DOCUMENT_CODE_IN_NOTE = true;

    /** Danh sách keyTag ứng viên cho trường "Mã chứng từ". Rỗng = không gửi. */
    public static function document_code_key_tags()
    {
        $tags = apply_filters('tgs_viettel_invoice_document_code_key_tags', self::DOCUMENT_CODE_KEY_TAGS);
        if (is_string($tags)) {
            $tags = [$tags];
        }
        if (!is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            $tag = is_string($tag) ? trim($tag) : '';
            if ($tag !== '' && !in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }
        return $clean;
    }

    /**
     * Dựng khối metadata cho hoá đơn — dùng cho CẢ hoá đơn bán lẫn hoá đơn
     * điều chỉnh do trả hàng, đừng dựng tay ở nơi khác.
     *
     * @param string      $document_code Mã chứng từ: mã phiếu bán, hoặc mã
     *                                   phiếu hoàn nếu là hoá đơn điều chỉnh.
     * @param string|null $note          Câu ghi chú DỰ PHÒNG, chỉ dùng khi
     *                                   không nhét mã phiếu vào ô Ghi chú
     *                                   (DOCUMENT_CODE_IN_NOTE tắt) hoặc khi
     *                                   phiếu không đọc được mã. Phiếu hoàn
     *                                   truyền câu "Phiếu hoàn X: lý do" vào
     *                                   đây để tắt công tắc là ghi chú trở về
     *                                   y như cũ.
     */
    public static function build_invoice_metadata($document_code, $note = null)
    {
        $document_code = trim((string) $document_code);
        $fallback_note = $note === null ? '' : trim((string) $note);
        if ($fallback_note === '') {
            $fallback_note = self::DOCUMENT_CODE_NOTE_FALLBACK;
        }

        $in_note = (bool) apply_filters(
            'tgs_viettel_invoice_document_code_in_note',
            self::DOCUMENT_CODE_IN_NOTE
        );
        $note = ($in_note && $document_code !== '') ? $document_code : $fallback_note;

        $metadata = [
            [
                'keyTag' => 'invoiceNote',
                'stringValue' => $note,
                'valueType' => 'text',
                'keyLabel' => 'Ghi chú',
            ],
        ];

        if ($document_code === '') {
            return $metadata;
        }

        foreach (self::document_code_key_tags() as $key_tag) {
            $metadata[] = [
                'keyTag' => $key_tag,
                'stringValue' => $document_code,
                'valueType' => 'text',
                'keyLabel' => self::DOCUMENT_CODE_LABEL,
            ];
        }

        return $metadata;
    }

    /*
     * ─── KHÁCH LẺ: TÊN NGƯỜI MUA TRÊN HOÁ ĐƠN ────────────────────────────────
     *
     * Bán lẻ không lấy thông tin khách thì hoá đơn phải ghi
     * "Bán cho người tiêu dùng" ở CẢ hai dòng: *Họ tên người mua hàng*
     * và *Tên đơn vị*. Ghi "Khách lẻ" là tên nội bộ của phần mềm, không phải
     * cách kê khai.
     *
     * Chỉ áp dụng khi THẬT SỰ là khách lẻ: không có mã số thuế, và tên để trống
     * hoặc đang là nhãn mặc định. Khách có khai tên thật thì giữ nguyên tên họ.
     */
    const RETAIL_BUYER_LABEL = 'Bán cho người tiêu dùng';

    /** Nhãn người mua cho khách lẻ. Đổi được bằng filter. */
    public static function retail_buyer_label()
    {
        $label = apply_filters('tgs_viettel_invoice_retail_buyer_label', self::RETAIL_BUYER_LABEL);
        $label = trim((string) $label);

        return $label !== '' ? $label : self::RETAIL_BUYER_LABEL;
    }

    /**
     * Tên này có phải NHÃN NỘI BỘ của phần mềm không (không xét mã số thuế).
     *
     * "Khách lẻ" là cách phần mềm gọi khi chưa lấy thông tin khách — KHÔNG phải
     * tên một con người, nên tuyệt đối không được đi lên hoá đơn.
     *
     * TÁCH RIÊNG KHỎI is_retail_buyer() VÌ HAI CÂU HỎI KHÁC NHAU:
     *
     *   is_retail_buyer()           — "giao dịch này có phải bán lẻ không"
     *                                 Có MST ⇒ KHÔNG, vì đã bán cho đơn vị.
     *   is_placeholder_buyer_name() — "ô tên đang để nhãn nội bộ à"
     *                                 Không liên quan MST.
     *
     * Gộp hai câu này làm một chính là lỗi đã gặp: khách đưa mã số thuế công ty
     * nhưng chưa khai tên người mua, hoá đơn gửi lên cơ quan thuế ghi nguyên
     * "Khách lẻ" ở dòng Họ tên người mua hàng.
     *
     * ⚠️ Phải khớp với JS: tgsIsPlaceholderBuyerName() trong tgs-retail-buyer.js
     *
     * @param string $name
     * @return bool
     */
    public static function is_placeholder_buyer_name($name)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }

        // remove_accents() của WordPress xử lý được tiếng Việt có dấu.
        $folded = function_exists('remove_accents') ? remove_accents($name) : $name;
        $folded = strtolower(preg_replace('/\s+/', ' ', trim($folded)));

        $placeholders = [
            'khach le',
            'khach hang le',
            'khach vang lai',
            'khach lẻ',
        ];

        return in_array($folded, $placeholders, true);
    }

    /**
     * Giao dịch này có phải BÁN LẺ không.
     *
     * Có mã số thuế ⇒ không phải bán lẻ, dù tên là gì — dùng để quyết định dòng
     * TÊN ĐƠN VỊ trên hoá đơn (có MST thì phải ghi tên công ty thật).
     *
     * Còn dòng HỌ TÊN NGƯỜI MUA thì xét bằng is_placeholder_buyer_name().
     *
     * @param array $customer
     * @return bool
     */
    public static function is_retail_buyer($customer)
    {
        $customer = (array) $customer;

        if (trim((string) ($customer['customer_tax_code'] ?? '')) !== '') {
            return false;
        }

        return self::is_placeholder_buyer_name($customer['customer_name'] ?? '');
    }

    /** Mã thuế suất dùng cho dòng không chịu thuế. */
    public static function kct_tax_code()
    {
        return (float) apply_filters('tgs_viettel_invoice_kct_tax_code', self::KCT_TAX_CODE);
    }

    /** Dòng này có phải hàng không chịu thuế không. */
    public static function is_kct_line($item)
    {
        $item = (array) $item;
        return (int) ($item['is_kct'] ?? $item['local_ledger_item_is_kct'] ?? 0) === 1;
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

    /**
     * ─── SỐ CHỮ SỐ THẬP PHÂN CỦA ĐƠN GIÁ GỬI THUẾ ───────────────────────────
     *
     * Viettel chặn payload có đơn giá lẻ hơn mức cấu hình của người nộp thuế:
     *   {"code":400,"message":"INVALID_DECIMAL_POINT_PRICE",
     *    "data":"Đơn giá của hàng hóa có phần thập phân tối đa N ký tự"}
     *
     * Con số N KHÔNG cố định: nó là cấu hình "số chữ số thập phân của đơn giá"
     * bên phía Viettel/CQT. Từ 24/08/2026 tới 09/2026 hệ thống trả về N = 0
     * (đơn giá phải là số nguyên) — kế toán đã xác nhận lại với Viettel,
     * giờ đơn giá được phép có phần lẻ nên chốt N = 2 (đơn giá 2 số thập
     * phân, khớp đúng "cơ chế làm tròn ra hoá đơn: thuế 2 số" — báo cáo quản
     * trị nội bộ dùng 4 số, xem `mo-hinh-tien-va-bang-local-ledger-item.md`).
     * Để một chỗ duy nhất, đổi hằng số (hoặc móc filter) là cả hoá đơn gốc
     * lẫn hoá đơn điều chỉnh cùng đổi theo, không phải đi sửa từng chỗ rồi
     * lệch nhau.
     *
     * KHÔNG ảnh hưởng số tiền khách trả: itemTotalAmountWithTax luôn chốt
     * theo đúng số đã thu (tròn đồng); unitPrice/withoutTax/taxAmount là ba
     * số CÒN LẠI được phép mang phần lẻ theo N này.
     */
    const UNIT_PRICE_DECIMALS = 2;

    public static function unit_price_decimals()
    {
        $decimals = (int) apply_filters(
            'tgs_viettel_invoice_unit_price_decimals',
            self::UNIT_PRICE_DECIMALS
        );

        return max(0, min(4, $decimals));
    }

    /**
     * Đơn giá đã cắt về đúng số chữ số thập phân Viettel cho phép.
     *
     * round() trước cho ra cách làm tròn nhất quán (0,5 lên trên), rồi sprintf
     * và ép kiểu lại: với server đặt serialize_precision cao, chỉ round(x, 4)
     * không thôi vẫn có thể bị json_encode in ra 32407.416700000001 — đúng cái
     * lỗi cần tránh.
     *
     * Không cho phép thập phân thì trả về HẲN số nguyên, để json_encode chắc
     * chắn in "32407" chứ không phải "32407.0" — dấu chấm với một chữ số 0
     * đằng sau cũng đủ để phía Viettel đếm thành 1 ký tự thập phân.
     */
    public static function api_unit_price($unit_price, $lam_tron_len = false)
    {
        $decimals = self::unit_price_decimals();

        if ($lam_tron_len) {
            /*
             * round() trước ở mức 6 chữ số để phần lẻ nhị phân không đẩy một
             * đơn giá vốn đã chẵn lên thêm một đồng (33.334,0000000001).
             */
            $factor = pow(10, $decimals);
            $value  = ceil(round(max(0.0, (float) $unit_price) * $factor, 6)) / $factor;
        } else {
            $value = round(max(0.0, (float) $unit_price), $decimals);
        }

        if ($decimals === 0) {
            return (int) $value;
        }

        return (float) sprintf('%.' . $decimals . 'F', $value);
    }

    /**
     * ─── BA SỐ CỦA DÒNG PHẢI KHỚP VỚI ĐƠN GIÁ ĐÃ LÀM TRÒN ───────────────────
     *
     * Viettel đối chiếu ngay trên payload:
     *
     *     unitPrice × quantity == itemTotalAmountWithoutTax
     *
     * Lệch một đồng là trả về 400:
     *   IVI_TOTAL_A_WITHOUT_TAX_AND_UP_QUAN_NOT_COMPARED
     *   "Đơn giá nhân Số lượng không so khớp với Thành tiền"
     *
     * Từ 24/08/2026 đơn giá bắt buộc là SỐ NGUYÊN (unit_price_decimals() = 0),
     * mà tiền hàng chia cho số lượng thì gần như luôn lẻ — đơn 19011AA00527:
     * 111.111 ÷ 24 = 4.629,625 → khai 4.630 thì 4.630 × 24 = 111.120, lệch 9đ
     * với tiền hàng thật. KHÔNG có cách nào vừa giữ đơn giá nguyên vừa giữ
     * nguyên tiền hàng lẻ; bắt buộc phải chọn số nào được xê dịch.
     *
     * Chốt: NEO VÀO SỐ KHÁCH ĐÃ TRẢ (itemTotalAmountWithTax) — đúng mục 2 của
     * mô hình tiền: "hoá đơn phải neo vào Thành tiền, con số khách trả". Phần
     * lệch do làm tròn đơn giá dồn hết vào TIỀN THUẾ:
     *
     *     unitPrice  = làm tròn theo số thập phân Viettel cho phép
     *     withoutTax = unitPrice × quantity     ← điều kiện của Viettel
     *     withTax    = giữ nguyên số đã thu     ← điều kiện của bill
     *     taxAmount  = withTax − withoutTax
     *
     * Nhờ vậy thành tiền TỪNG DÒNG trên hoá đơn vẫn bằng đúng thành tiền từng
     * dòng của phiếu bán, và tổng hoá đơn không xê dịch một đồng nào. Cái phải
     * trả giá là tiền thuế khai lệch với "tiền hàng × thuế suất" tối đa nửa
     * đồng nhân số lượng (dòng 24 chai: 8.880 so với 8.889,6) — sai số làm
     * tròn, đi cả hai chiều, không phải khai sai bản chất.
     *
     * ⚠️ Dòng thuế 0% / KCT không có chỗ để dồn (tiền thuế phải bằng 0), nên
     * withTax buộc phải bám theo withoutTax, và đơn giá LÀM TRÒN LÊN để hoá
     * đơn không bao giờ khai thiếu hơn số đã thu. Đây là trường hợp DUY NHẤT
     * hoá đơn lệch với bill — dưới một đồng nhân số lượng, và chỉ khi tiền
     * hàng không chia hết cho số lượng.
     *
     * Để MỘT CHỖ DUY NHẤT vì hoá đơn điều chỉnh (trả hàng) phải dựng số y hệt
     * hoá đơn gốc — hai bên lệch nhau là cơ quan thuế đối chiếu ra ngay.
     *
     * @param float $quantity    Số lượng theo ĐVT khai trên hoá đơn
     * @param float $unit_price  Đơn giá sau CK trước thuế, CHƯA làm tròn
     * @param int   $with_tax    Tiền dòng gồm thuế — số khách thật sự trả
     * @param float $tax_percent Thuế suất của chính dòng đó (KCT đã quy về 0)
     */
    /**
     * ─── SỐ LƯỢNG LẺ: NẮN ĐƠN GIÁ ĐỂ TÍCH TRÒN ĐỒNG ─────────────────────────
     *
     * Hoá đơn điều chỉnh hay có số lượng lẻ: bán "1 Vỉ_4" mà khách trả 1 hộp
     * thì số lượng hoàn quy về ĐVT của hoá đơn gốc là 0,25 vỉ. Đơn giá 92.593
     * × 0,25 = 23.148,25 — không tròn đồng, mà itemTotalAmountWithoutTax thì
     * bắt buộc là số nguyên ⇒ Viettel lại trả
     * IVI_TOTAL_A_WITHOUT_TAX_AND_UP_QUAN_NOT_COMPARED.
     *
     * Cách duy nhất còn lại là nhích ĐƠN GIÁ tới giá trị gần nhất mà tích ra
     * số nguyên: 0,25 = 1/4 nên đơn giá phải chia hết cho 4 → 92.592, lệch
     * đúng 1đ so với đơn giá đã khai ở hoá đơn gốc, còn tiền hàng thì tròn
     * đồng và khớp tuyệt đối với ràng buộc của Viettel.
     *
     * Số lượng nguyên (gần như toàn bộ dòng bán) không bao giờ đi vào vòng dò
     * này: tích đã tròn đồng sẵn thì trả về ngay.
     *
     * Dò tối đa NAN_DON_GIA_TOI_DA bước để không treo khi số lượng là số thập
     * phân không quy được về phân số đơn giản (0,333…); không tìm được thì trả
     * lại đơn giá cũ — thà để Viettel báo lỗi còn hơn tự bịa một con số xa.
     */
    const NAN_DON_GIA_TOI_DA = 200;

    private static function nan_don_gia_cho_tron_dong($api_price, $quantity, $uu_tien_len = false)
    {
        $quantity = (float) $quantity;
        if ($quantity <= 0) {
            return $api_price;
        }

        /*
         * ⚠️ So khớp "đã tròn, khỏi nắn" phải xét ở ĐÚNG $decimals chữ số lẻ
         * Viettel đang cho phép, KHÔNG phải luôn so với số nguyên — nếu không,
         * khi $decimals > 0 thì tích gần như không bao giờ là số nguyên tuyệt
         * đối, mọi dòng đều rơi vào vòng dò bên dưới đi tìm một đơn giá cho
         * tích ra SỐ NGUYÊN, vô tình lại ép về đúng hành vi "đơn giá nguyên"
         * đã bỏ. Ở decimals = 0 thì round($x, 0) === round($x), hành vi cũ
         * không đổi một chút nào.
         */
        $decimals = self::unit_price_decimals();

        $tich = $api_price * $quantity;
        if (abs($tich - round($tich, $decimals)) < 1e-6) {
            return $api_price;
        }

        $factor = pow(10, $decimals);
        $n0     = (int) round($api_price * $factor);

        for ($i = 1; $i <= self::NAN_DON_GIA_TOI_DA; $i++) {
            // Ưu tiên nhích LÊN, để hoá đơn không khai thiếu hơn số đã thu.
            $ung_vien = $uu_tien_len ? [$n0 + $i] : [$n0 + $i, $n0 - $i];
            foreach ($ung_vien as $n) {
                if ($n < 0) {
                    continue;
                }
                $thu = $n * $quantity / $factor;
                if (abs($thu - round($thu, $decimals)) < 1e-6) {
                    return $decimals === 0
                        ? (int) round($n / $factor)
                        : (float) sprintf('%.' . $decimals . 'F', $n / $factor);
                }
            }
        }

        return $api_price;
    }

    public static function api_line_amounts($quantity, $unit_price, $with_tax, $tax_percent)
    {
        $quantity = max(0.0, (float) $quantity);
        $decimals = self::unit_price_decimals();
        // $with_tax là số khách ĐÃ TRẢ (chốt ở lúc bán) — luôn tròn đồng,
        // không phụ thuộc $decimals của đơn giá gửi Viettel.
        $with_tax = max(0, (int) round($with_tax));

        /*
         * Thuế 0% / KCT: tiền thuế phải bằng 0 nên không có chỗ dồn phần lẻ —
         * đây là trường hợp duy nhất tiền dòng trên hoá đơn buộc phải xê dịch
         * so với bill. Chốt LÀM TRÒN LÊN để hoá đơn không bao giờ khai thiếu
         * hơn số đã thu: 100.000 chia 3 → đơn giá 33.334, dòng ra 100.002
         * (ở $decimals = 0; với $decimals = 2 phần dôi chỉ còn tối đa 0,01đ
         * × số lượng thay vì cả đồng).
         */
        if ((float) $tax_percent <= 0) {
            $api_price   = self::nan_don_gia_cho_tron_dong(self::api_unit_price($unit_price, true), $quantity, true);
            $without_tax = max(0, round($api_price * $quantity, $decimals));

            return [
                'unit_price'  => $api_price,
                'without_tax' => $without_tax,
                'with_tax'    => $without_tax,
                'tax_amount'  => 0,
            ];
        }

        $api_price   = self::nan_don_gia_cho_tron_dong(self::api_unit_price($unit_price), $quantity);
        // KHÔNG ép (int): unitPrice × quantity giờ được phép có phần lẻ tới
        // $decimals chữ số — đây chính là chỗ trước kia dồn hết phần lẻ vào
        // tiền thuế vì withoutTax bị ép nguyên.
        $without_tax = max(0, round($api_price * $quantity, $decimals));

        // Chặn tiền thuế âm nếu đơn giá làm tròn lên vượt cả số đã thu.
        $with_tax = max($with_tax, $without_tax);

        return [
            'unit_price'  => $api_price,
            'without_tax' => $without_tax,
            'with_tax'    => $with_tax,
            'tax_amount'  => round($with_tax - $without_tax, $decimals),
        ];
    }

    /** Các trường người mua được phép lưu kèm đơn để phát hành hoá đơn */
    const INVOICE_BUYER_FIELDS = [
        'customer_name',
        'customer_company_name',
        'customer_tax_code',
        'customer_address',
        'customer_phone',
        'customer_email',
    ];

    /** Khoá trong meta của đơn giữ người mua đã chốt ở màn review */
    const INVOICE_BUYER_META_KEY = 'tax_invoice_buyer';

    /**
     * Người mua đã chốt cho ĐƠN NÀY, đọc từ meta của đơn.
     *
     * Trả về mảng RỖNG khi chưa ai chốt — để chỗ gọi giữ nguyên khách của đơn.
     * Chỉ nhận các trường có giá trị: lưu chuỗi rỗng đè lên tên khách thật thì
     * hoá đơn ra trắng tên, tệ hơn là để nguyên "Khách lẻ".
     */
    private static function saved_invoice_buyer($sale_ledger_id, $meta_id)
    {
        global $wpdb;

        $meta_id = intval($meta_id);
        if ($meta_id <= 0 || !defined('TGS_TABLE_LOCAL_LEDGER_META')) {
            return [];
        }

        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT local_ledger_meta_value FROM ' . TGS_TABLE_LOCAL_LEDGER_META
                . ' WHERE local_ledger_meta_id = %d LIMIT 1',
            $meta_id
        ));

        $meta = json_decode((string) $raw, true);
        if (!is_array($meta) || !is_array($meta[self::INVOICE_BUYER_META_KEY] ?? null)) {
            return [];
        }

        $buyer = [];
        foreach (self::INVOICE_BUYER_FIELDS as $field) {
            $value = $meta[self::INVOICE_BUYER_META_KEY][$field] ?? '';
            if (is_scalar($value) && trim((string) $value) !== '') {
                $buyer[$field] = sanitize_text_field((string) $value);
            }
        }

        return $buyer;
    }

    /** Bản công khai của saved_invoice_buyer() cho màn review gọi vào */
    public static function public_saved_invoice_buyer($sale_ledger_id, $meta_id)
    {
        return self::saved_invoice_buyer($sale_ledger_id, $meta_id);
    }
    /**
     * GHI NHỚ NGƯỜI MUA cho đơn, để lần gửi sau không phải gõ lại.
     *
     * Vì sao phải lưu: thông tin nhân viên gõ ở màn review trước giờ chỉ sống
     * trong trình duyệt. Lần gửi đầu lỗi rồi gửi lại bằng cron / nút gửi lại ở
     * trang quản trị là hoá đơn ra "Khách lẻ", vì những đường đó không có form
     * để mà gõ.
     *
     * Vì sao KHÔNG ghi vào bảng khách: "Khách lẻ" là một bản ghi dùng chung cho
     * hàng trăm đơn (đo trên dữ liệu thật: 334 đơn cùng trỏ vào một khách). Sửa
     * vào đó là đổi luôn người mua của mọi đơn cũ.
     *
     * Cách lưu bám đúng nếp của tgs_pos: meta là bảng CHỈ THÊM DÒNG, ghi xong
     * mới trỏ đơn sang dòng mới — lịch sử cũ giữ nguyên, không sửa đè.
     */
    public static function save_invoice_buyer_to_sale($sale_ledger_id, array $buyer)
    {
        global $wpdb;

        $sale_ledger_id = intval($sale_ledger_id);
        if ($sale_ledger_id <= 0 || !defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_META')) {
            return false;
        }

        $clean = [];
        foreach (self::INVOICE_BUYER_FIELDS as $field) {
            $value = $buyer[$field] ?? '';
            if (is_scalar($value) && trim((string) $value) !== '') {
                $clean[$field] = sanitize_text_field((string) $value);
            }
        }
        if (empty($clean)) {
            return false;
        }

        $meta_id = intval($wpdb->get_var($wpdb->prepare(
            'SELECT local_ledger_meta_id FROM ' . TGS_TABLE_LOCAL_LEDGER
                . ' WHERE local_ledger_id = %d LIMIT 1',
            $sale_ledger_id
        )));

        $meta = [];
        if ($meta_id > 0) {
            $raw = $wpdb->get_var($wpdb->prepare(
                'SELECT local_ledger_meta_value FROM ' . TGS_TABLE_LOCAL_LEDGER_META
                    . ' WHERE local_ledger_meta_id = %d LIMIT 1',
                $meta_id
            ));
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        // Gõ lại lần sau chỉ đổi trường vừa gõ, không xoá trắng những trường cũ
        $existing = is_array($meta[self::INVOICE_BUYER_META_KEY] ?? null)
            ? $meta[self::INVOICE_BUYER_META_KEY]
            : [];
        $meta[self::INVOICE_BUYER_META_KEY] = array_merge($existing, $clean);

        if ($meta[self::INVOICE_BUYER_META_KEY] === $existing) {
            return true; // không có gì đổi, khỏi sinh thêm dòng meta
        }

        $now = current_time('mysql');
        $inserted = $wpdb->insert(TGS_TABLE_LOCAL_LEDGER_META, [
            'local_ledger_meta_value' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            'user_id' => get_current_user_id(),
            'is_deleted' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$inserted || intval($wpdb->insert_id) <= 0) {
            return false;
        }

        $wpdb->update(
            TGS_TABLE_LOCAL_LEDGER,
            ['local_ledger_meta_id' => intval($wpdb->insert_id), 'updated_at' => $now],
            ['local_ledger_id' => $sale_ledger_id]
        );

        return true;
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
                'SELECT local_ledger_id, local_ledger_code, local_ledger_person_id, local_ledger_meta_id, local_ledger_item_id, created_at FROM ' . TGS_TABLE_LOCAL_LEDGER . ' WHERE local_ledger_id = %d LIMIT 1',
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

        /*
         * ─── NGƯỜI MUA ĐÃ CHỐT Ở MÀN REVIEW ĐÈ LÊN KHÁCH CỦA ĐƠN ────────────
         *
         * Đơn bán thường gắn "Khách lẻ" — một bản ghi khách DÙNG CHUNG cho hàng
         * trăm đơn. Khách nào cần hoá đơn thì nhân viên gõ tên/MST ở màn kiểm
         * tra trước khi gửi thuế, và thông tin đó được lưu riêng cho ĐƠN NÀY
         * (xem save_invoice_buyer_to_sale). Tuyệt đối không ghi vào bảng khách:
         * sửa "Khách lẻ" là sửa luôn người mua của mọi đơn cũ.
         *
         * Nhờ lưu lại mà mọi đường phát hành đều dùng đúng người mua: gửi từ
         * POS, gửi lại ở màn Gửi thuế, chạy tự động, hay gửi lại từ trang quản
         * trị — không còn phụ thuộc vào việc nhân viên có gõ lại hay không.
         */
        $saved_buyer = self::saved_invoice_buyer($sale_ledger_id, $sale['local_ledger_meta_id'] ?? 0);
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
        // Cờ KCT khoá lúc bán — chưa có cột (DB cũ) thì coi như có chịu thuế.
        $optional_selects[] = $this->local_ledger_item_column_exists('local_ledger_item_is_kct')
            ? 'i.local_ledger_item_is_kct'
            : '0 AS local_ledger_item_is_kct';
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
                // KCT khác mức 0% — xem tgs_shop_management/docs/quan-ly-thue-suat.md
                'is_kct' => (int) ($item['local_ledger_item_is_kct'] ?? 0) === 1 ? 1 : 0,
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
                // Người mua đã chốt ở màn review thắng khách mặc định của đơn
                'customer' => array_merge([
                    'customer_name' => (string) ($person['local_ledger_person_name'] ?? 'Khách lẻ'),
                    'customer_company_name' => (string) ($person['local_ledger_person_name'] ?? 'Khách lẻ'),
                    'customer_tax_code' => (string) ($person['local_ledger_person_tax_code'] ?? ''),
                    'customer_address' => (string) ($person['local_ledger_person_address'] ?? ''),
                    'customer_phone' => (string) ($person['local_ledger_person_phone'] ?? ''),
                    'customer_email' => (string) ($person['local_ledger_person_email'] ?? ''),
                ], $saved_buyer),
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

            /*
             * Hàng KHÔNG CHỊU THUẾ: tiền thuế bằng 0 (tính như 0%), nhưng mã
             * `taxPercentage` gửi cho Viềttel phải là mã KCT riêng, không phải số 0
             * — gửi 0 là khai thành "thuế suất 0%", sai bản chất.
             */
            $is_kct = self::is_kct_line($item);
            if ($is_kct) {
                $tax_percent = 0.0;
            }

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
             * ─── HAI SỐ CỦA SỔ SÁCH, NEO VÀO TIỀN KHÁCH THẬT SỰ TRẢ ─────────
             *
             * `$tien_khach_tra` phải bằng ĐÚNG số đã thu của dòng đó, nên nó
             * neo vào TIỀN THUẾ ĐÃ LƯU lúc bán, y như cách POS chốt tiền:
             *
             *     thành tiền = làm_tròn(tiền hàng sau CK + TIỀN THUẾ ĐÃ LƯU)
             *
             * (xem TGS_POS_Order_Handler — cột local_ledger_item_tax_amount).
             *
             * Bản cũ ở đây lại TÍNH LẠI tiền thuế từ thuế suất:
             *     thành tiền = làm_tròn(tiền hàng sau CK × (1 + thuế%))
             *
             * Hai cách chênh nhau 1đ mỗi khi chiết khấu có phần lẻ. Ví dụ thật:
             * hàng 450.000 giảm còn 430.000 → CK trước thuế 18.518,52 lưu thành
             * 18.519 → tính lại ra 429.999, trong khi khách trả 430.000. Kế toán
             * đối chiếu bill với hoá đơn là lệch ngay, mà hoá đơn phát hành rồi
             * thì không sửa được. Dòng nào chưa lưu tiền thuế (đơn cũ) mới rơi
             * về cách tính lại.
             */
            $stored_tax_raw  = (float) ($item['stored_tax_amount'] ?? 0);
            $so_sach_without = max(0, (int) round($line['tien_hang_sau_ck']));
            $tien_khach_tra  = $stored_tax_raw > 0
                ? max(0, (int) round($line['tien_hang_sau_ck'] + $stored_tax_raw))
                : max(0, (int) round($line['thanh_tien']));
            $so_sach_tax     = max(0, $tien_khach_tra - $so_sach_without);

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
             * ⚠️ Đối chiếu chạy trên SỐ SỔ SÁCH, TRƯỚC bước nắn theo đơn giá
             * gửi đi ở dưới. Bước đó cố tình dịch tiền thuế đi tới nửa đồng
             * nhân số lượng, đem so ở đó thì mọi dòng số lượng lớn đều báo
             * lệch oan và quầy không xuất được hoá đơn.
             *
             * Bỏ qua khi dòng chưa có tiền thuế (đơn cũ, chờ bước vá dữ liệu
             * bù vào) — chặn cả những dòng đó thì quầy không xuất được hoá đơn.
             */
            if ($stored_tax_raw > 0 && abs($stored_tax_raw - $so_sach_tax) > 1.0) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Dòng "%s" có tiền thuế lệch với số đã chốt lúc bán (%sđ so với %sđ). '
                            . 'Kiểm tra lại đơn giá/thuế của dòng này trước khi phát hành hóa đơn.',
                        (string) ($item['sku'] ?? ''),
                        number_format($so_sach_tax, 0, ',', '.'),
                        number_format($stored_tax_raw, 0, ',', '.')
                    ),
                ];
            }

            /*
             * ─── NẮN BA SỐ THEO ĐƠN GIÁ THẬT SỰ GỬI ĐI ──────────────────────
             *
             * Viettel bắt unitPrice × quantity == itemTotalAmountWithoutTax,
             * mà đơn giá gửi đi đã bị làm tròn về số nguyên. api_line_amounts()
             * giữ luật đó cho CẢ hoá đơn gốc lẫn hoá đơn điều chỉnh — đọc phần
             * chú thích ở đó trước khi đổi bất cứ con số nào dưới đây.
             */
            $api_line    = self::api_line_amounts($quantity, $unit_price, $tien_khach_tra, $tax_percent);
            $api_price   = $api_line['unit_price'];
            $without_tax = $api_line['without_tax'];
            $with_tax    = $api_line['with_tax'];
            $tax_amount  = $api_line['tax_amount'];

            $sum_without_tax += $without_tax;
            $sum_tax         += $tax_amount;
            $sum_with_tax    += $with_tax;

            // Mã gửi cho Viettel: KCT dùng mã riêng, còn lại là chính mức thuế.
            $api_tax_code = $is_kct ? self::kct_tax_code() : $tax_percent;

            // Nhóm KCT phải đứng RIÊNG với nhóm 0% trong bảng tổng hợp thuế.
            $key = (string) $api_tax_code;
            if (!isset($tax_breakdown_map[$key])) {
                $tax_breakdown_map[$key] = [
                    'taxPercentage' => $api_tax_code,
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
                 * Đơn giá ở đây là tiền hàng sau CK chia cho số lượng, nên rất
                 * hay ra số vô hạn tuần hoàn — đơn HD80_N59XC: 388.889 / 12 =
                 * 32.407,41666… Viettel chỉ nhận đơn giá đúng số chữ số thập
                 * phân họ cấu hình, lẻ hơn là trả INVALID_DECIMAL_POINT_PRICE.
                 * Xem unit_price_decimals().
                 *
                 * Lấy lại từ api_line_amounts() chứ KHÔNG gọi api_unit_price()
                 * lần nữa: ba con số tiền ở dưới đã dựng từ đúng đơn giá này,
                 * gọi lại là mở đường cho hai chỗ lệch nhau sau này.
                 */
                'unitPrice' => $api_price,
                'itemTotalAmountWithoutTax' => $without_tax,
                'itemTotalAmountAfterDiscount' => $without_tax,
                'itemTotalAmountWithTax' => $with_tax,
                'taxPercentage' => $api_tax_code,
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

        // Bán lẻ không lấy thông tin khách ⇒ ghi "Bán cho người tiêu dùng".
        $is_retail_buyer    = self::is_retail_buyer($customer);
        $retail_buyer_label = self::retail_buyer_label();

        /*
         * HỌ TÊN NGƯỜI MUA xét theo NHÃN, không theo mã số thuế.
         *
         * Khách đưa mã số thuế công ty nhưng nhân viên chưa khai tên người mua
         * thì ô tên vẫn đang là "Khách lẻ" — đó là nhãn nội bộ của phần mềm,
         * không phải tên người, và nó đã từng đi thẳng lên hoá đơn gửi cơ quan
         * thuế. Xem is_placeholder_buyer_name().
         */
        $buyer_name_is_placeholder = self::is_placeholder_buyer_name($customer['customer_name'] ?? '');

        /*
         * Mã chứng từ = mã phiếu bán bên mình (`local_ledger_code` của bảng
         * wp_local_ledger, chính con số quầy đọc trên bill, ví dụ
         * CNTESTAA10008). Đơn tách bill khuyến mãi thì mỗi phiếu con mang mã
         * riêng của nó, đúng nguyên tắc "hoá đơn nào mã chứng từ nấy".
         * Xem build_invoice_metadata() để biết vì sao phải gửi kèm keyTag.
         */
        $document_code = trim((string) ($filtered_payload['sale_code'] ?? ''));

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
                /*
                 * Khách lẻ ⇒ cả hai dòng đều ghi "Bán cho người tiêu dùng",
                 * không ghi "Khách lẻ" — xem is_retail_buyer().
                 */
                'buyerName' => $buyer_name_is_placeholder
                    ? $retail_buyer_label
                    : (string) ($customer['customer_name'] ?? ''),
                'buyerLegalName' => $is_retail_buyer
                    ? $retail_buyer_label
                    : (string) ($customer['customer_company_name'] ?? ''),
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
            'metadata' => self::build_invoice_metadata($document_code),
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
