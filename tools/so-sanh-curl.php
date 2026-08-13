<?php
/**
 * Khoanh vùng: vì sao shell curl gọi được mà PHP thì không?
 *
 * ── BỐI CẢNH ────────────────────────────────────────────────────────────────
 * Trên cùng một server, cùng một IP, cùng một URL:
 *
 *     curl tu terminal   -> HTTP 500, curl_exit=0, nhan du 109 byte JSON
 *     PHP/WordPress      -> cURL error 56, khong nhan duoc gi
 *
 * Đã loại: đường mạng, TLS, IP nguồn, tài khoản, header, User-Agent, method,
 * payload, HTTP/2, TLS 1.3. Khác biệt còn lại nằm trong chính tầng PHP.
 *
 * File này gọi curl TRỰC TIẾP (không qua WordPress) với từng biến thể tham số,
 * để xem tham số nào biến một request đang chạy thành đứt gãy. Biến thể cuối
 * mới đi qua WordPress, để tách "lỗi của PHP" khỏi "lỗi của WP HTTP API".
 *
 * ── CÁCH DÙNG ───────────────────────────────────────────────────────────────
 * Chạy CẢ HAI cách rồi so hai bảng với nhau — đó là phần quan trọng nhất:
 *
 *   1) Qua web (chạy dưới user php-fpm, đúng môi trường đang lỗi):
 *      https://<domain>/wp-content/plugins/tgs-viettel-invoice/tools/so-sanh-curl.php
 *
 *   2) Qua terminal (chạy dưới root, php.ini của CLI):
 *      php /duong/dan/toi/tools/so-sanh-curl.php
 *
 * Nếu bảng CLI xanh mà bảng web đỏ => khác biệt ở php-fpm (user, php.ini,
 * open_basedir, cấu hình OpenSSL riêng). Nếu cả hai cùng đỏ => khác biệt nằm
 * giữa libcurl của PHP và curl hệ thống.
 *
 * ⚠️ XÓA FILE NÀY SAU KHI CHẨN ĐOÁN XONG.
 */

$is_cli = (PHP_SAPI === 'cli');

// Qua web thì phải chặn người lạ; qua CLI thì đã cần quyền shell rồi.
$wp_loaded = false;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    if (is_file($dir . '/wp-load.php')) {
        require_once $dir . '/wp-load.php';
        $wp_loaded = true;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!$wp_loaded || !is_user_logged_in() || !current_user_can('manage_options')) {
        status_header(403);
        exit('403 - Can dang nhap bang tai khoan quan tri cua site nay.');
    }
}

$URL = 'https://api-vinvoice.viettel.vn/services/einvoiceapplication/api/InvoiceAPI/InvoiceWS/createInvoice/0100109106-507';
$ROOT = 'https://api-vinvoice.viettel.vn/';

echo str_repeat('=', 70) . "\n";
echo "SO SANH CURL: PHP vs SHELL\n";
echo str_repeat('=', 70) . "\n";
printf("%-22s %s\n", 'Chay bang:', $is_cli ? 'PHP CLI (terminal)' : 'PHP qua web (php-fpm)');
printf("%-22s %s\n", 'User he dieu hanh:', function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : (getenv('USER') ?: '?'));
printf("%-22s %s\n", 'PHP:', PHP_VERSION);
printf("%-22s %s\n", 'php.ini:', php_ini_loaded_file() ?: '(khong ro)');
$cv = curl_version();
printf("%-22s %s\n", 'libcurl cua PHP:', $cv['version'] . ' / ' . $cv['ssl_version']);
printf("%-22s %s\n", 'OpenSSL cua PHP:', defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'n/a');
printf("%-22s %s\n", 'openssl.cafile:', ini_get('openssl.cafile') ?: '(trong)');
printf("%-22s %s\n", 'curl.cainfo:', ini_get('curl.cainfo') ?: '(trong)');
printf("%-22s %s\n", 'open_basedir:', ini_get('open_basedir') ?: '(khong dat)');
echo "\n";

/**
 * Gọi curl trực tiếp với một bộ tham số, trả về kết quả gọn.
 *
 * Cố tình KHÔNG dùng wp_remote_*: mục đích là xem PHP thuần có gọi được không,
 * tách khỏi mọi thứ WordPress thêm vào.
 */
