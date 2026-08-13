# Các vấn đề cần giải quyết — TGS Viettel Invoice

Ngày rà soát: 2026-08-13  
Phạm vi: mã nguồn hiện tại của plugin, commit `341abab`, working tree và gói `tgs-viettel-invoice.zip`.

## Kết luận ngắn

Plugin hiện đã qua PHP lint ở working tree, nhưng chưa nên coi là sẵn sàng phát hành ổn định. Các việc cần ưu tiên nhất là đồng bộ lại source/commit, khôi phục kiểm tra quyền POS, bổ sung chống phát hành trùng và sửa luồng retry.

Không nên bật `auto_enabled` trên production trước khi hoàn thành các mục P0 và P1 bên dưới.

## P0 — Phải xử lý trước khi đóng gói/phát hành

### 1. Commit hiện tại chứa merge-conflict marker và gây parse error

- Commit `341abab` chứa `<<<<<<<`, `=======`, `>>>>>>>` trong `tgs-viettel-invoice.php`.
- Đây là nguyên nhân trực tiếp của lỗi `unexpected token "<<"` tại dòng 779.
- Working tree hiện đã bỏ marker và PHP lint thành công, nhưng thay đổi vẫn chưa được commit.
- File ZIP đang là file untracked, nên chưa có quy trình đảm bảo ZIP luôn được tạo từ đúng commit sạch.

Việc cần làm:

1. Review lại từng quyết định khi resolve conflict, đặc biệt là nhánh kiểm tra quyền.
2. Commit bản đã resolve.
3. Tạo ZIP mới từ commit đó, không đóng gói thủ công từ một working tree đang dirty.
4. CI hoặc script đóng gói phải chạy PHP lint và kiểm tra merge marker trước khi tạo ZIP.

Tiêu chí hoàn thành:

- `git grep -n -E '^(<<<<<<<|=======|>>>>>>>)'` không có kết quả.
- Toàn bộ file PHP lint thành công.
- `git status` sạch trước khi build ZIP.
- Có thể xác định chính xác ZIP được tạo từ commit nào.

### 2. Quyền của nhiều AJAX endpoint bị hạ từ quyền POS xuống chỉ cần đăng nhập

Sau khi resolve conflict, nhiều endpoint đang dùng `is_user_logged_in()` thay cho `current_user_can_use_pos()`. Một tài khoản WordPress đăng nhập nhưng không có quyền vào POS vẫn có thể thử đọc dữ liệu hoặc thực hiện thao tác hóa đơn nếu lấy được nonce.

Các endpoint bị ảnh hưởng gồm:

- Xem log và danh sách hóa đơn: `ajax_get_sale_debug_log()`, `ajax_pos_list_statuses()`.
- Phát hành/gửi lại hóa đơn: `ajax_send_from_sale()`, `ajax_pos_retry_invoice()`.
- Gửi email và xem PDF: `ajax_pos_send_invoice_email()`, `ajax_pos_preview_invoice_pdf()`.
- Tra cứu khách hàng theo mã số thuế: `ajax_lookup_customer_by_tax_code()`.
- `ajax_pos_debug_statuses_context()`, `ajax_pos_get_items_for_review()` và `ajax_pos_update_danger_flags()` hiện chỉ kiểm nonce, chưa kiểm quyền người dùng.

Rủi ro còn lớn hơn trên multisite: `bootstrap_requested_blog_context()` chấp nhận `blog_id` từ request rồi `switch_to_blog()` mà chưa kiểm tra người dùng có quyền tại site đích hay không.

Việc cần làm:

1. Tạo một guard dùng chung: xác thực nonce, đăng nhập, quyền POS và quyền truy cập đúng blog/site.
2. Gọi guard ở tất cả endpoint POS trước khi đọc hoặc thay đổi dữ liệu.
3. Xóa các hook `wp_ajax_nopriv_*` không cần thiết cho chức năng bắt buộc đăng nhập.
4. Endpoint debug chỉ cho quản trị viên hoặc tắt hoàn toàn ngoài môi trường debug.

Tiêu chí hoàn thành:

- Subscriber không có quyền POS nhận HTTP 403.
- Nhân viên site A không đọc/gửi hóa đơn của site B bằng cách đổi `blog_id`.
- Chỉ user có quyền phù hợp mới xem được log, thông tin khách hàng và file PDF/XML.

