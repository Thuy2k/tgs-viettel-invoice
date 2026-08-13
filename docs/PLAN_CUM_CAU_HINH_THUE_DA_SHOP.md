# Kế hoạch: Cụm cấu hình thuế cho hệ thống nhiều shop

## 1. Mục tiêu

Xây dựng một tầng cấu hình mới tên **Cụm phát hành hóa đơn** (gọi tắt là **cụm thuế**) cho hệ thống WordPress Multisite có hơn 400 shop.

Admin tổng có thể:

- tạo một cụm thuế;
- chọn nhiều shop và đưa vào cùng cụm;
- cấu hình một lần tài khoản Viettel Invoice, chủ thể bán hàng, MST, mẫu số/ký hiệu và chế độ tự động cho cả cụm;
- phân công kế toán chỉ được xem/quản lý các cụm được giao;
- theo dõi shop chưa được gán cụm, shop bị trùng hoặc cấu hình chưa đủ điều kiện phát hành.

Mục tiêu quan trọng nhất là không để shop A phát hành hóa đơn bằng MST, tài khoản hoặc ký hiệu của chi nhánh B.

## 2. Kết luận nghiệp vụ thuế

### 2.1. Không nên lấy “chi nhánh” trong sơ đồ tổ chức làm điều kiện gộp duy nhất

Đơn vị gộp trong phần mềm phải là **cùng chủ thể phát hành hóa đơn/cùng hồ sơ đăng ký hóa đơn điện tử**, không chỉ là cùng tên chi nhánh hay cùng khu vực.

Một cụm chỉ nên chứa các shop thỏa đồng thời các điều kiện đã được kế toán xác nhận:

1. Cùng người bán thể hiện trên hóa đơn.
2. Cùng MST dùng để phát hành hóa đơn (MST doanh nghiệp hoặc MST đơn vị phụ thuộc đã đăng ký).
3. Cùng tài khoản/chứng thư hoặc cơ chế xác thực Viettel Invoice.
4. Dùng mẫu số, ký hiệu hóa đơn và hình thức hóa đơn tương thích với đăng ký tại cơ quan thuế/Viettel.
5. Hạch toán và quản lý hóa đơn tập trung theo cùng chủ thể đó.

Nói cách khác, các shop đều gọi tới nền tảng Viettel nhưng “nơi nhận” về nghiệp vụ được xác định bởi tài khoản phát hành, MST, mẫu số/ký hiệu và hồ sơ đăng ký, không phải chỉ bởi URL API.

| Tình huống | Cách xếp cụm đề xuất |
| --- | --- |
| Nhiều cửa hàng chỉ là địa điểm bán hàng, cùng pháp nhân, cùng MST và cùng tài khoản phát hành | Có thể chung một cụm |
| Chi nhánh có MST đơn vị phụ thuộc, tự đăng ký/sử dụng hóa đơn hoặc kê khai riêng | Tách thành cụm riêng |
| Hai nhóm dùng tài khoản Viettel hoặc chứng thư khác nhau | Tách cụm |
| Cùng MST nhưng mẫu số/ký hiệu được quản lý riêng và không được phép dùng chéo | Tách cụm hoặc tạo hồ sơ phát hành riêng |
| Khác MST người bán | Bắt buộc tách cụm |
| Một shop có thể bán cho nhiều pháp nhân | Ngoài phạm vi bản đầu; cần mô hình chọn hồ sơ phát hành theo giao dịch |

### 2.2. Kết luận cho câu hỏi “cùng chi nhánh gửi chung hay mỗi shop một nơi?”

- Nếu các shop là địa điểm bán hàng thuộc cùng một chủ thể phát hành và dùng chung hồ sơ Viettel Invoice thì có thể truyền chung qua một cấu hình cụm.
- Nếu mỗi chi nhánh/đơn vị phụ thuộc có MST hoặc hồ sơ phát hành riêng thì phải truyền bằng cấu hình riêng của đơn vị đó.
- Dù dùng chung cụm, hóa đơn và log vẫn phải lưu `blog_id`, tên/địa chỉ shop phát sinh để đối soát doanh thu từng cửa hàng.
- Không tự động quyết định chỉ từ sơ đồ tổ chức. Trước khi bật cụm, kế toán phải duyệt checklist ở mục 14.

