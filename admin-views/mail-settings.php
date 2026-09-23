<?php
/**
 * Trang "Cài đặt gửi mail" — cấu hình gửi email hóa đơn cho CHÍNH website hiện tại.
 *
 * - Bật/tắt gửi email cho website này (mặc định TẮT — chưa triển khai thật thì không gửi khách).
 * - Chọn kênh gửi: SMTP (tgs_email_report) hoặc API Viettel (sendHtmlMailProcess).
 * Toàn bộ lưu theo từng website. Muốn bật site nào phải vào đúng site đó.
 *
 * @package tgs-viettel-invoice
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TGS_Viettel_Mail_Config')) {
    require_once TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-mail-config.php';
}
if (!class_exists('TGS_Viettel_Portal_Mailer')) {
    require_once TGS_VIETTEL_INVOICE_PLUGIN_DIR . 'includes/class-tgs-viettel-portal-mailer.php';
}

$tgs_mail_notice = '';
$tgs_mail_can_edit = current_user_can('manage_options') || current_user_can('manage_tgs_shop');

// ── Xử lý lưu ────────────────────────────────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['tgs_mail_cfg_submit'])
) {
    if (!$tgs_mail_can_edit) {
        $tgs_mail_notice = '<div class="notice notice-error"><p>Bạn không có quyền thay đổi cấu hình này.</p></div>';
    } elseif (!isset($_POST['tgs_mail_cfg_nonce']) || !wp_verify_nonce($_POST['tgs_mail_cfg_nonce'], 'tgs_mail_cfg_save')) {
        $tgs_mail_notice = '<div class="notice notice-error"><p>Phiên làm việc hết hạn, vui lòng thử lại.</p></div>';
    } else {
        $enabled = !empty($_POST['tgs_mail_enabled']);
        $mode    = (isset($_POST['tgs_mail_mode']) && $_POST['tgs_mail_mode'] === 'viettel') ? 'viettel' : 'smtp';
        TGS_Viettel_Mail_Config::save($enabled, $mode);
        $tgs_mail_notice = '<div class="notice notice-success"><p><strong>Đã lưu.</strong> Cấu hình gửi email cho website này đã cập nhật.</p></div>';
    }
}

$tgs_mail_cfg   = TGS_Viettel_Mail_Config::get();
$tgs_portal_cfg = TGS_Viettel_Portal_Mailer::get_config();
$tgs_site_name = get_bloginfo('name');
$tgs_site_id   = get_current_blog_id();
?>
<div class="wrap" style="max-width:820px;">
    <h1 style="display:flex;align-items:center;gap:10px;">
        <i class="bx bx-envelope" style="color:#2563eb;"></i> Cài đặt gửi mail hóa đơn
    </h1>
    <p style="color:#64748b;margin-top:2px;">
        Cấu hình áp dụng RIÊNG cho website:
        <strong><?php echo esc_html($tgs_site_name); ?></strong>
        <span style="color:#94a3b8;">(site #<?php echo (int) $tgs_site_id; ?>)</span>.
        Muốn bật/tắt website khác thì đăng nhập vào chính website đó.
    </p>

    <?php echo $tgs_mail_notice; // đã escape ở trên ?>

    <style>
        /* Điều khiển tự vẽ — KHÔNG dùng checkbox/radio mặc định (theme WP hay reset, khó thấy). */
        #tgsMailForm .tgsw{position:relative;display:inline-block;width:56px;height:30px;flex:0 0 auto;}
        #tgsMailForm .tgsw input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:2;}
        #tgsMailForm .tgsw .track{position:absolute;inset:0;background:#cbd5e1;border-radius:999px;transition:.15s;}
        #tgsMailForm .tgsw .knob{position:absolute;top:3px;left:3px;width:24px;height:24px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.35);transition:.15s;}
        #tgsMailForm .tgsw input:checked ~ .track{background:#16a34a;}
        #tgsMailForm .tgsw input:checked ~ .knob{left:29px;}
        #tgsMailForm .tgsw-state .on{display:none;color:#16a34a;font-weight:700;}
        #tgsMailForm .tgsw-state .off{display:inline;color:#94a3b8;font-weight:700;}
        #tgsMailForm .tgsw-box:has(input:checked) .tgsw-state .on{display:inline;}
        #tgsMailForm .tgsw-box:has(input:checked) .tgsw-state .off{display:none;}

        #tgsMailForm .tgs-mode{position:relative;display:block;border:2px solid #e2e8f0;border-radius:10px;padding:13px 15px 13px 46px;margin:10px 0;cursor:pointer;transition:.12s;}
        #tgsMailForm .tgs-mode:hover{border-color:#cbd5e1;background:#f8fafc;}
        #tgsMailForm .tgs-mode input{position:absolute;opacity:0;}
        #tgsMailForm .tgs-mode .dot{position:absolute;left:15px;top:15px;width:20px;height:20px;border:2px solid #94a3b8;border-radius:50%;background:#fff;box-sizing:border-box;}
        #tgsMailForm .tgs-mode:has(input:checked){border-color:#2563eb;background:#eff6ff;box-shadow:inset 0 0 0 1px #2563eb;}
        #tgsMailForm .tgs-mode:has(input:checked) .dot{border-color:#2563eb;background:#2563eb;}
        #tgsMailForm .tgs-mode:has(input:checked) .dot::after{content:"";position:absolute;left:5px;top:5px;width:6px;height:6px;border-radius:50%;background:#fff;}
    </style>

    <form method="post" id="tgsMailForm" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:22px 24px;margin-top:14px;">
        <?php wp_nonce_field('tgs_mail_cfg_save', 'tgs_mail_cfg_nonce'); ?>

        <h2 style="margin-top:0;font-size:16px;color:#0f172a;">1. Trạng thái gửi email</h2>
        <label class="tgsw-box" style="display:flex;align-items:center;gap:14px;cursor:pointer;padding:6px 0;">
            <span class="tgsw">
                <input type="checkbox" name="tgs_mail_enabled" value="1" <?php checked($tgs_mail_cfg['enabled']); ?>>
                <span class="track"></span><span class="knob"></span>
            </span>
            <span>
                <strong>Bật gửi email hóa đơn cho website này</strong>
                <span class="tgsw-state" style="margin-left:8px;font-size:13px;">
                    <span class="on">● ĐANG BẬT</span><span class="off">○ ĐANG TẮT</span>
                </span><br>
                <span style="color:#64748b;font-size:13px;">
                    Khi TẮT: mọi thao tác "Gửi email" (bước gửi thuế ở POS và trang Kiểm tra gửi thuế)
                    sẽ bị chặn, KHÔNG gửi cho khách. Dùng khi website chưa triển khai thật.
                </span>
            </span>
        </label>

        <hr style="border:none;border-top:1px solid #eef2f7;margin:18px 0;">

        <h2 style="margin-top:0;font-size:16px;color:#0f172a;">2. Kênh gửi email</h2>
        <label class="tgs-mode">
            <input type="radio" name="tgs_mail_mode" value="smtp" <?php checked($tgs_mail_cfg['mode'], 'smtp'); ?>>
            <span class="dot"></span>
            <strong>SMTP (cấu hình email của hệ thống)</strong><br>
            <span style="color:#64748b;font-size:13px;">
                Gửi kèm PDF hóa đơn qua SMTP đã cấu hình ở plugin gửi email (Gmail/Brevo/…). Mặc định, như hiện tại.
            </span>
        </label>
        <label class="tgs-mode">
            <input type="radio" name="tgs_mail_mode" value="viettel" <?php checked($tgs_mail_cfg['mode'], 'viettel'); ?>>
            <span class="dot"></span>
            <strong>API Viettel (Viettel tự gửi mail)</strong><br>
            <span style="color:#64748b;font-size:13px;">
                Gọi API <code>sendHtmlMailProcess</code> của Viettel — Viettel gửi email hóa đơn HTML
                tới địa chỉ email ĐÃ ghi trên hóa đơn. Dùng khi đã tích hợp Viettel thật.
            </span>
        </label>

        <hr style="border:none;border-top:1px solid #eef2f7;margin:18px 0;">

        <h2 style="margin-top:0;font-size:16px;color:#0f172a;">3. Gửi email đa địa chỉ (portal Viettel)</h2>
        <p style="color:#64748b;font-size:13px;margin:2px 0 10px;">
            Nút <strong>“Gửi email”</strong> ở màn <em>Kiểm tra gửi thuế</em> gửi hóa đơn tới <strong>bất kỳ email
            nào</strong> qua portal Viettel (đúng định dạng hóa đơn chính thức). Cấu hình này nay đặt <strong>theo CỤM</strong>
            (nhiều shop chung 1 tài khoản portal) — vào <strong>Cấu hình cụm Viettel</strong> → mục
            <em>“Gửi email đa địa chỉ (portal Viettel)”</em>.
        </p>
        <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;background:#f8fafc;font-size:13px;">
            Trạng thái cho website này:
            <?php if (!empty($tgs_portal_cfg['username'])) : ?>
                <strong style="color:<?php echo $tgs_portal_cfg['enabled'] ? '#16a34a' : '#b45309'; ?>;">
                    <?php echo $tgs_portal_cfg['enabled'] ? '● ĐANG BẬT' : '○ ĐANG TẮT'; ?>
                </strong>
                · tài khoản <code><?php echo esc_html($tgs_portal_cfg['username']); ?></code>
                <?php echo !empty($tgs_portal_cfg['supplier_id']) ? ' · supplierId <code>' . esc_html($tgs_portal_cfg['supplier_id']) . '</code>' : ' · <span style="color:#dc2626;">chưa có supplierId</span>'; ?>
            <?php else : ?>
                <strong style="color:#94a3b8;">Chưa cấu hình</strong> — khai ở Cấu hình cụm Viettel.
            <?php endif; ?>
        </div>

        <div style="margin-top:22px;">
            <button type="submit" name="tgs_mail_cfg_submit" value="1"
                    class="button button-primary" style="padding:6px 22px;font-weight:600;"
                    <?php disabled(!$tgs_mail_can_edit); ?>>
                Lưu cấu hình
            </button>
        </div>
    </form>

    <p style="color:#94a3b8;font-size:12.5px;margin-top:14px;">
        Ghi chú: chế độ API Viettel gửi tới email trên hóa đơn (thiết lập lúc phát hành). Muốn đổi người nhận
        thì sửa email trên hóa đơn trước khi phát hành, hoặc dùng chế độ SMTP.
    </p>
</div>
