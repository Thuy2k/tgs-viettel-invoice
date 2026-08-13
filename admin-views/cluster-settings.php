<?php
if (!defined('ABSPATH')) exit;
$cluster_manager = class_exists('TGS_Viettel_Invoice_Clusters') ? TGS_Viettel_Invoice_Clusters::instance() : null;
$can_manage_clusters = $cluster_manager && $cluster_manager->can_manage_all();
?>
<div class="card mb-3 border-success" id="vi-cluster-admin">
    <div class="card-header d-flex justify-content-between align-items-center bg-success text-white">
        <strong>Cụm phát hành hóa đơn</strong>
    </div>
    <div class="card-body">
        <div class="alert alert-light border mb-3">Cụm phải đại diện cho cùng một chủ thể phát hành: cùng MST, tài khoản
            Viettel và mẫu số/ký hiệu đã đăng ký. Shop chưa thuộc cụm vẫn dùng cấu hình cũ trong thời gian migration.
        </div>
        <div class="vi-cluster-onboarding mb-3">
            <div><span>1</span><strong>Tạo cụm</strong><small>Đặt tên cho chi nhánh/chủ thể thuế.</small></div>
            <div><span>2</span><strong>Nhập cấu hình</strong><small>MST, tài khoản Viettel, mẫu số và ký hiệu.</small></div>
            <div><span>3</span><strong>Chọn shop &amp; kích hoạt</strong><small>Tích các shop dùng chung rồi lưu cụm.</small></div>
        </div>
        <div class="vi-cluster-toolbar d-flex flex-wrap gap-2 align-items-center mb-3">
            <?php if ($can_manage_clusters): ?><button type="button" class="btn btn-primary fw-semibold" id="vi-cluster-create-main">+ Tạo cụm hóa đơn mới</button><?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="vi-cluster-refresh">Tải lại</button>
            <button type="button" class="btn btn-outline-warning btn-sm" id="vi-cluster-unassigned">Shop chưa
                gán</button>
            <span class="small text-muted" id="vi-cluster-summary"></span>
        </div>
        <div id="vi-cluster-feedback" class="alert d-none"></div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead>
                    <tr>
                        <th>Cụm</th>
                        <th>Mã cụm</th>
                        <th>Phiên bản</th>
                        <th>MST</th>
                        <th>Mẫu số / Ký hiệu</th>
                        <th>Shop</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="vi-cluster-list">
                    <tr>
                        <td colspan="8" class="text-muted">Đang tải...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="vi-cluster-backdrop d-none" id="vi-cluster-backdrop"></div>
<div class="vi-cluster-toast d-none" id="vi-cluster-toast" role="status" aria-live="polite"><span class="vi-cluster-toast-icon"></span><span class="vi-cluster-toast-text"></span><button type="button" class="vi-cluster-toast-close" aria-label="Đóng">&times;</button></div>

