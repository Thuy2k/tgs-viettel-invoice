<?php
/**
 * Chẩn đoán kết nối tới API Viettel VInvoice.
 *
 * ── DÙNG KHI NÀO ────────────────────────────────────────────────────────────
 * Khi POS báo "Gửi API thất bại: cURL error ..." kèm HTTP 0. HTTP 0 nghĩa là
 * wp_remote_post() trả WP_Error — request chưa hề nhận được response nào, nên
 * mọi thứ thuộc về payload (thuế suất, hàng tặng, tiền nong) đều VÔ CAN. Lỗi
 * nằm ở tầng mạng/TLS hoặc ở việc bị đầu kia từ chối.
 *
 * File này tách bạch hai khả năng đó:
 *
 *   - Nếu ngay cả URL gốc api-vinvoice.viettel.vn cũng hỏng → lỗi MẠNG của
 *     server (firewall, DPI, OpenSSL). Không liên quan tài khoản.
 *   - Nếu URL gốc OK mà riêng createInvoice bị cắt → đầu Viettel chủ động từ
 *     chối. Nhìn sang MST/tài khoản đang cấu hình.
 *
 * ── CÁCH DÙNG ───────────────────────────────────────────────────────────────
 * Đăng nhập admin của ĐÚNG site cần kiểm tra, rồi mở:
 *     https://<domain-site-do>/wp-content/plugins/tgs-viettel-invoice/tools/tls-diagnostic.php
 *
 * Phải mở bằng đúng domain của site đang lỗi: multisite nạp cấu hình theo
 * HTTP_HOST, mở nhầm domain là đọc nhầm bộ settings.
 *
 * ⚠️ XÓA FILE NÀY SAU KHI CHẨN ĐOÁN XONG.
 */

// Đi ngược lên tìm wp-load.php thay vì đếm cứng số cấp thư mục: đếm cứng là
// sai ngay khi file bị đổi chỗ, mà thông báo lỗi lúc đó ("No such file") lại
// không hề gợi ý nguyên nhân thật.
$tgs_diag_root = __DIR__;
$tgs_diag_wp_load = '';
for ($i = 0; $i < 8; $i++) {
    if (is_file($tgs_diag_root . '/wp-load.php')) {
        $tgs_diag_wp_load = $tgs_diag_root . '/wp-load.php';
        break;
    }
    $tgs_diag_parent = dirname($tgs_diag_root);
    if ($tgs_diag_parent === $tgs_diag_root) {
        break;
    }
    $tgs_diag_root = $tgs_diag_parent;
}

if ($tgs_diag_wp_load === '') {
    header('Content-Type: text/plain; charset=utf-8');
    exit('Khong tim thay wp-load.php khi di nguoc tu ' . __DIR__);
}

require_once $tgs_diag_wp_load;

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    status_header(403);
    exit('403 - Cần đăng nhập bằng tài khoản quản trị của site này.');
}

header('Content-Type: text/plain; charset=utf-8');

if (!class_exists('TGS_Viettel_Invoice_Plugin')) {
    exit('Không tìm thấy class TGS_Viettel_Invoice_Plugin — plugin chưa kích hoạt trên site này?');
}

$settings = TGS_Viettel_Invoice_Plugin::get_settings();
$base     = untrailingslashit($settings['api_base_url'] ?? '');
$mst      = (string) ($settings['supplier_tax_code'] ?? '');
$host     = parse_url($base, PHP_URL_HOST);

function tgs_diag_line($label, $value = '')
{
    printf("%-26s %s\n", $label, $value);
}

function tgs_diag_head($title)
{
    echo "\n" . str_repeat('=', 74) . "\n" . $title . "\n" . str_repeat('=', 74) . "\n";
}

/**
 * In gọn kết quả một lần gọi wp_remote_*.
 *
 * WP_Error mới là thứ cần soi: error_code + message chứa nguyên văn lỗi cURL.
 * HTTP code bất kỳ (kể cả 401/403/404) đều tính là ĐI TỚI NƠI — tức tầng mạng
 * không có vấn đề.
 */