## P1 — Rủi ro phát hành sai hoặc phát hành trùng

### 3. Chưa có idempotency/locking trước khi phát hành hóa đơn

Cả nút gửi thủ công và hook `tgs_sale_completed` đều có thể chạy luồng issue. Trước khi gọi API Viettel, code tạo một tracking row mới nhưng không khóa theo `sale_ledger_id`, không kiểm tra đơn đã `issued/done`, và không có unique constraint để chặn hai request đồng thời.

Các tình huống có thể gây trùng:

- Hook tự động đang chạy, thu ngân đồng thời bấm nút gửi.
- Người dùng bấm hai lần hoặc trình duyệt retry request.
- Hai PHP worker cùng xử lý một đơn.
- Timeout xảy ra sau khi Viettel đã nhận hóa đơn nhưng local chưa kịp cập nhật trạng thái.

Việc cần làm:

1. Chọn một khóa idempotency ổn định, tối thiểu gồm `blog_id + sale_ledger_id + request_mode`.
2. Thêm trạng thái `processing` bằng thao tác DB nguyên tử hoặc lock có TTL.
3. Trước khi issue, kiểm tra bản ghi đã có `issue_status = 1`, transaction UUID hoặc invoice number.
4. Khi kết quả mạng không rõ ràng, tra cứu trạng thái Viettel trước khi issue lại.
5. Nút thủ công phải hiển thị trạng thái hiện tại và không issue lại đơn đã phát hành.

### 4. Retry có thể phát hành lại hóa đơn đã issue thành công

`ajax_pos_retry_invoice()` chỉ gửi lại CQT khi đồng thời có `issue_status = 1` và `issue_transaction_uuid` khác rỗng. Nếu Viettel đã issue thành công nhưng response không parse được UUID, code chuyển sang chạy lại full flow và có thể tạo hóa đơn thứ hai.

Ngoài ra, `extract_transaction_uuid()` đang coi cả `invoiceNo` là một ứng viên transaction UUID. Invoice number và transaction UUID là hai định danh khác nhau; dùng lẫn có thể gửi CQT với giá trị sai.

Việc cần làm:

1. Tách riêng hàm đọc `transactionUuid` và `invoiceNo`, không fallback chéo.
2. Nếu issue đã thành công nhưng thiếu UUID, chuyển sang trạng thái `issued_needs_reconcile`.
3. Thực hiện API tra cứu/đối soát trước khi cho phép issue lại.
4. Chỉ full retry khi đã xác nhận chắc chắn hóa đơn chưa được tạo phía Viettel.

### 5. Luồng tự động có thể làm mất danh sách sản phẩm cần loại trừ

Trong `run_auto_issue_cqt_flow()`, code dựng `$excluded_ids_map` từ `sale_data['excluded_item_ids']`, sau đó gán lại `is_under24_promo_danger` cho tất cả item. Hook `tgs_sale_completed` thường không có trường này, nên mảng rỗng sẽ biến toàn bộ cờ đã đọc từ DB thành `false`.

Kết quả có thể là dòng Z/quà đã đánh dấu loại trừ lại được đưa vào payload gửi CQT.

Việc cần làm:

- Chỉ override cờ khi request thực sự cung cấp `excluded_item_ids`.
- Luồng tự động phải giữ nguyên cờ từ snapshot của đơn hoặc áp dụng quy tắc server-side đáng tin cậy.
- Thêm test cho đơn có dòng Z, quà dưới 24 tháng và danh sách loại trừ rỗng/không được truyền.

### 6. Endpoint cập nhật cờ danger không kiểm tra item thuộc đơn được gửi lên

`ajax_pos_update_danger_flags()` nhận cả `sale_ledger_id` và danh sách `item_id`, nhưng câu lệnh update chỉ dựa vào `local_ledger_item_id`. Người gọi có thể gửi ID của item thuộc đơn khác.

Việc cần làm:

- Đọc danh sách item hợp lệ từ `sale_ledger_id` và chỉ update giao của hai tập ID.
- Kết hợp kiểm tra quyền POS và quyền site.
- Trả lỗi nếu request chứa item không thuộc đơn.

### 7. Biến `$disc_amount` chưa được gán trong payload review

Trong `ajax_pos_get_items_for_review()`, mảng `$item` sử dụng `'discount_amount' => $disc_amount`, nhưng sau khi resolve conflict không còn dòng gán `$disc_amount`.

