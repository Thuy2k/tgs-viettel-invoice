<?php
/**
 * Cấu hình GỬI EMAIL HÓA ĐƠN — theo TỪNG WEBSITE (per-site).
 *
 * Vì sao per-site: nhiều shop chưa triển khai thật, CHƯA muốn gửi email cho khách.
 * Phải vào từng website bật riêng. Mỗi site còn chọn KÊNH gửi:
 *   - 'smtp'    : gửi qua SMTP đã cấu hình (plugin tgs_email_report) — wp_mail (mặc định, như cũ).
 *   - 'viettel' : nhờ API Viettel tự gửi mail HTML (sendHtmlMailProcess) tới email trên hóa đơn.
 *
 * Lưu bằng get_option/update_option trên CHÍNH blog hiện tại (mỗi site 1 bản ghi độc lập).
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Viettel_Mail_Config
{
    const OPTION = 'tgs_viettel_mail_config';

    /** Trả cấu hình chuẩn hóa của SITE HIỆN TẠI: ['enabled'=>bool, 'mode'=>'smtp'|'viettel']. */
    public static function get()
    {
        $o = get_option(self::OPTION, []);
        if (!is_array($o)) {
            $o = [];
        }
        $mode = isset($o['mode']) && $o['mode'] === 'viettel' ? 'viettel' : 'smtp';
        return [
            'enabled' => !empty($o['enabled']),
            'mode'    => $mode,
        ];
    }

    /** Site hiện tại đã BẬT gửi email chưa. Mặc định TẮT. */
    public static function is_enabled()
    {
        return self::get()['enabled'];
    }

    /** Kênh gửi của site hiện tại: 'smtp' | 'viettel'. */
    public static function mode()
    {
        return self::get()['mode'];
    }

    /** Lưu cấu hình cho site hiện tại. */
    public static function save($enabled, $mode)
    {
        update_option(self::OPTION, [
            'enabled' => (bool) $enabled,
            'mode'    => ($mode === 'viettel') ? 'viettel' : 'smtp',
        ], false);
    }
}