function thu_curl($url, array $opts = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ] + $opts);

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'ok'    => ($errno === 0),
        'errno' => $errno,
        'err'   => $err,
        'code'  => $code,
        'bytes' => is_string($body) ? strlen($body) : 0,
        'body'  => is_string($body) ? substr($body, 0, 160) : '',
    ];
}

function in_ket_qua($ten, $r)
{
    if ($r['ok']) {
        printf("  %-44s OK    HTTP %-4d %d byte\n", $ten, $r['code'], $r['bytes']);
    } else {
        printf("  %-44s LOI   curl errno %d\n", $ten, $r['errno']);
        printf("  %-44s       %s\n", '', $r['err']);
    }
}

echo str_repeat('=', 70) . "\n";
echo "A. CURL THUAN CUA PHP (khong qua WordPress)\n";
echo str_repeat('=', 70) . "\n";
echo "Dong dau tien la quan trong nhat: PHP goi curl toi gian co chay khong.\n\n";

$bien_the = [
    'toi gian (chi RETURNTRANSFER)'          => [],
    '+ ep HTTP/1.1'                          => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1],
    '+ ep HTTP/1.0 (tat chunked)'            => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0],
    '+ Connection: close'                    => [CURLOPT_HTTPHEADER => ['Connection: close']],
    '+ CURLOPT_ENCODING rong (nhan gzip)'    => [CURLOPT_ENCODING => ''],
    '+ Accept-Encoding kieu WordPress'       => [CURLOPT_HTTPHEADER => ['Accept-Encoding: deflate;q=1.0, compress;q=0.5, gzip;q=0.5']],
    '+ User-Agent kieu WordPress'            => [CURLOPT_USERAGENT => 'WordPress/6.7; ' . ($wp_loaded ? home_url() : 'https://example.com')],
    '+ tat verify SSL'                       => [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0],
    '+ ep IPv4'                              => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
    '+ POST body {} nhu luc that'            => [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ],
];

$ket_qua_a = [];
foreach ($bien_the as $ten => $opts) {
    $r = thu_curl($URL, $opts);
    $ket_qua_a[$ten] = $r['ok'];
    in_ket_qua($ten, $r);
}
echo "\n";
echo "  --- doi chieu: trang chu / (duong PHP van goi duoc) ---\n";
in_ket_qua('GET / toi gian', thu_curl($ROOT));
echo "\n";