function tgs_diag_result($response)
{
    if (is_wp_error($response)) {
        echo "  KET QUA : LOI TRANSPORT (khong nhan duoc response)\n";
        echo "  code    : " . $response->get_error_code() . "\n";
        echo "  message : " . $response->get_error_message() . "\n";
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    echo "  KET QUA : DEN DUOC SERVER — HTTP " . $code . "\n";
    echo "  body    : " . mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 300) . "\n";
    return true;
}

tgs_diag_head('1. MOI TRUONG');
tgs_diag_line('Site:', home_url());
tgs_diag_line('Blog ID:', (string) get_current_blog_id());
tgs_diag_line('PHP:', PHP_VERSION);
tgs_diag_line('OpenSSL:', defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'n/a');
if (function_exists('curl_version')) {
    $cv = curl_version();
    tgs_diag_line('cURL:', $cv['version'] . ' / ' . $cv['ssl_version']);
}
tgs_diag_line('WP_HTTP_BLOCK_EXTERNAL:', defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL ? 'BAT (dang chan!)' : 'tat');
tgs_diag_line('WP_PROXY_HOST:', defined('WP_PROXY_HOST') ? WP_PROXY_HOST : 'khong dat');

tgs_diag_head('2. CAU HINH VIETTEL CUA SITE NAY');
tgs_diag_line('api_base_url:', $base !== '' ? $base : '(TRONG)');
tgs_diag_line('supplier_tax_code:', $mst !== '' ? $mst : '(TRONG)');
tgs_diag_line('auth_mode:', (string) ($settings['auth_mode'] ?? ''));
tgs_diag_line('username:', (string) ($settings['username'] ?? '(TRONG)'));
tgs_diag_line('password:', !empty($settings['password']) ? 'da dat (' . strlen($settings['password']) . ' ky tu)' : '(TRONG)');
tgs_diag_line('verify_ssl:', !empty($settings['verify_ssl']) ? 'bat' : 'tat');
tgs_diag_line('template / series:', ($settings['default_template_code'] ?? '') . ' / ' . ($settings['default_invoice_series'] ?? ''));

if ($mst === '0100109106-507') {
    echo "\n  >>> CANH BAO: MST nay la chuoi VI DU trong placeholder cua form cai dat\n";
    echo "  >>> (MST cua chinh tap doan Viettel). Day gan nhu chac chan la gia tri\n";
    echo "  >>> bi copy nham vao. Bắn so demo vao host production se bi tu choi.\n";
}

tgs_diag_head('3. IP DI RA CUA SERVER');
$ip = wp_remote_get('https://api.ipify.org', ['timeout' => 15, 'sslverify' => false]);
tgs_diag_line('IP public:', is_wp_error($ip) ? 'khong lay duoc (' . $ip->get_error_message() . ')' : trim(wp_remote_retrieve_body($ip)));
tgs_diag_line('DNS ' . $host . ':', $host ? implode(', ', (array) gethostbynamel($host)) : 'n/a');

tgs_diag_head('4. BAT TAY TLS THUAN (khong qua cURL)');
echo "Dung fsockopen de xem TLS co bat tay duoc khong. Neu buoc nay OK ma cURL\n";
echo "van loi 56 thi van de nam o tang HTTP/TLS cua cURL, khong phai duong mang.\n\n";
$errno = 0;
$errstr = '';
$sock = @stream_socket_client(
    'ssl://' . $host . ':443',
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT,
    stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
);
if ($sock) {
    $meta = stream_get_meta_data($sock);
    echo "  KET QUA : BAT TAY TLS THANH CONG\n";
    if (!empty($meta['crypto'])) {
        tgs_diag_line('  protocol:', $meta['crypto']['protocol'] ?? '?');
        tgs_diag_line('  cipher:', $meta['crypto']['cipher_name'] ?? '?');
    }
    fclose($sock);
} else {
    echo "  KET QUA : KHONG BAT TAY DUOC — errno {$errno}: {$errstr}\n";
}

tgs_diag_head('5. GOI URL GOC (khong auth, khong payload)');
echo "Day la phep thu QUYET DINH.\n";
echo "  - Ra bat ky HTTP code nao (200/403/404...) => duong mang TOT.\n";
echo "  - Loi transport o day               => loi MANG cua server.\n\n";
$root_ok = tgs_diag_result(wp_remote_get('https://' . $host . '/', [
    'timeout'   => 20,
    'sslverify' => !empty($settings['verify_ssl']),
]));

