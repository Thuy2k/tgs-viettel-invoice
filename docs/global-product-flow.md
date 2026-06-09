# TGS Viettel Invoice - luồng sản phẩm global

Tài liệu này ghi lại cách plugin `tgs-viettel-invoice` lấy thông tin sản phẩm sau khi hệ thống chuyển sang catalog global.

## Nguyên tắc

- Không query hoặc join bảng `wp_local_product_name` / `{blog_prefix}_local_product_name`.
- Dòng bán hàng vẫn lấy từ bảng local theo shop: `local_ledger` và `local_ledger_item`.
- Catalog sản phẩm lấy từ `wp_global_product_name` thông qua `TGS_Global_Product_Source`.
- Khóa sản phẩm trên `local_ledger_item.local_product_name_id` được hiểu là alias của `global_product_name_id`.
- SKU ưu tiên lấy từ `local_ledger_item.local_product_sku`; nếu thiếu thì resolve bằng `global_product_name_id` qua global product source.
- Các key `local_product_*` trong payload invoice chỉ là alias từ global để giữ tương thích UI/POS cũ.

Doc API gốc nằm ở:

```text
wp-content/plugins/tgs_shop_management/docs/global-product-api.md
```

## Helper trung tâm

File:

```text
includes/class-tgs-viettel-invoice-global-products.php
```

Class:

```php
TGS_Viettel_Invoice_Global_Products
```

Hàm chính:

- `query_products($args)`: gọi `TGS_Global_Product_Source::query_products()`.
- `get_products_by_ids($ids, $blog_id)`: lấy sản phẩm global theo ID.
- `get_products_by_skus($skus, $blog_id)`: lấy sản phẩm global theo SKU.
- `enrich_ledger_items($rows, $blog_id)`: nhận dòng `local_ledger_item`, gắn thêm `global_product_*` và alias `local_product_*`.
- `find_under24_skus($skus)`: đối chiếu SKU với `wp_global_milk_under24m`.

## Luồng build payload hóa đơn

`TGS_Viettel_Invoice_Flow_Service::build_smart_payload_from_sale($sale_ledger_id)`:

1. Đọc phiếu bán từ `local_ledger`.
2. Đọc các dòng item từ `local_ledger_item`.
3. Không join bảng sản phẩm local.
4. Gọi `TGS_Viettel_Invoice_Global_Products::enrich_ledger_items()`.
5. Tạo payload trung gian với:
   - `product_id`: global product ID.
   - `sku`: global SKU.
   - `item_name`: global product name.
   - `unit_name`: global product unit.

Giá/khuyến mại vẫn lấy từ ledger item vì đó là giá tại thời điểm bán hàng.

## Luồng preview POS

`ajax_pos_get_items_for_review()`:

1. Lấy item ID từ phiếu bán.
2. Đọc ledger item và các cột khuyến mại/danger flag.
3. Enrich catalog từ global product source.
4. Trả về `main_items`, `gift_items`, `all_items` cho POS review.

Nếu UI cũ vẫn đọc `local_product_name`, `local_product_sku`, `local_product_unit`, các key này đã được helper gắn từ global.

## Kiểm tra sữa dưới 24 tháng

Danh sách sữa dưới 24 tháng dùng bảng global:

```text
wp_global_milk_under24m.global_product_sku
```

Tất cả nơi cần check under-24m gọi:

```php
TGS_Viettel_Invoice_Global_Products::find_under24_skus($skus);
```

Không tự join bảng sản phẩm local để tìm SKU.

## Danh sách trạng thái hóa đơn

Khi tính cờ `contains_under24_main_item` cho các sale row chưa có invoice record:

1. Đọc item ID từ `local_ledger_item_id` JSON.
2. Đọc ledger item.
3. Enrich bằng global product source.
4. Lấy SKU đã resolve để đối chiếu `wp_global_milk_under24m`.

## Quy ước khi phát triển tiếp

- Nếu cần tên, SKU, đơn vị, barcode, giá niêm yết sản phẩm: gọi helper global, không query local product.
- Nếu cần giá thực bán, khuyến mại, số lượng bán: lấy từ `local_ledger_item`.
- Nếu cần tồn kho: dùng API/source global trong `tgs_shop_management`, không tính riêng trong invoice.
- Nếu thêm màn hình tìm sản phẩm: dùng endpoint `/wp-json/tgs-shop/v1/products` hoặc `TGS_Global_Product_Source`.
- Nếu thấy `local_product_name_id` trong invoice/POS payload: đọc như global product ID alias.

## Checklist review code

- Không có `TGS_TABLE_LOCAL_PRODUCT_NAME` trong PHP invoice.
- Không có `JOIN local_product_name`.
- Các dòng item hóa đơn được enrich qua `TGS_Viettel_Invoice_Global_Products`.
- Under-24m check bằng SKU global.
- Comment/field `local_product_*` nếu còn tồn tại phải được hiểu là alias từ global.