### 2.3. Cơ sở tham khảo

- [Nghị định 123/2020/NĐ-CP về hóa đơn, chứng từ](https://vanban.chinhphu.vn/?docid=201365&lang=vi&pageid=27160).
- [Nghị định 70/2025/NĐ-CP sửa đổi Nghị định 123, hiệu lực từ 01/06/2025](https://vanban.chinhphu.vn/?docid=213179&lang=vi&pageid=27160).
- [Thông tư 32/2025/TT-BTC hướng dẫn về hóa đơn, chứng từ](https://vanban.chinhphu.vn/?docid=213855&pageid=27160).
- [Thông tư 86/2024/TT-BTC về đăng ký thuế](https://vanban.chinhphu.vn/?docid=212208&pageid=27160).
- [Ví dụ trả lời của cơ quan thuế về chi nhánh dùng MST đơn vị phụ thuộc trên hóa đơn](https://cantho.gdt.gov.vn/wps/wcm/connect/CanTho/site/faq/e91c38da-1c5b-4d35-bc72-dd82e49d5aa8?presentationTemplate=Lib%2Fpt_faq_detail_print).

Đây là định hướng thiết kế phần mềm, không thay thế ý kiến nghiệp vụ của kế toán/đơn vị tư vấn thuế. Danh sách cụm thực tế phải được kế toán xác nhận theo hồ sơ đăng ký hóa đơn điện tử đang có hiệu lực.

## 3. Hiện trạng code và khoảng trống

Plugin hiện chỉ có hai tầng:

```text
Giá trị mặc định
  -> Cấu hình chung toàn network (site_option)
    -> Thông tin riêng của shop (blog option)
```

Trong `tgs-viettel-invoice.php`:

- `OPTION_COMMON_SETTINGS`: cấu hình chung toàn mạng.
- `OPTION_SETTINGS`: cấu hình riêng tại từng blog/shop.
- `get_settings()` đang merge `default -> common -> shop`.
- Trường chung hiện gồm API, tài khoản/mật khẩu/token, SSL, mẫu số, ký hiệu, phương thức thanh toán, chế độ tự động.
- Trường riêng shop hiện gồm tên công ty, MST người bán, địa chỉ, điện thoại.

Các vấn đề:

1. Một tài khoản/mẫu số/ký hiệu đang áp dụng toàn network, không phù hợp khi các chi nhánh phát hành khác nhau.
2. MST lại nằm ở shop, nên có thể tạo tổ hợp sai: MST của shop A nhưng tài khoản/ký hiệu chung của chi nhánh B.
3. Role của WordPress chỉ giải quyết quyền thao tác, không mô tả shop nào thuộc chủ thể thuế nào.
4. Không có ràng buộc một shop chỉ thuộc một cụm đang hoạt động.
5. Không có lịch sử thay đổi cụm/cấu hình để đối soát hóa đơn đã phát hành.
6. Nhiều điểm gọi `get_settings()` trực tiếp; nếu chỉ sửa trang setting thì các luồng gửi lại, hủy hoặc tự động có thể vẫn lấy sai cấu hình.

## 4. Mô hình cấu hình mới

```text
Cấu hình kỹ thuật mặc định toàn network
  -> Cụm phát hành hóa đơn
    -> Ghi đè trình bày được cho phép của shop
      -> Snapshot cấu hình trên lần phát hành
```

### 4.1. Cấu hình toàn network

Chỉ giữ các mặc định kỹ thuật không xác định chủ thể thuế, ví dụ timeout, số lần retry, log retention và API URL mặc định.

Không tiếp tục coi tài khoản Viettel, MST, mẫu số và ký hiệu là cấu hình dùng chung bắt buộc cho toàn bộ 400 shop.

### 4.2. Cấu hình thuộc cụm

- Mã cụm, tên cụm, trạng thái.
- Tên pháp lý người bán.
- MST người bán/đơn vị phụ thuộc.
- Địa chỉ, điện thoại pháp lý.
- API base URL, môi trường demo/thật, `verify_ssl`.
- Chế độ xác thực, username/password/token hoặc tham chiếu tới secret.
- Mẫu số, ký hiệu hóa đơn, phương thức thanh toán mặc định.
- Loại/hình thức hóa đơn, cấu hình hóa đơn từ máy tính tiền nếu áp dụng.
- Cơ chế tự động xuất/gửi CQT.
- Chiến lược ký hiệu: dùng chung trong cụm hay quy định riêng theo địa điểm đã đăng ký.
- Phiên bản cấu hình và thời gian hiệu lực.

### 4.3. Cấu hình riêng shop được phép ghi đè

Chỉ nên cho ghi đè các thông tin phục vụ hiển thị/đối soát như tên cửa hàng, địa chỉ điểm bán, điện thoại, mã địa điểm nội bộ.

Không cho shop tự ghi đè MST, tài khoản Viettel, mẫu số hoặc ký hiệu nếu không tách shop sang cụm khác. Quy tắc này giữ được tính toàn vẹn của cụm.

## 5. Thiết kế dữ liệu

Với hơn 400 shop, cần bảng network riêng thay vì lưu toàn bộ mapping trong một `site_option` JSON. Bảng riêng hỗ trợ unique constraint, tìm kiếm, audit và cập nhật đồng thời tốt hơn.

Dùng `$wpdb->base_prefix` để dữ liệu có phạm vi toàn multisite network.

### 5.1. Bảng cụm

`{$base_prefix}tgs_viettel_invoice_clusters`

| Cột chính | Ý nghĩa |
| --- | --- |
| `id` | Khóa chính |
| `code`, `name` | Mã/tên cụm; `code` unique |
| `status` | `draft`, `active`, `inactive` |
| `supplier_tax_code` | MST chủ thể phát hành |
| `legal_name`, `legal_address`, `legal_phone` | Thông tin người bán |
| `settings_json` | Cấu hình không nhạy cảm, có schema version |
| `secret_payload` hoặc `secret_ref` | Credential đã mã hóa/tham chiếu kho secret |
| `config_version` | Optimistic lock và snapshot |
| `effective_from`, `effective_to` | Thời gian hiệu lực |
| `created_by`, `updated_by`, timestamps | Truy vết |

Không trả password/token đầy đủ về trình duyệt. Nếu chưa có dịch vụ quản lý secret, cần lớp mã hóa dùng khóa ngoài database; không coi base64 là mã hóa.

### 5.2. Bảng thành viên shop

`{$base_prefix}tgs_viettel_invoice_cluster_shops`

| Cột chính | Ý nghĩa |
| --- | --- |
| `cluster_id`, `blog_id` | Quan hệ cụm - shop |
| `status` | `active`, `inactive` |
| `effective_from`, `effective_to` | Lịch sử hiệu lực |
| `assigned_by`, timestamps | Audit |

Ràng buộc nghiệp vụ bản đầu: một `blog_id` chỉ có tối đa một membership `active`. Nếu cần lưu lịch sử ngay trong bảng, dùng transaction/service validation để bảo đảm không có hai khoảng hiệu lực chồng nhau.

### 5.3. Bảng phân công người dùng

`{$base_prefix}tgs_viettel_invoice_cluster_users`

- `cluster_id`, `user_id`.
- `access_level`: `viewer`, `accountant`, `manager`.
- người tạo và timestamps.

Role/capability quyết định người dùng **được làm loại thao tác nào**; bảng này quyết định người dùng **được làm trên cụm nào**. Không suy diễn membership chỉ từ tên role trong User Role Editor.

### 5.4. Audit log

`{$base_prefix}tgs_viettel_invoice_cluster_audit`

Lưu người thao tác, action, cluster/shop liên quan, dữ liệu trước/sau đã che secret, IP, thời điểm và request ID. Các action tối thiểu: tạo/sửa/kích hoạt cụm, đổi shop, đổi MST/ký hiệu, test kết nối và đổi phân công kế toán.

## 6. Bộ giải quyết cấu hình tập trung

Tạo service, ví dụ `TGS_Viettel_Invoice_Settings_Resolver`:

```php
$resolved = $resolver->for_blog($blog_id, $invoice_context);
```

Kết quả cần chứa:

- `cluster_id`, `cluster_code`, `config_version`;
- cấu hình đã resolve;
- nguồn của từng trường (`network`, `cluster`, `shop`);
- lỗi validation và trạng thái có được phép phát hành hay không.

Thứ tự resolve:

```text
plugin defaults
  -> network technical defaults
    -> active cluster profile
      -> allowed shop presentation overrides
```

Quy tắc:

- Shop chưa có cụm: chặn phát hành với lỗi rõ ràng, không âm thầm dùng tài khoản chung cũ.
- Cụm draft/inactive hoặc thiếu MST/credential/mẫu số/ký hiệu: chặn trước khi gọi API.
- Cache mapping `blog_id -> cluster_id`, nhưng phải xóa cache ngay khi đổi membership/cấu hình.
- Không `switch_to_blog()` hàng loạt trong request chỉ để tìm cấu hình.
- Tất cả luồng issue, draft, gửi CQT, gửi lại, hủy, xem/tải hóa đơn và tự động phải đi qua resolver; không còn gọi `get_settings()` trực tiếp tại các luồng nghiệp vụ.

## 7. Snapshot và tính đúng của hóa đơn lịch sử

Khi tạo lần phát hành đầu tiên, lưu cùng bản ghi tracking/log:

- `source_blog_id`;
- `cluster_id`, `cluster_code`, `config_version`;
- MST, mẫu số, ký hiệu và endpoint đã dùng;
- fingerprint của credential (không lưu secret rõ);
- snapshot thông tin người bán và địa điểm bán.

Khi gửi lại CQT hoặc xử lý hóa đơn đã issue, ưu tiên snapshot của lần phát hành thay vì cấu hình cụm hiện tại. Việc một shop được chuyển cụm sau này không được làm hóa đơn cũ chuyển sang MST mới.

## 8. Thiết kế giao diện admin

Đặt tại trang hiện có:

`/wp-admin/admin.php?page=tgs-shop-management&view=viettel-invoice-settings`

### 8.1. Danh sách cụm

Mỗi dòng hiển thị:

- tên/mã cụm;
- MST người bán;
- môi trường Viettel;
- mẫu số/ký hiệu;
- số shop;
- trạng thái cấu hình;
- lần kiểm tra kết nối gần nhất;
- cảnh báo xung đột.

Có bộ lọc theo trạng thái, MST, khu vực và ô tìm kiếm. Có khối riêng “Shop chưa được gán cụm”.

### 8.2. Form tạo/sửa cụm

Chia thành các phần:

1. Thông tin cụm và chủ thể phát hành.
2. Kết nối Viettel Invoice.
3. Mẫu số/ký hiệu và chế độ phát hành.
4. Chọn shop.
5. Phân công kế toán.
6. Kiểm tra và kích hoạt.

### 8.3. Bộ chọn shop tham khảo `spe-scope-container`

Tham khảo component `TgsScopeSelector` trong `tgs_selling_policy/assets/js/scope-selector.js` và cách gắn vào `id="spe-scope-container"`:

- tìm shop theo tên/domain/mã;
- chọn thủ công nhiều shop;
- chọn theo cây tổ chức nếu plugin hierarchy đang hoạt động;
- hiển thị số lượng và danh sách đã chọn;
- tìm kiếm AJAX có debounce, phân trang/giới hạn kết quả.

Không phụ thuộc runtime của plugin hóa đơn vào việc `tgs_selling_policy` có bật hay không. Có thể tách component thành thư viện dùng chung hoặc tạo bản invoice-local dựa trên cùng UX/API contract.

Khác biệt bắt buộc so với Selling Policy:

- Trong Selling Policy, `blogIds = []` có thể mang nghĩa “tất cả shop”. Với cụm thuế, mảng rỗng phải mang nghĩa **chưa chọn shop**.
- Không cung cấp “Tất cả shop” mặc định vì thao tác này có thể gán nhầm MST cho toàn hệ thống.
- Shop đã thuộc cụm khác phải hiện tên cụm hiện tại và không được âm thầm chọn.
- Muốn chuyển shop phải dùng action “Chuyển cụm”, hiển thị cấu hình cũ/mới, số hóa đơn đang chờ và yêu cầu xác nhận.
- Có preview shop nào sẽ thêm, giữ nguyên, xóa hoặc xung đột trước khi lưu.

## 9. Phân quyền

Đăng ký capability riêng để User Role Editor có thể cấp cho role:

- `manage_tgs_invoice_clusters`: tạo/sửa/phân shop; mặc định chỉ Super Admin.
- `manage_tgs_invoice_cluster_settings`: sửa cấu hình cụm được phân công.
- `view_tgs_invoice_cluster_reports`: xem hóa đơn/báo cáo cụm được phân công.
- `manage_tgs_invoice_shop_overrides`: sửa thông tin trình bày của shop.
- `view_tgs_invoice_audit`: xem audit log.

Quy tắc truy cập:

- Super Admin thấy và quản lý toàn bộ network.
- Kế toán phải có capability phù hợp **và** có record trong `cluster_users`.
- Quản trị shop không mặc nhiên được xem credential cụm.
- Credential luôn được che; để thay credential dùng form nhập mới, không đọc ngược secret.
- Mọi AJAX/REST endpoint phải kiểm tra nonce, capability và quyền trên đúng `cluster_id`.

## 10. API/AJAX dự kiến

- `list_clusters`, `get_cluster`, `save_cluster`, `deactivate_cluster`.
- `get_scope_data`, `search_scope_shops` dựa trên contract của Selling Policy nhưng namespace riêng.
- `validate_cluster_membership`, `assign_shops`, `move_shops`.
- `get_unassigned_shops`.
- `preview_resolved_settings(blog_id)`; che secret.
- `test_cluster_connection`; chỉ dùng lời gọi không phát hành hóa đơn.
- `assign_cluster_users`.
- `get_cluster_audit`.

Không hard-delete cụm đã từng phát hành hóa đơn; chỉ cho `inactive`.

## 11. Validation và bảo vệ dữ liệu

Trước khi kích hoạt cụm:

- MST đúng định dạng và đã được kế toán xác nhận.
- Credential, API URL, mẫu số và ký hiệu đầy đủ.
- Không có shop thuộc hai cụm active.
- Không có shop bị xóa/archive khỏi multisite.
- Cảnh báo khi cùng MST nhưng khác credential/ký hiệu; đây có thể hợp lệ nhưng phải xác nhận.
- Cảnh báo khi nhiều cụm dùng cùng ký hiệu mà chiến lược đánh số chưa được Viettel/kế toán xác nhận.
- Test kết nối không được tạo hóa đơn thật.
- Dùng database transaction cho thao tác chuyển nhiều shop.
- Dùng `config_version`/optimistic locking để hai admin không ghi đè thay đổi của nhau.
- Action chuyển cụm bị chặn nếu shop có hóa đơn đang ở trạng thái issue thành công nhưng chờ gửi CQT, trừ khi có quy trình xử lý rõ ràng.

## 12. Migration từ cấu hình hiện tại

Không tự động gộp chỉ dựa trên tên công ty hoặc tên shop.

### Giai đoạn migration

1. Quét toàn network ở chế độ read-only:
   - `tgs_viettel_invoice_common_settings`;
   - `tgs_viettel_invoice_settings` của từng blog;
   - trạng thái blog và các hóa đơn đang chờ.
2. Tạo báo cáo các tổ hợp hiện có theo MST + credential fingerprint + mẫu số + ký hiệu.
3. Đề xuất cụm nháp; kế toán phải xem và duyệt từng cụm.
4. Liệt kê shop thiếu MST, cấu hình không đầy đủ hoặc xung đột.
5. Tạo bảng và membership, chưa đổi runtime.
6. Chạy “resolve preview” so sánh cấu hình cũ và mới cho toàn bộ shop.
7. Bật feature flag theo một vài cụm pilot.
8. Đối soát hóa đơn thật/demo và rollback nếu cần.
9. Mở rộng theo đợt; chỉ ngừng fallback legacy sau khi không còn shop chưa gán.

Trong thời gian chuyển đổi, fallback legacy chỉ được bật có thời hạn và phải log cảnh báo. Khi rollout hoàn tất, shop không có cụm phải bị chặn thay vì lấy cấu hình toàn network.

## 13. Lộ trình triển khai

### Phase 0 - Chốt nghiệp vụ và dữ liệu

- Kế toán lập danh sách chủ thể phát hành, MST, tài khoản Viettel, mẫu số/ký hiệu và các shop trực thuộc.
- Viettel/kế toán xác nhận chiến lược ký hiệu và đánh số khi nhiều shop dùng chung chủ thể.
- Chốt một shop chỉ có một cụm active trong bản đầu.

### Phase 1 - Hạ tầng

- Migration tạo 4 bảng network.
- Repository/service cho cụm, membership, user access và audit.
- Settings Resolver và validation.
- Cache/invalidation.
- Unit test cho precedence và ràng buộc membership.

### Phase 2 - Admin UI

- Danh sách và form cụm.
- Bộ chọn shop theo UX `spe-scope-container`.
- Shop chưa gán, conflict preview và bulk move.
- Phân công kế toán/capability.
- Test kết nối và audit view.

### Phase 3 - Tích hợp toàn bộ luồng hóa đơn

- Thay mọi `get_settings()` trong luồng nghiệp vụ bằng resolver có `blog_id` rõ ràng.
- Thêm snapshot vào tracking/log.
- Bảo đảm issue, send CQT, retry, cancel và auto flow dùng đúng cụm/snapshot.
- Thêm cluster filter cho màn hình kế toán và báo cáo.

### Phase 4 - Migration và pilot

- Dry-run 400+ shop.
- Pilot 1 cụm demo, sau đó 1 cụm production ít shop.
- Đối soát số lượng hóa đơn, MST, ký hiệu, trạng thái CQT và báo cáo doanh thu.
- Rollout theo đợt, có feature flag và rollback runbook.

### Phase 5 - Hoàn thiện

- Tắt fallback cấu hình legacy.
- Dọn UI/import-export cũ.
- Export/import theo cụm nhưng không export secret rõ.
- Dashboard sức khỏe cấu hình và cảnh báo shop chưa gán.

## 14. Checklist kế toán phải xác nhận cho từng cụm

- Tên pháp lý và MST nào phải xuất hiện trên hóa đơn?
- Chi nhánh có MST đơn vị phụ thuộc hay dùng MST trụ sở chính?
- Chi nhánh kê khai thuế riêng hay hạch toán/phát hành tập trung?
- Tài khoản Viettel/chứng thư nào đã đăng ký cho MST này?
- Mẫu số, ký hiệu, loại hóa đơn và hình thức có mã/không mã/máy tính tiền nào được phép dùng?
- Các shop có được dùng chung ký hiệu/dải số không?
- Địa chỉ trên hóa đơn là địa chỉ pháp lý hay địa chỉ cửa hàng; Viettel đang cấu hình thế nào?
- Ai là kế toán chịu trách nhiệm và ai được quyền chuyển shop?
- Ngày bắt đầu hiệu lực của cụm?
- Có hóa đơn đang chờ gửi CQT, điều chỉnh, thay thế hoặc hủy trước khi chuyển không?

## 15. Kiểm thử bắt buộc

### Unit/integration

- Resolve đúng cấu hình cho hai shop cùng cụm và hai shop khác cụm.
- Shop chưa gán hoặc cụm inactive bị chặn.
- Không tạo được hai membership active cho một shop.
- Shop override không thay được MST/credential/ký hiệu.
- Kế toán cụm A không đọc/sửa cụm B dù có URL/AJAX payload thủ công.
- Chuyển cụm không làm thay đổi snapshot hóa đơn cũ.
- Retry/send CQT dùng snapshot đúng MST đã issue.
- Cache bị invalidated sau khi sửa/chuyển cụm.
- Hai admin lưu đồng thời nhận conflict theo `config_version`.

### E2E/UAT

- Tạo cụm, tìm/chọn hàng trăm shop, lưu và tải lại đúng.
- Thử conflict khi chọn shop đã thuộc cụm.
- Issue demo từ hai shop cùng cụm và hai cụm khác nhau.
- Đối chiếu payload: `supplierTaxCode`, template, series, seller info, source shop.
- Test issue thành công nhưng send CQT lỗi, sau đó chuyển shop; retry vẫn đi đúng cấu hình cũ.
- Kiểm tra màn hình kế toán chỉ thấy cụm được giao.
- Kiểm tra import/export không rò password/token.

## 16. Tiêu chí nghiệm thu

- 100% shop phát hành hóa đơn được gán đúng một cụm active.
- Không còn luồng nghiệp vụ lấy trực tiếp cấu hình chung/shop mà bỏ qua resolver.
- Không thể phát hành khi cụm thiếu hoặc không hợp lệ.
- Không thể gán một shop vào hai cụm active.
- Mọi hóa đơn/log xác định được shop nguồn, cụm, MST và phiên bản cấu hình đã dùng.
- Super Admin quản lý được nhiều shop bằng tìm kiếm/cây tổ chức và có preview xung đột.
- Kế toán chỉ truy cập các cụm được phân công.
- Migration có dry-run, báo cáo chênh lệch, feature flag và rollback.
- Kế toán ký duyệt mapping shop - cụm và kết quả pilot trước rollout toàn network.

## 17. Các vùng code dự kiến thay đổi

- `tgs-viettel-invoice.php`: bỏ việc các luồng nghiệp vụ tự merge/get settings; gọi resolver.
- `includes/`: thêm schema installer, repositories, resolver, access policy, audit và migration service.
- `admin-views/`: thêm danh sách/form cụm, shop conflict/unassigned và phân công kế toán.
- `assets/js`, `assets/css`: thêm scope selector độc lập cho invoice hoặc thư viện dùng chung.
- Tracking/log schema: thêm `cluster_id`, `config_version` và snapshot/fingerprint.
- Import/export: chuyển sang định dạng cluster-aware, che secret.
- `user-role-editor`: không cần sửa plugin; chỉ dùng nó để gán các capability do Viettel Invoice đăng ký.

## 18. Các quyết định cần chốt trước khi code

1. Danh sách pháp nhân/MST/đơn vị phụ thuộc thực tế và mapping 400+ shop.
2. Một shop có bao giờ cần phát hành cho nhiều pháp nhân không?
3. Có cho phép nhiều cụm cùng MST nhưng khác ký hiệu/tài khoản không?
4. Địa chỉ người bán trên hóa đơn lấy từ cụm hay từ shop trong từng trường hợp?
5. Tổ chức hierarchy nào là nguồn dữ liệu chuẩn cho Công ty/Khu vực/Chi nhánh/Tỉnh?
6. Credential sẽ được mã hóa bằng khóa nào và khóa được quản lý ở đâu?
7. Chọn thời gian lưu audit và snapshot theo yêu cầu đối soát.

Chỉ bắt đầu Phase 3 sau khi các câu 1-4 được kế toán và người phụ trách Viettel Invoice xác nhận bằng văn bản hoặc dữ liệu duyệt trong hệ thống.
