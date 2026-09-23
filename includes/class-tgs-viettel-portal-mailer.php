<?php
/**
 * GỬI EMAIL HÓA ĐƠN ĐA ĐỊA CHỈ qua PORTAL Viettel (vinvoice.viettel.vn).
 *
 * Vì sao có class này (khác với API đối tác đang dùng ở tgs-viettel-invoice.php):
 *   - API đối tác `sendHtmlMailProcess` CHỈ gửi tới email ĐÃ ghi trên hóa đơn
 *     (không đổi được người nhận).
 *   - Portal web (vinvoice) cho gửi tới NHIỀU email BẤT KỲ qua endpoint
 *     `/email/send-email-customer` body {id, buyerEmailAddress:"a@x;b@y"}.
 *
 * Nhưng portal dùng ĐĂNG NHẬP WEB (access_token client web_app), KHÁC Basic
 * username/password của API đối tác; và tham số `id` là ID NỘI BỘ của Viettel
 * (không phải invoiceNo/transactionUuid) — phải tra qua `invoice/search`.
 *
 * Luồng: login() -> resolve_id(invoiceNo) -> send_email(id, emails[]).
 * Token cache theo site (~access_token sống ~1198s). Cấu hình per-site (option
 * riêng, KHÔNG dính cấu hình phát hành): tài khoản portal (…_QT) + mật khẩu +
 * supplierId nội bộ. Mặc định TẮT.
 *
 * @package tgs-viettel-invoice
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Viettel_Portal_Mailer
{
    /** Option lưu cấu hình portal cho SITE hiện tại. */
    const OPTION = 'tgs_viettel_portal_config';

    /** Host portal web (chung mọi cluster). */
    const BASE = 'https://vinvoice.viettel.vn';

    /** Trần TTL cache token (giây) — access_token thực tế ~1198s, trừ hao. */
    const TOKEN_TTL = 1000;

    // ────────────────────────────────────────────────────────────────────────
    // Cấu hình per-site
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Cấu hình portal cho SITE hiện tại. Ưu tiên cấu hình theo CỤM (nhiều site
     * chung 1 tài khoản portal); nếu cụm chưa khai thì fallback option per-site cũ.
     * @return array{enabled:bool,username:string,supplier_id:string,password:string,_scope:string}
     */
    public static function get_config()
    {
        $cluster = self::cluster_portal();
        if ($cluster !== null && $cluster['username'] !== '') {
            return $cluster;
        }
        // Fallback: cấu hình per-site cũ (option) — cho site đã khai trước khi chuyển sang cụm.
        $o = get_option(self::OPTION, []);
        if (!is_array($o)) {
            $o = [];
        }
        return [
            'enabled'     => !empty($o['enabled']),
            'username'    => (string) ($o['username'] ?? ''),
            'supplier_id' => (string) ($o['supplier_id'] ?? ''),
            'password'    => self::decrypt((string) ($o['password_enc'] ?? '')),
            '_scope'      => 'b' . get_current_blog_id(),
        ];
    }

    /** Đọc cấu hình portal từ CỤM của site hiện tại (qua resolver clusters). null nếu chưa gán cụm/chưa khai. */
    private static function cluster_portal()
    {
        if (!class_exists('TGS_Viettel_Invoice_Clusters')) {
            return null;
        }
        $s = TGS_Viettel_Invoice_Clusters::instance()->resolve(get_current_blog_id(), []);
        if (!is_array($s) || empty($s['_cluster']['assigned'])) {
            return null;
        }
        $username = (string) ($s['portal_username'] ?? '');
        if ($username === '') {
            return null;
        }
        $cid = (int) ($s['_cluster']['id'] ?? 0);
        $ver = (int) ($s['_cluster']['config_version'] ?? 0);
        return [
            'enabled'     => !empty($s['portal_enabled']),
            'username'    => $username,
            'supplier_id' => (string) ($s['portal_supplier_id'] ?? ''),
            'password'    => (string) ($s['portal_password'] ?? ''),
            // Cache token theo CỤM + phiên bản cấu hình (đổi creds -> version tăng -> cache tự mới).
            '_scope'      => 'c' . $cid . 'v' . $ver,
        ];
    }

    public static function is_enabled()
    {
        return self::get_config()['enabled'];
    }

    /** Lưu cấu hình. Mật khẩu rỗng/'********' -> GIỮ mật khẩu cũ (không ghi đè). */
    public static function save_config($enabled, $username, $password, $supplier_id)
    {
        $existing = self::get_config();
        $pw = ($password === '' || $password === '********') ? $existing['password'] : (string) $password;
        update_option(self::OPTION, [
            'enabled'      => (bool) $enabled,
            'username'     => sanitize_text_field($username),
            'supplier_id'  => sanitize_text_field($supplier_id),
            'password_enc' => self::encrypt($pw),
        ], false);
        // Đổi cấu hình -> bỏ token cache cũ (có thể khác tài khoản).
        delete_transient(self::tok_transient_key());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Mã hóa mật khẩu (aes-256-gcm, key từ AUTH_KEY) — cùng kiểu với clusters
    // ────────────────────────────────────────────────────────────────────────

    private static function crypto_key()
    {
        $source = defined('AUTH_KEY') && AUTH_KEY ? AUTH_KEY : wp_salt('auth');
        return hash('sha256', 'tgs_viettel_portal|' . $source, true);
    }

    private static function encrypt($plain)
    {
        if ($plain === '' || !function_exists('openssl_encrypt')) {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return '';
        }
        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt($payload)
    {
        if ($payload === '' || strpos($payload, 'gcm:') !== 0 || !function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($payload, 4), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Portal client
    // ────────────────────────────────────────────────────────────────────────

    private static function tok_transient_key($scope = '')
    {
        if ($scope === '') {
            $scope = 'b' . get_current_blog_id();
        }
        return 'tgs_vt_portal_tok_' . $scope;
    }

    /**
     * Đăng nhập portal, cache token theo CỤM (nhiều site chung 1 token) — hoặc theo
     * site nếu dùng cấu hình per-site cũ. Scope lấy từ get_config()['_scope'].
     * @return array{ok:bool,token?:string,cluster?:string,message?:string}
     */
    public static function login($force = false)
    {
        $cfg = self::get_config();
        if ($cfg['username'] === '' || $cfg['password'] === '') {
            return ['ok' => false, 'message' => 'Chưa cấu hình tài khoản portal Viettel cho cụm của site này (Cấu hình cụm Viettel → "Gửi email đa địa chỉ").'];
        }
        $key = self::tok_transient_key($cfg['_scope'] ?? '');

        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached) && !empty($cached['token'])) {
                return ['ok' => true, 'token' => $cached['token'], 'cluster' => $cached['cluster'] ?? 'cluster3'];
            }
        }

        $resp = wp_remote_post(self::BASE . '/api/auth/login', [
            'timeout'     => 30,
            'httpversion' => '1.1',
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json, text/plain, */*',
                'Origin'       => self::BASE,
                'Referer'      => self::BASE . '/account/login',
                'Connection'   => 'keep-alive',
            ],
            'body' => wp_json_encode([
                'username'   => $cfg['username'],
                'password'   => $cfg['password'],
                'rememberMe' => false,
                'captcha'    => '',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => 'Không gọi được đăng nhập portal: ' . $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        if ($code < 200 || $code >= 300 || $token === '') {
            return ['ok' => false, 'message' => 'Đăng nhập portal thất bại (HTTP ' . $code . '). Kiểm tra lại tài khoản/mật khẩu portal.'];
        }

        $cluster = is_array($json) ? (string) ($json['invoice_cluster'] ?? '') : '';
        if ($cluster === '') {
            $cluster = self::cluster_from_jwt($token);
        }
        if ($cluster === '') {
            $cluster = 'cluster3';
        }

        $ttl = is_array($json) ? (int) ($json['expires_in'] ?? 1198) : 1198;
        $ttl = max(60, min(self::TOKEN_TTL, $ttl - 60));
        set_transient($key, ['token' => $token, 'cluster' => $cluster], $ttl);

        return ['ok' => true, 'token' => $token, 'cluster' => $cluster];
    }

    /** Đọc field invoice_cluster trong JWT (payload phần giữa). */
    private static function cluster_from_jwt($jwt)
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) < 2) {
            return '';
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return is_array($payload) ? (string) ($payload['invoice_cluster'] ?? '') : '';
    }

    private static function cluster_url($cluster, $path)
    {
        return self::BASE . '/api/' . rawurlencode($cluster) . '/services/einvoiceapplication/api/' . ltrim($path, '/');
    }

    /**
     * Tra ID nội bộ Viettel theo invoiceNo (cần cho send-email-customer).
     * @return array{ok:bool,id?:int,unauthorized?:bool,message?:string}
     */
    public static function resolve_id($token, $cluster, $invoice_no, $issue_date = '')
    {
        $invoice_no = trim((string) $invoice_no);
        if ($invoice_no === '') {
            return ['ok' => false, 'message' => 'Thiếu invoiceNo.'];
        }
        $cfg = self::get_config();

        // Cửa sổ createdDate (UTC). Có ngày phát hành -> ±3 ngày quanh nó; không thì 60 ngày gần đây.
        $from = gmdate('Y-m-d\T00:00:00.000\Z', strtotime('-60 days'));
        $to   = gmdate('Y-m-d\T23:59:59.000\Z');
        $ts = $issue_date ? strtotime($issue_date) : 0;
        if ($ts) {
            $from = gmdate('Y-m-d\T00:00:00.000\Z', $ts - 3 * 86400);
            $to   = gmdate('Y-m-d\T23:59:59.000\Z', $ts + 3 * 86400);
        }

        $q = [
            'page'                            => 0,
            'size'                            => 20,
            'invoiceNo.equals'                => $invoice_no,
            'createdDate.greaterThanOrEqual'  => $from,
            'createdDate.lessThanOrEqual'     => $to,
            'dateType.equals'                 => 0,
            'invoiceStatus.equals'            => 1,
            'invoiceTypeId.notEquals'         => 52,
            'sort'                            => 'issueDate,desc',
        ];
        if ($cfg['supplier_id'] !== '') {
            $q['supplierId.equals'] = $cfg['supplier_id'];
        }
        $url = self::cluster_url($cluster, 'invoice/search') . '?' . http_build_query($q);

        $resp = wp_remote_get($url, [
            'timeout'     => 30,
            'httpversion' => '1.1',
            'headers'     => [
                'Accept'        => 'application/json, text/plain, */*',
                'Authorization' => 'Bearer ' . $token,
                'Referer'       => self::BASE . '/invoice-management/invoice',
                'Connection'    => 'keep-alive',
            ],
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => 'Tìm hóa đơn lỗi: ' . $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'unauthorized' => true, 'message' => 'Token portal hết hạn.'];
        }
        $rows = self::extract_rows(json_decode((string) wp_remote_retrieve_body($resp), true));
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $no = (string) ($r['invoiceNo'] ?? ($r['invoiceNumber'] ?? ''));
            if ($no !== '' && strcasecmp($no, $invoice_no) === 0 && !empty($r['id'])) {
                return ['ok' => true, 'id' => (int) $r['id']];
            }
        }
        // Chỉ 1 dòng trả về -> lấy luôn (đã lọc theo invoiceNo.equals).
        if (count($rows) === 1 && !empty($rows[0]['id'])) {
            return ['ok' => true, 'id' => (int) $rows[0]['id']];
        }
        return ['ok' => false, 'message' => 'Không tìm thấy hóa đơn ' . $invoice_no . ' trên portal (HTTP ' . $code . ').'];
    }

    /** Bóc mảng bản ghi từ nhiều dạng response (JHipster mảng phẳng, hoặc {content:[]}). */
    private static function extract_rows($json)
    {
        if (!is_array($json)) {
            return [];
        }
        if (isset($json[0])) {
            return $json; // mảng phẳng
        }
        foreach ([['content'], ['data', 'content'], ['data'], ['result', 'content'], ['result']] as $path) {
            $cur = $json;
            $ok = true;
            foreach ($path as $k) {
                if (is_array($cur) && array_key_exists($k, $cur)) {
                    $cur = $cur[$k];
                } else {
                    $ok = false;
                    break;
                }
            }
            if ($ok && is_array($cur) && isset($cur[0])) {
                return $cur;
            }
        }
        return [];
    }

    /**
     * Gửi email hóa đơn tới danh sách địa chỉ (Viettel tự gửi — đúng định dạng).
     * @return array{ok:bool,http_code?:int,response?:string,unauthorized?:bool,message?:string}
     */
    public static function send_email($token, $cluster, $id, array $emails)
    {
        $emails = self::clean_emails($emails);
        if (empty($emails)) {
            return ['ok' => false, 'message' => 'Chưa có email người nhận hợp lệ.'];
        }
        $url = self::cluster_url($cluster, 'email/send-email-customer');
        $resp = wp_remote_post($url, [
            'timeout'     => 40,
            'httpversion' => '1.1',
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json, text/plain, */*',
                'Authorization' => 'Bearer ' . $token,
                'Origin'        => self::BASE,
                'Referer'       => self::BASE . '/invoice-management/invoice',
                'Connection'    => 'keep-alive',
            ],
            'body' => wp_json_encode([
                'id'                => (int) $id,
                'buyerEmailAddress' => implode(';', $emails),
            ], JSON_UNESCAPED_UNICODE),
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => 'Gửi email portal lỗi: ' . $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'unauthorized' => true, 'message' => 'Token portal hết hạn.'];
        }
        $body = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        $err = is_array($json) ? ($json['errorCode'] ?? ($json['error'] ?? null)) : null;
        $ok = ($code >= 200 && $code < 300) && (empty($err) || $err === '0' || strtolower((string) $err) === 'success');
        return [
            'ok'        => $ok,
            'http_code' => $code,
            'response'  => $body,
            'message'   => $ok ? 'OK' : ('Portal trả HTTP ' . $code . ($body !== '' ? ' — ' . mb_substr($body, 0, 250) : '')),
        ];
    }

    /**
     * Orchestrate đầy đủ: login -> resolve id -> send. Tự login lại 1 lần khi 401.
     * @param string[] $emails
     * @return array{ok:bool,invoice_id?:int,to?:string,message?:string,response?:string,http_code?:int}
     */
    public static function send_invoice($invoice_no, $issue_date, array $emails)
    {
        $emails = self::clean_emails($emails);
        if (empty($emails)) {
            return ['ok' => false, 'message' => 'Chưa có email người nhận hợp lệ.'];
        }

        $login = self::login();
        if (empty($login['ok'])) {
            return $login;
        }
        $token = $login['token'];
        $cluster = $login['cluster'];

        $res = self::resolve_id($token, $cluster, $invoice_no, $issue_date);
        if (!empty($res['unauthorized'])) {
            $login = self::login(true);
            if (empty($login['ok'])) {
                return $login;
            }
            $token = $login['token'];
            $cluster = $login['cluster'];
            $res = self::resolve_id($token, $cluster, $invoice_no, $issue_date);
        }
        if (empty($res['ok'])) {
            return $res;
        }

        $send = self::send_email($token, $cluster, $res['id'], $emails);
        if (!empty($send['unauthorized'])) {
            $login = self::login(true);
            if (empty($login['ok'])) {
                return $login;
            }
            $send = self::send_email($login['token'], $login['cluster'], $res['id'], $emails);
        }
        $send['invoice_id'] = $res['id'];
        $send['to'] = implode('; ', $emails);
        return $send;
    }

    /** @param string[] $emails */
    private static function clean_emails(array $emails)
    {
        $out = [];
        foreach ($emails as $e) {
            $e = sanitize_email(trim((string) $e));
            if ($e !== '' && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Tách chuỗi nhiều email (phân cách ; , khoảng trắng, xuống dòng) thành mảng. */
    public static function split_emails($raw)
    {
        $parts = preg_split('/[;,\s]+/', (string) $raw) ?: [];
        return self::clean_emails($parts);
    }
}
