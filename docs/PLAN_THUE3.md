# Kế hoạch THUE3 - TGS Viettel Invoice

## 1) Mục tiêu của bản triển khai này
- Không tập trung hóa đơn nháp.
- Luồng chuẩn: bán hàng thành công -> phát hành hóa đơn -> gửi CQT.
- Lỗi ở bước nào phải hiển thị đúng bước đó, có log để đối soát và gửi lại.
- Bám sát plugin hiện có `tgs-viettel-invoice` và tài liệu `thue1.text`, `thue3.text`.

## 2) Những gì đã có sẵn (không làm lại)
- Plugin `tgs-viettel-invoice` đã có:
  - Cấu hình theo từng cửa hàng.
  - Màn hình kiểm thử thủ công: nháp / phát hành / hủy.
  - Khung gọi API và log cơ bản.
- Bảng dùng chung `wp_global_milk_under24m` đã có để kiểm tra SKU sữa dưới 24 tháng.

## 3) DB-first trong tgs_shop_management (đã làm)
Chỉ cập nhật tại `class-tgs-database.php`, không tạo file script rời.

### 3.1) Phiên bản schema
- `DB_VERSION`: `1.1.3`.

### 3.2) Bảng `local_viettel_invoice` (mở rộng)
Thêm cột để theo dõi đầy đủ 2 bước phát hành và gửi CQT:
- Trạng thái theo bước:
  - `issue_status` (0: chưa gửi, 1: thành công, 2: thất bại)
  - `send_cqt_status` (0: chưa gửi, 1: thành công, 2: thất bại)
- Đánh dấu đơn có sản phẩm chính dưới 24 tháng:
  - `contains_under24_main_item`
  - `under24_main_sku_list_json`
- Dữ liệu trung gian cho luồng 3 hàm:
  - `smart_source_payload`
  - `smart_filtered_payload`
- Kết quả bước phát hành:
  - `issue_transaction_uuid`
  - `issue_request_payload`, `issue_response_payload`
  - `issue_http_code`, `issue_error_message`
  - `issue_sent_at`
- Kết quả bước gửi CQT:
  - `cqt_request_payload`, `cqt_response_payload`
  - `cqt_http_code`, `cqt_error_message`
  - `cqt_sent_at`
- Theo dõi gửi lại:
  - `resend_count`
  - `last_retry_at`
- Index bổ sung để lọc nhanh:
  - `local_ledger_code`, `issue_status`, `send_cqt_status`, `contains_under24_main_item`, `issue_transaction_uuid`

### 3.3) Bảng `local_viettel_invoice_log` (mở rộng)
Thêm cột để truy vết theo đơn và theo bước:
- `sale_ledger_id`
- `local_ledger_code`
- `step_name` (prepare / issue / send_cqt / ...)
- `transaction_uuid`
- Thêm index tương ứng cho các cột trên.

### 3.4) Đồng bộ `drop_tables`
Đã thêm:
- `TGS_TABLE_LOCAL_VIETTEL_INVOICE_LOG`
- `TGS_TABLE_LOCAL_VIETTEL_INVOICE`

## 4) Luồng kỹ thuật mục tiêu (theo THUE3)
Triển khai theo 3 hàm rõ trách nhiệm để dễ bảo trì.

### Hàm 1: `build_smart_payload_from_sale(local_ledger_id)`
Đầu vào tối giản:
- Mã đơn / ID đơn.
- Thông tin khách hàng.
- Danh sách dòng hàng từ `wp_local_ledger_item`.

Đầu ra:
- Một payload trung gian dễ đọc, dễ can thiệp nghiệp vụ.

### Hàm 2: `filter_and_sort_items_for_tax(payload)`
Luật nghiệp vụ:
- Xác định hàng chính bằng `local_ledger_item_gift_type = 0` hoặc `NULL`.
- Kiểm tra SKU sữa dưới 24 tháng bằng cách:
  - Lấy SKU sản phẩm local (`local_product_sku`).
  - Đối chiếu với `wp_global_milk_under24m.global_product_sku`.
  - Chỉ lấy bản ghi `is_deleted = 0` hoặc `NULL`.
- Nếu hàng chính là sữa dưới 24 tháng:
  - Loại 100% hàng tặng đi kèm hàng đó.