tgs_diag_head('6. GOI DUNG ENDPOINT createInvoice (payload rong)');
echo "Gui body rong co chu dich: khong tao hoa don that. Mong doi 400/401/500 —\n";
echo "bat ky con so nao cung tot hon la loi transport.\n\n";
$url = $base . '/InvoiceAPI/InvoiceWS/createInvoice/' . rawurlencode($mst);
tgs_diag_line('URL:', $url);
echo "\n";

$headers = ['Content-Type' => 'application/json'];
if (($settings['auth_mode'] ?? '') === 'token') {
    $headers['Authorization'] = 'Bearer ' . $settings['access_token'];
} else {
    $headers['Authorization'] = 'Basic ' . base64_encode($settings['username'] . ':' . $settings['password']);
}

$endpoint_ok = tgs_diag_result(wp_remote_post($url, [
    'headers'   => $headers,
    'body'      => '{}',
    'timeout'   => 45,
    'sslverify' => !empty($settings['verify_ssl']),
]));

tgs_diag_head('7. LAP LAI BUOC 6 NHUNG EP HTTP/1.1 + TLS 1.2');
echo "Mot so firewall/DPI chi cat dut khi thay HTTP/2 hoac TLS 1.3. Neu buoc nay\n";
echo "chay duoc ma buoc 6 hong thi da co cach vá ngay trong code.\n\n";

$force_legacy = function ($handle) {
    if (defined('CURL_HTTP_VERSION_1_1')) {
        curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    }
    if (defined('CURL_SSLVERSION_TLSv1_2')) {
        curl_setopt($handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    }
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }
};

add_action('http_api_curl', $force_legacy, 10, 1);
$legacy_ok = tgs_diag_result(wp_remote_post($url, [
    'headers'   => $headers,
    'body'      => '{}',
    'timeout'   => 45,
    'sslverify' => !empty($settings['verify_ssl']),
]));
remove_action('http_api_curl', $force_legacy, 10);

tgs_diag_head('8. THAM DO: BI CAT O DAU TRONG CHUOI XU LY?');
echo "Da biet: URL goc ra 200 nhung createInvoice bi cat. Con hai kha nang:\n";
echo "  B1 - Viettel tu choi vi TAI KHOAN demo   => sua MST/user/pass la xong.\n";
echo "  B2 - WAF chan theo IP nguon              => dien MST that VAN hong,\n";
echo "                                              phai nho Viettel mo IP.\n\n";
echo "Cach tach: bo dan tung yeu to ra khoi request. Neu bo Authorization di ma\n";
echo "van bi cat thi cu cat XAY RA TRUOC KHI xet tai khoan => B2. Neu luc do lai\n";
echo "ra 401 thi gateway co doc request => chinh tai khoan kich hoat cu cat => B1.\n\n";

$no_auth_headers = ['Content-Type' => 'application/json'];

$probes = [
    'a' => [
        'label'  => 'POST duong KHONG TON TAI duoi /api (co auth)',
        'why'    => 'Gateway co tra loi POST noi chung khong?',
        'method' => 'POST',
        'url'    => $base . '/duong-khong-ton-tai-' . time(),
        'args'   => ['headers' => $headers, 'body' => '{}'],
    ],
    'b' => [
        'label'  => 'POST createInvoice — BO HEADER Authorization',
        'why'    => 'PHEP THU CHINH. Ra 401 => B1. Bi cat => B2.',
        'method' => 'POST',
        'url'    => $url,
        'args'   => ['headers' => $no_auth_headers, 'body' => '{}'],
    ],
    'c' => [
        'label'  => 'GET createInvoice (khong body)',
        'why'    => 'Cu cat co phu thuoc vao viec co body POST khong?',
        'method' => 'GET',
        'url'    => $url,
        'args'   => ['headers' => $headers],
    ],
    'd' => [
        'label'  => 'POST createInvoice voi MST BIA 0000000000',
        'why'    => 'Cu cat co phu thuoc vao chinh con so MST khong?',
        'method' => 'POST',
        'url'    => $base . '/InvoiceAPI/InvoiceWS/createInvoice/0000000000',
        'args'   => ['headers' => $headers, 'body' => '{}'],
    ],
    'e' => [
        'label'  => 'POST createOrUpdateInvoiceDraft (MST that)',
        'why'    => 'Chi rieng createInvoice bi chan hay ca cum InvoiceWS?',
        'method' => 'POST',
        'url'    => $base . '/InvoiceAPI/InvoiceWS/createOrUpdateInvoiceDraft/' . rawurlencode($mst),
        'args'   => ['headers' => $headers, 'body' => '{}'],
    ],
];

