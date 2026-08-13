#!/bin/bash
#
# Kiểm tra kết nối từ server tới API hoá đơn điện tử Viettel.
#
# ── MỤC ĐÍCH ────────────────────────────────────────────────────────────────
# Chứng minh vấn đề nằm ngoài phạm vi ứng dụng. Script này KHÔNG dùng PHP,
# KHÔNG dùng WordPress, KHÔNG đọc cấu hình plugin — chỉ có curl và openssl gọi
# thẳng ra mạng. Nếu nó vẫn hỏng thì lỗi không thể do code web gây ra.
#
# Toàn bộ lệnh đều CHỈ ĐỌC: không ghi file, không đổi cấu hình, không cài gì.
#
# ── CÁCH DÙNG ───────────────────────────────────────────────────────────────
#     bash kiem-tra-mang.sh
#
# Chạy trên CẢ HAI server (UAT và server thật) rồi đặt hai kết quả cạnh nhau —
# đó mới là bằng chứng đắt nhất: cùng một lệnh, cùng một đích đến, khác mỗi IP
# nguồn, ra hai kết quả khác nhau.

HOST=api-vinvoice.viettel.vn
BASE=https://$HOST/services/einvoiceapplication/api
API_PATH=$BASE/InvoiceAPI/InvoiceWS/createInvoice/0100109106-507

vach() { printf '%s\n' '=================================================================='; }

# In tên phép thử kèm kết quả gọn một dòng.
#
# Phân biệt rõ hai loại thất bại, vì chúng dẫn tới hai kết luận trái ngược:
#   - Có HTTP code (kể cả 401/404/500) => gói tin ĐI TỚI NƠI và VỀ ĐƯỢC.
#   - curl exit code                   => không hề nhận được phản hồi nào.
probe() {
    local ten="$1"; shift
    local code rc
    code=$(curl -s -o /dev/null --max-time 30 -w '%{http_code}' "$@" 2>/dev/null)
    rc=$?
    if [ "$rc" -eq 0 ]; then
        printf '  %-46s HTTP %s\n' "$ten" "$code"
    else
        printf '  %-46s THAT BAI (curl exit %s)\n' "$ten" "$rc"
    fi
}

vach
echo "KIEM TRA KET NOI TOI $HOST"
vach
echo "Thoi diem : $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "May chay  : $(hostname)"
echo "curl      : $(curl --version 2>/dev/null | head -1)"
echo "openssl   : $(openssl version 2>/dev/null)"
echo

vach
echo "1. IP DI RA CUA SERVER NAY"
vach
echo "  IP public : $(curl -s --max-time 15 https://api.ipify.org)"
echo "  DNS $HOST : $(getent hosts $HOST | awk '{print $1}' | tr '\n' ' ')"
echo

vach
echo "2. TCP + TLS CO THONG KHONG"
vach
echo "  (Neu buoc nay xong thi tuong lua/dinh tuyen phia minh KHONG co van de)"
echo
timeout 20 openssl s_client -connect $HOST:443 -servername $HOST </dev/null 2>/dev/null \
    | grep -E 'Protocol|Cipher|Verify return code' | sed 's/^/  /'
echo

vach
echo "3. BANG SO SANH CAC LOAI REQUEST"
vach
probe "GET  / (trang chu)"                    "https://$HOST/"
probe "POST / (cung duong dan, doi method)"   -X POST -d '{}' "https://$HOST/"
probe "GET  /services/einvoiceapplication/"   "https://$HOST/services/einvoiceapplication/"
probe "GET  duong API day du"                 "$API_PATH"
probe "POST duong API day du"                 -X POST -H 'Content-Type: application/json' -d '{}' "$API_PATH"
probe "GET  duong API, ep HTTP/1.1 + TLS1.2"  --http1.1 --tlsv1.2 --tls-max 1.2 "$API_PATH"
probe "GET  duong API, User-Agent Chrome"     -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0' "$API_PATH"
echo

vach
echo "4. NGUYEN VAN LOI CUA CURL (duong API)"
vach
curl -sS -o /dev/null --max-time 30 "$API_PATH" 2>&1 | sed 's/^/  /'
echo

vach
echo "5. BAT TAY TLS THU CONG, GO TAY REQUEST HTTP"
vach
echo "  Bang chung nang nhat: khong qua curl, khong qua thu vien nao. Bat tay"
echo "  TLS bang openssl roi go thang request HTTP vao. So sanh hai truong hop:"
echo
echo "  --- 5a. Xin trang chu / ---"
printf 'GET / HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n' "$HOST" \
    | timeout 25 openssl s_client -connect $HOST:443 -servername $HOST -quiet 2>/dev/null \
    | head -5 | sed 's/^/    /'
echo
echo "  --- 5b. Xin duong API (chi khac moi dong dau) ---"
printf 'GET /services/einvoiceapplication/api/InvoiceAPI/InvoiceWS/createInvoice/0100109106-507 HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n\r\n' "$HOST" \
    | timeout 25 openssl s_client -connect $HOST:443 -servername $HOST -quiet 2>/dev/null \
    | head -5 | sed 's/^/    /'
echo "  (khong in ra dong nao = server dong ket noi ma khong tra loi gi)"
echo

vach
echo "CACH DOC KET QUA"
vach
cat <<'HUONG_DAN'
  - Muc 2 xong xuoi  => duong ra Internet va TLS phia server BINH THUONG.
  - Muc 3 ma "GET /" ra 200 con cac dong khac THAT BAI => server minh ra duoc
    toi Viettel, nhung Viettel tu choi phuc vu cac request di sau vao he thong.
  - Muc 5b im lang trong khi 5a tra ve "HTTP/1.1 200" => cung mot ket noi TLS,
    chi khac dong dau tien cua request, ma mot ben duoc tra loi, mot ben bi
    dong ket noi. Quyet dinh nay do dau kia dua ra sau khi da GIAI MA TLS,
    nen khong the do thiet bi mang trung gian phia minh.

  => Ket luan: khong phai loi cua ung dung, cung khong phai tuong lua cua minh.
     Can lam viec voi Viettel ve IP nguon o muc 1.

  Chay script nay tren SERVER THAT roi dat hai ket qua canh nhau: cung lenh,
  cung dich den, khac moi IP nguon.
HUONG_DAN