Hậu quả:

- PHP có thể phát sinh warning `Undefined variable $disc_amount`.
- Dữ liệu preview chiết khấu có thể sai hoặc thành `null`.

Việc cần làm:

- Gán `$disc_amount = floatval($row['local_ledger_item_discount_amount'] ?? 0)` trước khi dựng item.
- Thêm test preview cho chiết khấu 0, chiết khấu theo dòng và hàng tặng 100%.

### 8. HTTP 2xx đang được coi là thành công dù response không hợp lệ

`submit_invoice_payload()` chủ yếu dựa vào HTTP status. Response 2xx nhưng JSON lỗi, thiếu trường bắt buộc hoặc chứa mã lỗi nghiệp vụ vẫn có thể được trả về với `success = true`.

Việc cần làm:

- Xác thực schema response riêng cho `issue`, `send_cqt`, `draft` và `cancel`.
- Chỉ đánh dấu issue thành công khi có dấu hiệu nghiệp vụ đáng tin cậy từ Viettel.
- Ghi riêng lỗi transport, lỗi HTTP, lỗi parse JSON và lỗi nghiệp vụ.

### 9. Nhánh full retry luôn trả thông báo thành công ra AJAX

`ajax_pos_retry_invoice()` gọi `run_auto_issue_cqt_flow()` rồi gửi `wp_send_json_success()`. Tuy nhiên `run_auto_issue_cqt_flow()` không trả kết quả; khi các bước bên trong thất bại, hàm chỉ ghi log và `return`.

Do đó giao diện có thể báo “đã gửi lại” trong khi issue hoặc gửi CQT thực tế thất bại.

Việc cần làm:

- Cho `run_auto_issue_cqt_flow()` trả về cấu trúc `success`, `step`, `message`, `tracking_id`.
- AJAX phản ánh đúng kết quả cuối cùng.
- Hook tự động vẫn có thể bỏ qua response nhưng phải ghi log đầy đủ.

### 10. Bật lại hook tự động là thay đổi hành vi nguy hiểm

Commit mới bật lại `add_action('tgs_sale_completed', ...handle_sale_completed...)`. Mặc định `auto_enabled = 0`, nhưng site đã lưu cấu hình `auto_enabled = 1` sẽ bắt đầu phát hành tự động ngay khi cập nhật plugin.

Việc cần làm:

- Có bước migration/feature flag rõ ràng thay vì tự động kế thừa giá trị cũ.
- Hiển thị cảnh báo xác nhận khi bật chế độ issue tự động.
- Chỉ bật production sau khi có idempotency và test end-to-end.

## P2 — Tính đúng dữ liệu và vận hành

### 11. Preview đang biến thuế thiếu thành 0%

Trong `ajax_pos_get_items_for_review()`, fallback SQL và `floatval()` biến thuế `NULL` thành `0`. Điều này làm UI không phân biệt được “chưa khai thuế” với thuế suất thật `0%`.

Flow service ở working tree đã được sửa để giữ `NULL` và chỉ kiểm tra sau khi loại dòng không gửi CQT. Preview cũng cần dùng cùng quy ước ba trạng thái: thiếu, 0%, và thuế dương.

### 12. Thay đổi flow thuế hiện vẫn chưa commit

Working tree của `includes/class-tgs-viettel-invoice-flow-service.php` đã thay đổi để:

- Không chặn thuế trước khi loại dòng Z/quà.
- Chỉ kiểm tra thiếu thuế trên các dòng thực sự gửi CQT.
- Chặn phòng thủ nếu caller gọi thẳng bước build payload.

Thay đổi này đã qua lint và smoke test nhưng cần được review, commit và đưa vào gói phát hành nếu muốn hỗ trợ đúng các đơn cũ có dòng loại trừ thiếu thuế.

### 13. Plugin phụ thuộc schema bên ngoài nhưng không có kiểm tra version/migration DB đầy đủ

`run_migration()` hiện chỉ di chuyển option cấu hình. Plugin sử dụng nhiều bảng/cột được định nghĩa từ plugin khác và có fallback khác nhau khi cột thiếu.

Việc cần làm:

- Khai báo phiên bản schema tối thiểu của plugin phụ thuộc.
- Health check khi kích hoạt: constants, bảng và các cột bắt buộc.
- Không âm thầm thay dữ liệu thiếu bằng 0 ở các trường tài chính.
- Tài liệu hóa plugin/version cần cài cùng nhau.