$probe_ok = [];
foreach ($probes as $key => $probe) {
    echo "--- " . strtoupper($key) . ". " . $probe['label'] . "\n";
    echo "    (" . $probe['why'] . ")\n";
    $args = array_merge($probe['args'], [
        'timeout'   => 30,
        'sslverify' => !empty($settings['verify_ssl']),
    ]);
    $resp = ($probe['method'] === 'GET')
        ? wp_remote_get($probe['url'], $args)
        : wp_remote_post($probe['url'], $args);
    $probe_ok[$key] = tgs_diag_result($resp);
    echo "\n";
}

tgs_diag_head('9. TACH BIEN: DUONG DAN, HEADER, METHOD hay USER-AGENT?');
echo "Muc 5 (GET / tran) ra 200; moi probe muc 8 deu bi cat. Nhung muc 5 khac\n";
echo "cac probe o CA HAI THU: duong dan VA bo header. Chua tach duoc.\n";
echo "Vong nay doi tung bien mot, giu nguyen phan con lai.\n\n";

$probes2 = [
    'f' => [
        'label'  => 'GET duong API sau — KHONG header phu',
        'why'    => 'Ra HTTP => thu phat la HEADER. Bi cat => thu phat la DUONG DAN.',
        'method' => 'GET',
        'url'    => $url,
        'args'   => [],
    ],
    'g' => [
        'label'  => 'GET / (goc) — KEM DUNG bo header cua buoc 6',
        'why'    => 'Bi cat => HEADER la thu phat. Ra 200 => header vo can.',
        'method' => 'GET',
        'url'    => 'https://' . $host . '/',
        'args'   => ['headers' => $headers],
    ],
    'h' => [
        'label'  => 'GET /services/einvoiceapplication/ — khong header phu',
        'why'    => 'Cu cat bat dau tu cap thu muc nao?',
        'method' => 'GET',
        'url'    => 'https://' . $host . '/services/einvoiceapplication/',
        'args'   => [],
    ],
    'i' => [
        'label'  => 'POST / (goc) — body {}, khong header phu',
        'why'    => 'Rieng METHOD POST co bi chan khong?',
        'method' => 'POST',
        'url'    => 'https://' . $host . '/',
        'args'   => ['body' => '{}'],
    ],
    'j' => [
        'label'  => 'GET duong API sau — User-Agent gia trinh duyet',
        'why'    => 'WAF co chan theo UA "WordPress/..." khong?',
        'method' => 'GET',
        'url'    => $url,
        'args'   => ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'],
    ],
];

$p2 = [];
foreach ($probes2 as $key => $probe) {
    echo "--- " . strtoupper($key) . ". " . $probe['label'] . "\n";
    echo "    (" . $probe['why'] . ")\n";
    $args = array_merge($probe['args'], [
        'timeout'   => 30,
        'sslverify' => !empty($settings['verify_ssl']),
    ]);
    $resp = ($probe['method'] === 'GET')
        ? wp_remote_get($probe['url'], $args)
        : wp_remote_post($probe['url'], $args);
    $p2[$key] = tgs_diag_result($resp);
    echo "\n";
}

