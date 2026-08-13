<?php
if (!defined('ABSPATH')) {
    exit;
}

$settings = TGS_Viettel_Invoice_Plugin::get_settings();
$is_super_admin = is_super_admin();
$is_multisite = is_multisite();
?>

<div class="container-fluid py-3" id="tgs-viettel-settings-root">

    <?php if ($is_multisite): ?>
    <div class="alert alert-info">
        <h5 class="mb-2">Cấu hình đa site (Multisite)</h5>
        <div class="mb-0">
            <?php if ($is_super_admin): ?>
            Phần <strong>"Cấu hình chung"</strong> được lưu chung cho toàn mạng. Phần <strong>"Cấu hình cửa hàng"</strong> riêng cho site này.
            <?php else: ?>
            Cấu hình trong trang này được lưu riêng cho site hiện tại. Liên hệ Super Admin để thay đổi cấu hình chung.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$is_multisite || $is_super_admin): ?>
    <!-- COMMON SETTINGS (network-wide) -->
    <div class="card mb-3 border-primary">
        <div class="card-header bg-primary text-white"><strong>Cấu hình chung</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label class="form-label">API base URL</label>
                <input type="text" class="form-control" id="vi_api_base_url" value="<?php echo esc_attr($settings['api_base_url']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phương thức xác thực</label>
                <select class="form-select" id="vi_auth_mode">
                    <option value="basic" <?php selected($settings['auth_mode'], 'basic'); ?>>Basic Auth</option>
                    <option value="token" <?php selected($settings['auth_mode'], 'token'); ?>>Bearer Token</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tên đăng nhập</label>
                <input type="text" class="form-control" id="vi_username" value="<?php echo esc_attr($settings['username']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Mật khẩu</label>
                <input type="text" class="form-control" id="vi_password" value="<?php echo esc_attr(!empty($settings['password']) ? '********' : ''); ?>" placeholder="Nhập mới hoặc để ******** để giữ nguyên">
            </div>
            <div class="col-md-12">
                <label class="form-label">Access token</label>
                <input type="text" class="form-control" id="vi_access_token" value="<?php echo esc_attr(!empty($settings['access_token']) ? '********' : ''); ?>" placeholder="Dùng khi auth_mode = token">
            </div>
            <div class="col-md-12">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="vi_verify_ssl" <?php checked((int) $settings['verify_ssl'], 1); ?>>
                    <label class="form-check-label" for="vi_verify_ssl">Bật SSL verify</label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Template code mặc định</label>
                <input type="text" class="form-control" id="vi_default_template_code" value="<?php echo esc_attr($settings['default_template_code']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Invoice series mặc định</label>
                <input type="text" class="form-control" id="vi_default_invoice_series" value="<?php echo esc_attr($settings['default_invoice_series']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phương thức thanh toán mặc định</label>
                <input type="text" class="form-control" id="vi_default_payment_method" value="<?php echo esc_attr($settings['default_payment_method']); ?>">
            </div>
            <div class="col-md-6">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="vi_auto_enabled" <?php checked((int) $settings['auto_enabled'], 1); ?>>
                    <label class="form-check-label" for="vi_auto_enabled">Tự động tạo hóa đơn khi sale completed</label>
                </div>
                <select class="form-select mt-2" id="vi_auto_mode">
                    <option value="draft" <?php selected($settings['auto_mode'], 'draft'); ?>>Tự động tạo nháp</option>
                    <option value="issue" <?php selected($settings['auto_mode'], 'issue'); ?>>Tự động phát hành</option>
                </select>
                <div class="form-check mt-3 p-3 border border-danger rounded bg-light">
                    <input class="form-check-input" type="checkbox" id="vi_auto_issue_confirmed" <?php checked((int) $settings['auto_issue_confirmed'], 1); ?>>
                    <label class="form-check-label text-danger" for="vi_auto_issue_confirmed">
                        Tôi xác nhận tự động phát hành là thao tác không thể hoàn tác và chỉ bật sau khi đã kiểm thử end-to-end.
                    </label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="vi_log_retention_days">Thời gian lưu log hóa đơn (ngày)</label>
                <input type="number" min="0" max="3650" class="form-control" id="vi_log_retention_days" value="<?php echo esc_attr((int) $settings['log_retention_days']); ?>">
                <div class="form-text">Đặt 0 để không tự xóa. Khi lớn hơn 0, WP-Cron sẽ xóa log cũ hơn số ngày này.</div>
            </div>
            <div class="col-12">
                <button type="button" class="btn btn-primary" id="vi_save_common_settings">Lưu cấu hình chung</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Xuất / Nhập cấu hình JSON</strong></div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-success" id="vi_export_settings">Xuất JSON</button>
            </div>
            <div class="row g-2 align-items-center mb-3">
                <div class="col-auto">
                    <label class="form-label mb-0">Phạm vi xuất:</label>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="vi_export_scope">
                        <?php if ($is_super_admin): ?>
                        <option value="all">Tất cả (common + shop)</option>
                        <option value="common">Chỉ common</option>
                        <?php endif; ?>
                        <option value="shop" <?php echo $is_super_admin ? '' : 'selected'; ?>>Chỉ cửa hàng này</option>
                    </select>
                </div>
            </div>
            <label class="form-label">Nhập JSON</label>
            <textarea class="form-control font-monospace" id="vi_import_json" rows="8" placeholder="Dán JSON đã xuất vào đây"></textarea>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-outline-primary" id="vi_import_settings">Nhập vào cửa hàng hiện tại</button>
                <?php if ($is_super_admin): ?>
                <button type="button" class="btn btn-outline-danger" id="vi_import_common_settings">Nhập vào common</button>
                <?php endif; ?>
            </div>
            <div class="small text-muted mt-2">Tự động nhận dạng định dạng JSON (v1 flat, v2 common/shop).</div>
        </div>
    </div>

    <div id="vi_settings_feedback" class="alert d-none"></div>
</div>
