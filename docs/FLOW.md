# TGS Viettel Invoice Flow

## 1) Muc tieu
- Tao hoa don dien tu len Viettel VInvoice tu he thong shop.
- He thong chi tao nhap/phat hanh, khong xu ly buoc gui CQT.
- Ke toan se soat va thao tac tren web Viettel.

## 2) Kien truc plugin
- Plugin: tgs-viettel-invoice
- Tich hop UI vao dashboard cua tgs_shop_management qua filter:
  - tgs_shop_dashboard_routes
- Tich hop menu vao khoi He thong qua action:
  - tgs_shop_system_menu

## 3) Du lieu luu tru
- Settings theo tung shop (site option local):
  - option key: tgs_viettel_invoice_settings
- Bang local (prefix theo blog):
  - local_viettel_invoice: luu ban ghi request/response va trang thai hoa don
  - local_viettel_invoice_log: luu audit log moi lan goi API

## 4) Man hinh chinh
- Viettel Invoice (create):
  - Tab Tao nhap
  - Tab Tao phat hanh
  - Textarea payload JSON
  - Khoi hien thi response
  - Bang lich su gui gan day
- Viettel Invoice Settings:
  - Cau hinh thong tin doanh nghiep
  - Cau hinh auth/basic token
  - Export JSON settings
  - Import JSON settings
- Huong dan luong:
  - Tom tat quy trinh cho dev/ke toan

## 5) Endpoint Viettel dang dung
- Tao nhap:
  - InvoiceAPI/InvoiceWS/createOrUpdateInvoiceDraft/{supplierTaxCode}
- Tao phat hanh:
  - InvoiceAPI/InvoiceWS/createInvoice/{supplierTaxCode}

## 6) Hook mo rong cho tu dong hoa
- Plugin co lang nghe hook:
  - tgs_sale_completed
- Neu bat auto trong settings:
  - plugin goi filter tgs_viettel_invoice_build_payload_from_sale
  - team co the map sale_data -> payload Viettel theo nghiep vu thuc te

## 7) Nguyen tac van hanh
- Payload dong hang gui len Viettel nen la gia da xu ly khuyen mai.
- Hang tang de unitPrice = 0.
- Tong trong summarizeInfo can khop:
  - totalAmountWithoutTax
  - totalTaxAmount
  - totalAmountWithTax
- Neu can sua hoa don, thao tac tren web Viettel theo quy trinh ke toan.
