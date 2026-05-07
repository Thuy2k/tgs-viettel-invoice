<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="container-fluid py-3">
    <div class="card mb-3">
        <div class="card-body">
            <h4 class="mb-3">Tong hop luong TGS Viettel Invoice</h4>
            <p class="mb-2">Muc tieu: tao hoa don dien tu len Viettel tu he thong shop, de ke toan soat va gui CQT tren web Viettel.</p>
            <p class="mb-0">File docs chi tiet nam trong plugin: docs/FLOW.md</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Buoc 1 - Cau hinh</div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Nhap MST, username/password hoac token theo shop.</li>
                        <li>Khai bao template code, invoice series mac dinh.</li>
                        <li>Neu can clone shop, dung export/import JSON.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Buoc 2 - Tao hoa don</div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Vao man Viettel Invoice, chon tab Nhap hoac Phat hanh.</li>
                        <li>Dan payload JSON vao textarea roi gui API.</li>
                        <li>He thong luu request/response vao bang local_viettel_invoice va local_viettel_invoice_log.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Buoc 3 - Van hanh</div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Ke toan vao web Viettel de soat hoa don, sua neu can.</li>
                        <li>Viec gui CQT do ke toan chu dong thao tac tren Viettel.</li>
                        <li>Phan sale API co san hook tgs_sale_completed de tu dong hoa ve sau.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