<div class="vi-cluster-editor d-none" id="vi-cluster-editor" role="dialog" aria-modal="true" aria-labelledby="vi-cluster-editor-title">
    <div class="card-header d-flex justify-content-between align-items-center"><strong id="vi-cluster-editor-title">Tạo
            cụm hóa đơn</strong><button type="button" class="btn-close" id="vi-cluster-close"
            aria-label="Đóng"></button></div>
    <div class="card-body">
        <input type="hidden" id="vic_id" value="0"><input type="hidden" id="vic_version" value="0">
        <div class="row g-3">
            <div class="col-12 vi-section-heading" id="vic-step-basic"><strong>Thông tin cụm</strong></div>
            <div class="col-md-3"><label class="form-label">Mã cụm *</label><input class="form-control" id="vic_code"
                    maxlength="80"></div>
            <div class="col-md-6"><label class="form-label">Tên cụm *</label><input class="form-control" id="vic_name"
                    maxlength="190"></div>
            <div class="col-md-3"><label class="form-label">Trạng thái</label><select class="form-select"
                    id="vic_status">
                    <option value="draft">Nháp</option>
                    <option value="active">Hoạt động</option>
                    <option value="inactive">Ngừng</option>
                </select></div>
            <div class="col-12 vi-section-heading" id="vic-step-issuer"><strong>Chủ thể phát hành</strong></div>
            <div class="col-md-4"><label class="form-label">MST người bán *</label><input class="form-control"
                    id="vic_tax_code"></div>
            <div class="col-md-8"><label class="form-label">Tên pháp lý *</label><input class="form-control"
                    id="vic_legal_name"></div>
            <div class="col-md-8"><label class="form-label">Địa chỉ pháp lý</label><input class="form-control"
                    id="vic_legal_address"></div>
            <div class="col-md-4"><label class="form-label">Điện thoại</label><input class="form-control"
                    id="vic_legal_phone"></div>
            <div class="col-12 vi-section-heading" id="vic-step-viettel"><strong>Kết nối Viettel Invoice</strong></div>
            <div class="col-md-8"><label class="form-label">API base URL *</label><input class="form-control"
                    id="vic_api_base_url"></div>
            <div class="col-md-4"><label class="form-label">Xác thực</label><select class="form-select"
                    id="vic_auth_mode">
                    <option value="basic">Basic Auth</option>
                    <option value="token">Bearer Token</option>
                </select></div>
            <div class="col-md-4"><label class="form-label">Username</label><input class="form-control"
                    id="vic_username" autocomplete="off"></div>
            <div class="col-md-4"><label class="form-label">Password</label><input type="password" class="form-control"
                    id="vic_password" autocomplete="new-password" placeholder="Để trống để giữ nguyên"></div>
            <div class="col-md-4"><label class="form-label">Access token</label><input type="password"
                    class="form-control" id="vic_access_token" autocomplete="new-password"
                    placeholder="Để trống để giữ nguyên"></div>
            <div class="col-12">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="vic_verify_ssl"
                        checked><label class="form-check-label" for="vic_verify_ssl">Bật SSL verify</label></div>
            </div>
            <div class="col-12 vi-section-heading" id="vic-step-invoice"><strong>Mặc định hóa đơn</strong></div>
            <div class="col-md-4"><label class="form-label">Template code *</label><input class="form-control"
                    id="vic_template_code"></div>
            <div class="col-md-4"><label class="form-label">Invoice series *</label><input class="form-control"
                    id="vic_invoice_series"></div>
            <div class="col-md-4"><label class="form-label">Phương thức thanh toán</label><input class="form-control"
                    id="vic_payment_method"></div>
            <div class="col-md-6">
                <div class="form-check mt-4"><input class="form-check-input" type="checkbox"
                        id="vic_auto_enabled"><label class="form-check-label" for="vic_auto_enabled">Tự động xử lý khi
                        sale completed</label></div>
            </div>
            <div class="col-md-6"><label class="form-label">Chế độ tự động</label><select class="form-select"
                    id="vic_auto_mode">
                    <option value="issue">Phát hành và gửi CQT</option>
                    <option value="draft">Tạo nháp</option>
                </select></div>
            <div class="col-12 vi-section-heading" id="vic-step-shops"><strong>Shop thuộc cụm</strong></div>
            <div class="col-12"><input class="form-control" id="vic_shop_search"
                    placeholder="Tìm theo tên, ID, domain...">
                <div class="small text-muted mt-1">Shop thuộc cụm khác sẽ có cảnh báo; khi lưu hệ thống yêu cầu xác nhận
                    chuyển cụm.</div>
                <div id="vic_shop_results" class="vi-shop-results mt-2"></div>
                <div id="vic_selected_shops" class="vi-selected-shops mt-2"></div>
            </div>
            <?php if ($can_manage_clusters): ?>
            <div class="col-12 vi-section-heading" id="vic-step-users"><strong>Phân công kế toán</strong></div>
            <div class="col-12"><label class="form-label">User ID và mức quyền</label><textarea
                    class="form-control font-monospace" id="vic_users" rows="3"
                    placeholder="Ví dụ: 12:accountant, 25:viewer"></textarea>
                <div class="small text-muted">Mức quyền: viewer, accountant, manager. User vẫn cần capability tương ứng
                    từ User Role Editor.</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="vi-cluster-action-bar">
        <div id="vi-cluster-editor-feedback" class="alert d-none mb-2" role="alert"></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-primary fw-semibold px-4" id="vi-cluster-save"><span class="vi-save-label">Lưu cụm</span><span class="vi-save-loading d-none">Đang lưu...</span></button>
            <button type="button" class="btn btn-outline-success d-none" id="vi-cluster-test">Kiểm tra kết nối</button>
            <?php if ($can_manage_clusters): ?><button type="button" class="btn btn-outline-danger d-none" id="vi-cluster-deactivate">Ngừng cụm</button><?php endif; ?>
            <button type="button" class="btn btn-outline-secondary ms-auto" id="vi-cluster-cancel">Đóng</button>
        </div>
    </div>
</div>
