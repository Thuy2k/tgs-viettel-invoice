<?php
if (!defined('ABSPATH')) {
    exit;
}

$settings = TGS_Viettel_Invoice_Plugin::get_settings();
?>

<div class="container-fluid py-3" id="tgs-viettel-settings-root">
    <div class="alert alert-info">
        <h5 class="mb-2">Cau hinh theo tung shop</h5>
        <div class="mb-0">Cau hinh trong trang nay duoc luu theo site hien tai (khong dung chung toan multisite).</div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Thong tin doanh nghiep</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">Ten cong ty</label>
                <input type="text" class="form-control" id="vi_company_name" value="<?php echo esc_attr($settings['company_name']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">MST (supplierTaxCode)</label>
                <input type="text" class="form-control" id="vi_supplier_tax_code" value="<?php echo esc_attr($settings['supplier_tax_code']); ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label">Dia chi</label>
                <input type="text" class="form-control" id="vi_company_address" value="<?php echo esc_attr($settings['company_address']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Dien thoai</label>
                <input type="text" class="form-control" id="vi_company_phone" value="<?php echo esc_attr($settings['company_phone']); ?>">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Ket noi API Viettel</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label class="form-label">API base URL</label>
                <input type="text" class="form-control" id="vi_api_base_url" value="<?php echo esc_attr($settings['api_base_url']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Auth mode</label>
                <select class="form-select" id="vi_auth_mode">
                    <option value="basic" <?php selected($settings['auth_mode'], 'basic'); ?>>Basic Auth</option>
                    <option value="token" <?php selected($settings['auth_mode'], 'token'); ?>>Bearer Token</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" id="vi_username" value="<?php echo esc_attr($settings['username']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Password</label>
                <input type="text" class="form-control" id="vi_password" value="<?php echo esc_attr(!empty($settings['password']) ? '********' : ''); ?>" placeholder="Nhap moi hoac de ******** de giu nguyen">
            </div>
            <div class="col-md-12">
                <label class="form-label">Access token</label>
                <input type="text" class="form-control" id="vi_access_token" value="<?php echo esc_attr(!empty($settings['access_token']) ? '********' : ''); ?>" placeholder="Dung khi auth_mode = token">
            </div>
            <div class="col-md-4">
                <label class="form-label">Template code mac dinh</label>
                <input type="text" class="form-control" id="vi_default_template_code" value="<?php echo esc_attr($settings['default_template_code']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Invoice series mac dinh</label>
                <input type="text" class="form-control" id="vi_default_invoice_series" value="<?php echo esc_attr($settings['default_invoice_series']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Payment method mac dinh</label>
                <input type="text" class="form-control" id="vi_default_payment_method" value="<?php echo esc_attr($settings['default_payment_method']); ?>">
            </div>
            <div class="col-md-6">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="vi_verify_ssl" <?php checked((int) $settings['verify_ssl'], 1); ?>>
                    <label class="form-check-label" for="vi_verify_ssl">Bat SSL verify</label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="vi_auto_enabled" <?php checked((int) $settings['auto_enabled'], 1); ?>>
                    <label class="form-check-label" for="vi_auto_enabled">Tu dong tao hoa don khi sale completed</label>
                </div>
                <select class="form-select mt-2" id="vi_auto_mode">
                    <option value="draft" <?php selected($settings['auto_mode'], 'draft'); ?>>Auto tao nhap</option>
                    <option value="issue" <?php selected($settings['auto_mode'], 'issue'); ?>>Auto phat hanh</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Export / Import cau hinh JSON</strong></div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-success" id="vi_export_settings">Export JSON</button>
                <button type="button" class="btn btn-primary" id="vi_save_settings">Luu cau hinh</button>
            </div>
            <label class="form-label">Import JSON</label>
            <textarea class="form-control font-monospace" id="vi_import_json" rows="10" placeholder="Paste JSON da export tu shop khac vao day"></textarea>
            <button type="button" class="btn btn-outline-primary mt-2" id="vi_import_settings">Import JSON vao shop hien tai</button>
            <div class="small text-muted mt-2">Neu import tu shop giong nhau, ban co the copy nhanh toan bo settings khong can cau hinh lai.</div>
        </div>
    </div>

    <div id="vi_settings_feedback" class="alert d-none"></div>
</div>