### 14. Email mặc định đang hard-code địa chỉ cá nhân

Khi `to_email` thiếu hoặc không hợp lệ, code gửi về `thuy.nguyenvan2000hn@gmail.com`. Điều này có thể làm lộ hóa đơn khách hàng cho sai người nhận.

Việc cần làm:

- Không có fallback email cá nhân.
- Nếu email trống/không hợp lệ thì dừng và yêu cầu nhập lại.
- Có thể dùng email cửa hàng đã cấu hình, nhưng phải hiển thị rõ cho người gửi xác nhận.

### 15. Dữ liệu nhạy cảm và file hóa đơn cần chính sách bảo vệ/lưu giữ

- Username/password/access token đang lưu trong WordPress options.
- Log lưu toàn bộ request/response, có thể chứa tên, địa chỉ, số điện thoại, email và mã số thuế.
- PDF/XML tạm được đặt dưới uploads trong tối đa hai ngày; thư mục uploads thường truy cập được qua web nếu biết URL.
- Chưa thấy chính sách retention cho bảng log.

Việc cần làm:

- Mã hóa secret ở trạng thái lưu hoặc lấy từ secret/environment phù hợp.
- Mask/redact PII không cần thiết trong log.
- Giới hạn quyền xem log và thời gian lưu log.
- Lưu attachment tạm ngoài public web root hoặc chặn truy cập trực tiếp.
- Dọn file bằng scheduled task, không chỉ khi có lần gửi email tiếp theo.

### 16. Thiếu test tự động cho luồng tài chính và trạng thái hóa đơn

Tối thiểu cần có test cho:

- Thuế thiếu, thuế 0%, 5%, 8%, 10%.
- Chiết khấu theo dòng, nhiều số lượng, làm tròn và hàng tặng 100%.
- Dòng Z/quà bị loại và user override.
- Issue thành công/CQT thất bại/retry CQT.
- Timeout sau issue và đối soát trước retry.
- Hai request đồng thời cho cùng một đơn.
- Phân quyền POS và cách ly multisite.
- Response HTTP 200 nhưng lỗi nghiệp vụ hoặc JSON không hợp lệ.

## P3 — Khả năng bảo trì

### 17. File chính quá lớn và chứa quá nhiều trách nhiệm

`tgs-viettel-invoice.php` hiện xử lý bootstrap, quyền, settings, AJAX, API client, email/PDF, persistence, log, UI và cả auto flow. Điều này làm resolve conflict khó và là một nguyên nhân dẫn đến commit marker conflict cùng lỗi biến chưa khai báo.

Nên tách dần thành:

- `Authorization/RequestGuard`
- `ViettelApiClient`
- `InvoiceRepository` và `InvoiceLogRepository`
- `InvoiceOrchestrator`
- `InvoiceEmailService`
- Các AJAX controller nhỏ theo chức năng

## Thứ tự xử lý đề xuất

1. Sửa và commit sạch merge conflict; bỏ ZIP thủ công khỏi source hoặc chuẩn hóa build ZIP.
2. Khôi phục guard quyền POS + kiểm quyền multisite cho toàn bộ AJAX.
3. Sửa `$disc_amount`, bảo toàn cờ loại trừ và ownership của item.
4. Thêm idempotency/locking trước mọi lệnh issue.
5. Tách transaction UUID khỏi invoice number và xây luồng reconcile/retry an toàn.
6. Chuẩn hóa kiểm tra response Viettel và kết quả trả về AJAX.
7. Đồng bộ quy ước thuế `NULL`/`0%` giữa preview và flow service.
8. Loại email hard-code, bảo vệ secret/PII/file tạm.
9. Thêm test tự động rồi mới bật auto issue trên production.

## Các thay đổi local đã xác nhận nhưng chưa phải bản phát hành hoàn chỉnh

- Đã bỏ merge marker trong file chính; toàn bộ PHP lint thành công.
- Đã dời kiểm tra thiếu thuế xuống sau bước loại các dòng không gửi CQT.
- Đã thêm lớp chặn thiếu thuế tại bước build payload cuối.

Các thay đổi trên không giải quyết những vấn đề quyền, idempotency, retry và tính toàn vẹn dữ liệu đã liệt kê trong tài liệu này.