echo str_repeat('=', 70) . "\n";
echo "B. QUA WORDPRESS HTTP API\n";
echo str_repeat('=', 70) . "\n";
if (!$wp_loaded) {
    echo "  (khong nap duoc WordPress — bo qua)\n\n";
} else {
    echo "Kiem chung ban vá: dat 'httpversion' => '1.1' co du de cuu khong.\n";
    echo "Can biet vi WordPress co the VAN tu gan 'Connection: close' — ma rieng\n";
    echo "header do cung du gay loi 56 (xem muc A).\n\n";

    /** Gọi qua WP HTTP API, có bắt luôn nhật ký verbose của libcurl. */
    $thu_wp = function ($ten, $args, &$verbose_out = null) use ($URL) {
        $vh = fopen('php://temp', 'w+');
        $bat_verbose = function ($handle) use ($vh) {
            curl_setopt($handle, CURLOPT_VERBOSE, true);
            curl_setopt($handle, CURLOPT_STDERR, $vh);
        };

        add_action('http_api_curl', $bat_verbose, 10, 1);
        $resp = wp_remote_get($URL, $args);
        remove_action('http_api_curl', $bat_verbose, 10);

        rewind($vh);
        $verbose_out = stream_get_contents($vh);
        fclose($vh);

        if (is_wp_error($resp)) {
            printf("  %-44s LOI   %s\n", $ten, $resp->get_error_code());
            printf("  %-44s       %s\n", '', $resp->get_error_message());
            return false;
        }

        printf(
            "  %-44s OK    HTTP %-4d %d byte\n",
            $ten,
            (int) wp_remote_retrieve_response_code($resp),
            strlen((string) wp_remote_retrieve_body($resp))
        );
        return true;
    };

    $v_mac_dinh = '';
    $v_11 = '';
    $thu_wp('wp_remote_get MAC DINH (httpversion 1.0)', ['timeout' => 30], $v_mac_dinh);
    $wp_11_ok = $thu_wp("wp_remote_get + httpversion '1.1'", ['timeout' => 30, 'httpversion' => '1.1'], $v_11);
    $thu_wp("wp_remote_get + 1.1 + Connection: keep-alive", [
        'timeout'     => 30,
        'httpversion' => '1.1',
        'headers'     => ['Connection' => 'keep-alive'],
    ], $v_keep);

    echo "\n  --- WordPress THUC SU gui di nhung dong nao (bien the httpversion 1.1) ---\n";
    foreach (explode("\n", trim($v_11)) as $dong) {
        // Chỉ lấy dòng request đi ra (">") cho gọn — đủ để soi header Connection.
        if (strpos($dong, '>') === 0 || stripos($dong, 'Connection:') !== false) {
            echo '    ' . rtrim($dong) . "\n";
        }
    }
    echo "\n";

    if (!empty($wp_11_ok)) {
        echo "  => BAN VÁ DUNG. Neu web that su van loi thi code moi CHUA CHAY tren\n";
        echo "     server: kiem tra file da len dung chua va nho reload PHP-FPM\n";
        echo "     (OPcache con giu ban cu trong bo nho).\n";
    } else {
        echo "  => BAN VÁ CHUA DU. Dat httpversion 1.1 khong cuu duoc; xem cac dong\n";
        echo "     '>' o tren de biet WordPress con gan them header gi.\n";
    }
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "B2. FILE TREN SERVER DA CO BAN VÁ CHUA?\n";
echo str_repeat('=', 70) . "\n";
$plugin_file = dirname(__DIR__) . '/tgs-viettel-invoice.php';
if (!is_file($plugin_file)) {
    echo "  Khong thay $plugin_file\n\n";
} else {
    $noi_dung = (string) file_get_contents($plugin_file);
    $so_lan = substr_count($noi_dung, "'httpversion' => '1.1'");
    printf("  %-30s %s\n", 'File:', $plugin_file);
    printf("  %-30s %s\n", 'Sua lan cuoi:', date('Y-m-d H:i:s', (int) filemtime($plugin_file)));
    printf("  %-30s %s\n", 'md5:', md5($noi_dung));
    printf("  %-30s %d (can >= 2)\n", "So dong 'httpversion' => '1.1':", $so_lan);
    if ($so_lan < 2) {
        echo "\n  >>> FILE TREN SERVER CHUA CO BAN VÁ. Upload lai.\n";
    } else {
        echo "\n  File da co ban vá. Neu van loi thi la do OPcache giu ban cu:\n";
        if (function_exists('opcache_get_status')) {
            $st = @opcache_get_status(false);
            printf("  %-30s %s\n", 'OPcache:', !empty($st['opcache_enabled']) ? 'DANG BAT' : 'tat');
            printf("  %-30s %s\n", 'validate_timestamps:', ini_get('opcache.validate_timestamps'));
            printf("  %-30s %s\n", 'revalidate_freq:', ini_get('opcache.revalidate_freq'));
        }
        echo "  => Reload PHP-FPM (aaPanel > PHP > Khoi dong lai) roi thu lai.\n";
    }
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "C. NHAT KY VERBOSE CUA CURL (bien the toi gian)\n";
echo str_repeat('=', 70) . "\n";
echo "Xem chinh xac ket noi dut o buoc nao.\n\n";
$vh = fopen('php://temp', 'w+');
thu_curl($URL, [CURLOPT_VERBOSE => true, CURLOPT_STDERR => $vh]);
rewind($vh);
$verbose = stream_get_contents($vh);
fclose($vh);
foreach (explode("\n", trim($verbose)) as $dong) {
    echo '  ' . $dong . "\n";
}
echo "\n";

echo str_repeat('=', 70) . "\n";
echo "CACH DOC\n";
echo str_repeat('=', 70) . "\n";
if (!empty($ket_qua_a['toi gian (chi RETURNTRANSFER)'])) {
    echo "  curl thuan cua PHP CHAY DUOC => loi do thu gi do WordPress them vao.\n";
    echo "  Xem muc B va doi chieu voi cac bien the o muc A de biet la tham so nao.\n";
} else {
    echo "  Ngay ca curl toi gian cua PHP cung dut, trong khi curl o terminal thi\n";
    echo "  chay => khac biet nam giua libcurl cua PHP va curl he thong, hoac giua\n";
    echo "  moi truong php-fpm va shell. So bang nay voi bang chay bang CLI.\n";
    echo "  Neu bien the nao o muc A lai OK thi chinh tham so do la cach vá.\n";
}
echo "\n>>> XOA FILE NAY SAU KHI CHAN DOAN XONG. <<<\n";