echo "DOC KET QUA VONG NAY:\n";
if (!empty($p2['g']) && empty($p2['f'])) {
    echo "  Header vo can (G ra HTTP), duong dan sau bi cat (F bi cat)\n";
    echo "  => Gateway chan theo DUONG DAN /InvoiceAPI/. Ket hop voi viec bo\n";
    echo "     Authorization van bi cat (8.B): day la chan TRUY CAP, khong phai\n";
    echo "     chan tai khoan. Gan nhu chac chan la IP nguon chua duoc cap quyen.\n";
} elseif (empty($p2['g']) && !empty($p2['f'])) {
    echo "  Duong dan vo can (F ra HTTP), header lam gateway cat (G bi cat)\n";
    echo "  => Thu phat nam trong BO HEADER dang gui. Nhieu kha nang la WAF chan\n";
    echo "     Content-Type: application/json hoac Authorization tu IP la.\n";
} elseif (!empty($p2['j']) && empty($p2['f'])) {
    echo "  Doi User-Agent thanh trinh duyet thi qua duoc (J ok, F bi cat)\n";
    echo "  => WAF chan theo User-Agent 'WordPress/...'. Cai nay VÁ DUOC trong\n";
    echo "     plugin bang cach doi user-agent. Bao toi de lam.\n";
} else {
    echo "  Xem bang tren de doi chieu tung bien.\n";
}

tgs_diag_head('KET LUAN');

if ($root_ok && !$endpoint_ok) {
    echo "Duong mang TOT (URL goc ra 200) nhung createInvoice bi cat dut.\n";
    echo "Phia Viettel chu dong tu choi. Tham do noi them:\n\n";

    if ($probe_ok['b']) {
        echo "  >>> KICH BAN B1 — do TAI KHOAN.\n";
        echo "  Bo Authorization di thi gateway van tra loi bang HTTP. Tuc la no CO\n";
        echo "  doc request cua minh; chi khi kem bo thong tin dang nhap demo thi moi\n";
        echo "  bi cat. Doi MST + username/password sang tai khoan that (hoac xin\n";
        echo "  Viettel cap tai khoan sandbox KEM base URL sandbox rieng) la chay.\n";
    } elseif (!$probe_ok['b'] && !empty($probe_ok['a'])) {
        echo "  >>> KICH BAN B2 — chan theo DUONG DAN / IP NGUON.\n";
        echo "  POST vao duong khong ton ton tai duoi /api thi ra HTTP binh thuong,\n";
        echo "  nhung moi request vao /InvoiceAPI/ deu bi cat — ke ca khi da bo\n";
        echo "  Authorization. Cu cat xay ra TRUOC khi xet tai khoan.\n";
        echo "  => Doi MST se KHONG giai quyet duoc. Phai lien he Viettel de dang ky\n";
        echo "     IP " . (isset($ip) && !is_wp_error($ip) ? trim(wp_remote_retrieve_body($ip)) : '(xem muc 3)') . " cho tai khoan cua ban.\n";
    } else {
        echo "  >>> Moi request POST toi host nay deu bi cat, ke ca duong khong ton tai.\n";
        echo "  Kha nang cao la chan theo IP nguon o tang WAF. Lien he Viettel, cung\n";
        echo "  cap IP o muc 3 va hoi xem IP do co trong danh sach cho phep khong.\n";
    }

    if ($mst === '0100109106-507') {
        echo "\n  Du kich ban nao thi MST/username " . $mst . " van la so VI DU trong\n";
        echo "  tai lieu Viettel — can thay bang thong tin that truoc khi chay that.\n";
    }
} elseif (!$root_ok) {
    echo "URL goc cung khong goi duoc => LOI MANG PHIA SERVER.\n";
    echo "Khong phai do MST hay tai khoan. Lam viec voi ben quan tri VPS/firewall:\n";
    echo "  - Kiem tra firewall chieu OUT toi " . $host . ":443\n";
    echo "  - Kiem tra aaPanel co bat chan ket noi di ra khong\n";
    if ($legacy_ok) {
        echo "\nNhung buoc 7 (HTTP/1.1 + TLS1.2) lai CHAY DUOC — co the vá trong code,\n";
        echo "khong can dong toi ha tang. Bao toi de them tuy chon nay vao plugin.\n";
    }
} else {
    echo "Ca hai buoc deu goi duoc. Loi cURL 56 luc lam that co the la CHAP CHON.\n";
    echo "=> Can them co che retry trong plugin. Bao toi de lam.\n";
}

echo "\n>>> XOA FILE NAY SAU KHI CHAN DOAN XONG. <<<\n";