- Sắp xếp item gửi thuế:
  - Nhóm 1: hàng chính không dưới 24 tháng (ở trên).
  - Nhóm 2: hàng tặng hợp lệ đi sau hàng chính tương ứng.
  - Nhóm 3: hàng chính dưới 24 tháng (đẩy xuống cuối).

### Hàm 3: `send_issue_then_cqt(filtered_payload)`
- Gọi API phát hành hóa đơn.
- Nếu thành công, lấy `transactionUuid`.
- Gọi API gửi CQT bằng `transactionUuid`.
- Cập nhật trạng thái và log theo từng bước.
- Trả lỗi rõ ràng để POS hiển thị đúng cho nhân viên.

## 5) Hợp đồng API nội bộ (cho POS gọi)
Mục tiêu: POS truyền dữ liệu đơn giản, không truyền thẳng full payload Viettel ngay từ đầu.

Đề xuất tham số đầu vào:
- `customer_name`
- `customer_company_name` (tạm có thể trùng tên khách)
- `customer_tax_code` (có thể để trống)
- `customer_address`
- `sale_code` (`local_ledger_code`)
- `items[]` gồm:
  - `item_name`
  - `item_sku`
  - `unit_name`
  - `quantity`
  - `unit_price_after_discount`
  - `is_gift`

Khi map sang payload Viettel, cố định:
- `templateCode = 1/1156`
- `invoiceSeries = C25TZN`

## 6) Ma trận lỗi hiển thị trên POS
- `prepare_error`: lỗi đọc đơn hoặc item.
- `validate_error`: lỗi dữ liệu đầu vào.
- `issue_error`: lỗi phát hành hóa đơn.
- `cqt_error`: lỗi gửi CQT.
- `done`: thành công cả 2 bước.

Mỗi trạng thái cần hiển thị:
- Mã đơn.
- Thời gian.
- Thông báo lỗi ngắn gọn.
- Nút gửi lại (làm ở phase sau, schema đã chuẩn bị).

## 7) Sidebar POS (phase sau)
Bộ lọc cần có:
- Chưa gửi.
- Gửi thành công.
- Gửi thất bại.

Dựa trên cột:
- `issue_status`
- `send_cqt_status`
- `invoice_state`

## 8) Lộ trình triển khai cho team
### Phase A - Hoàn tất schema
- Đã xong.

### Phase B - Dịch vụ nghiệp vụ
- Viết service 3 hàm (build -> filter -> send).
- Gắn vào luồng bán hàng thành công (`tgs_sale_completed` hoặc hook POS phù hợp).

### Phase C - Hiển thị và thao tác lại
- Hiển thị lỗi rõ trên giao diện POS.
- Thêm thao tác gửi lại hóa đơn lỗi.

### Phase D - Đối soát cho kế toán
- Sidebar lọc trạng thái.
- Màn hình đối soát cuối ngày.

## 9) Quy ước code để dễ bảo trì
- Mỗi bước nghiệp vụ là một method riêng, không viết một hàm quá dài.
- Mỗi lần gọi API phải có một bản ghi log trong `local_viettel_invoice_log`.
- Không hardcode thông tin công ty trong service nghiệp vụ, luôn lấy từ cấu hình plugin.
- Không bỏ qua bước kiểm tra SKU sữa dưới 24 tháng trước khi gửi.

## 10) Lưu ý về ngôn ngữ hiển thị
- Tài liệu và giao diện quản trị cần dùng tiếng Việt có dấu khi hiển thị cho người dùng.
- Tránh các cụm không dấu kiểu "Cau hinh", "Gui", "That bai" vì gây khó đọc khi vận hành.

## 11) Hỏi đáp nhanh
- Vì sao cần `smart_source_payload` và `smart_filtered_payload`?
  - Để đối soát rõ dữ liệu trước lọc và sau lọc.
- Vì sao tách `issue_status` và `send_cqt_status`?
  - Để xác định đúng bước lỗi, dễ hỗ trợ và gửi lại.
- Có cần script tạo bảng riêng không?
  - Không. Chỉ cần cập nhật tại `class-tgs-database.php` và kích hoạt lại plugin.

## 12) Ghi chú vận hành
- Sau khi pull code: hủy kích hoạt và kích hoạt lại plugin `tgs_shop_management` để áp schema mới.
- Nên kiểm thử trên 1 shop staging trước khi bật rộng.
