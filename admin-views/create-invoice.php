<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('tgs_viettel_invoice')) {
    echo '<div class="alert alert-danger">Plugin TGS Viettel Invoice chua duoc khoi tao.</div>';
    return;
}

$view_data = tgs_viettel_invoice()->get_create_view_data();
$sample_json = $view_data['sample_json'];
$recent_invoices = $view_data['recent_invoices'];
?>

<div class="container-fluid py-3" id="tgs-viettel-create-root">
    <div class="alert alert-primary">
        <h5 class="mb-2">Tao hoa don Viettel</h5>
        <div class="mb-1">Man hinh nay cho phep gui truc tiep JSON len Viettel theo 2 che do: nhap va phat hanh.</div>
        <div class="mb-0">Luu y: gia tri dong hang nen la gia sau khuyen mai. Hang tang co don gia = 0.</div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <ul class="nav nav-tabs" id="tgs-viettel-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-draft" data-bs-toggle="tab" data-bs-target="#pane-draft" type="button" role="tab">Tao nhap</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-issue" data-bs-toggle="tab" data-bs-target="#pane-issue" type="button" role="tab">Tao phat hanh</button>
                </li>
            </ul>

            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="pane-draft" role="tabpanel">
                    <label class="form-label fw-semibold">Payload JSON (nhap)</label>
                    <textarea class="form-control font-monospace" id="tgs-viettel-payload-draft" rows="18"><?php echo esc_textarea($sample_json); ?></textarea>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-primary tgs-viettel-send-btn" data-mode="draft">Gui tao nhap</button>
                        <button type="button" class="btn btn-outline-secondary tgs-viettel-fill-sample" data-target="#tgs-viettel-payload-draft">Nap lai JSON mau</button>
                    </div>
                </div>

                <div class="tab-pane fade" id="pane-issue" role="tabpanel">
                    <label class="form-label fw-semibold">Payload JSON (phat hanh)</label>
                    <textarea class="form-control font-monospace" id="tgs-viettel-payload-issue" rows="18"><?php echo esc_textarea($sample_json); ?></textarea>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-danger tgs-viettel-send-btn" data-mode="issue">Gui phat hanh</button>
                        <button type="button" class="btn btn-outline-secondary tgs-viettel-fill-sample" data-target="#tgs-viettel-payload-issue">Nap lai JSON mau</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <strong>Ket qua goi API</strong>
        </div>
        <div class="card-body">
            <div id="tgs-viettel-response-status" class="small text-muted mb-2">Chua gui request.</div>
            <pre id="tgs-viettel-response-box" class="bg-light border rounded p-3 mb-0" style="max-height:400px; overflow:auto;">{}</pre>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Lich su gui gan day</strong>
            <span class="badge bg-secondary">20 ban ghi moi nhat</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Thoi gian</th>
                        <th>Mode</th>
                        <th>Trang thai</th>
                        <th>Hoa don Viettel</th>
                        <th>Tong sau thue</th>
                        <th>HTTP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_invoices)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Chua co du lieu.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_invoices as $row): ?>
                            <tr>
                                <td><?php echo intval($row['local_viettel_invoice_id']); ?></td>
                                <td><?php echo esc_html($row['created_at']); ?></td>
                                <td><?php echo esc_html($row['request_mode']); ?></td>
                                <td><?php echo esc_html($row['invoice_state']); ?></td>
                                <td><?php echo esc_html($row['viettel_invoice_no'] ?: $row['viettel_invoice_id']); ?></td>
                                <td><?php echo esc_html(number_format((float) $row['total_after_tax'], 0, ',', '.')); ?></td>
                                <td><?php echo esc_html((string) $row['http_code']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
