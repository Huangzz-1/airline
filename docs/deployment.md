# Thiết lập email và thanh toán

Các bộ kết nối SMTP, VNPay, MoMo và chuyển khoản đã có trong mã nguồn. Muốn nhận/gửi dữ liệu thật cần tài khoản nhà cung cấp, thông tin ngân hàng và tên miền HTTPS của đơn vị triển khai. Bản local đã dùng Mailpit, VNPay/MoMo Mock và ngân hàng giả, không cần các thông tin này. Xem local-usage.md. Kiểm thử Mock không thay thế nghiệm thu merchant thật.

## Cấu hình riêng của máy chủ

Điền các biến trong `backend/.env` theo `backend/.env.example`; không commit file hoặc gửi khóa bí mật qua chat. Giữ nguyên APP_KEY hiện tại. Nó bảo vệ phiên đăng nhập, thông báo email trong hàng đợi, QR và tài khoản nhận hoàn tiền được mã hóa.

- Đặt `APP_URL` bằng tên miền HTTPS được triển khai, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; khai báo `TRUSTED_PROXIES` bằng IP/CIDR proxy được tin cậy khi kết thúc TLS tại proxy. Dùng PHP-FPM/reverse proxy phù hợp khi phục vụ công khai; `artisan serve` trong Compose hiện tại dành cho workspace phát triển.
- Khi triển khai thật, đặt `PAYMENT_MODE=gateway`, `BANK_TRANSFER_MODE=live`, `AIRLINE_BOOTSTRAP_ADMIN=false`, `PAYMENT_SIMULATOR_ENABLED=false`, `AIRLINE_SAMPLE_DATA=false`. Chế độ production luôn từ chối simulator và không seed dữ liệu mẫu. Không tạo mặc định tài khoản đặc quyền.
- SMTP: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Dùng `MAIL_SCHEME=smtp`/587 với STARTTLS hoặc `smtps`/465 theo nhà cung cấp. Không tắt xác minh TLS. Đăng ký/xác minh và quên mật khẩu dùng hàng đợi mail được mã hóa, retry tối đa 5 lần.
- VNPay: `VNPAY_ENABLED=true`, `VNPAY_TMN_CODE`, `VNPAY_HASH_SECRET`, `VNPAY_URL`. URL live: `https://pay.vnpay.vn/vpcpay.html`; URL thử nghiệm: `https://sandbox.vnpayment.vn/paymentv2/vpcpay.html`. Đăng ký IPN `https://TEN_MIEN/payments/vnpay/ipn`, return `https://TEN_MIEN/payments/vnpay/return` trong cấu hình merchant.
- MoMo: `MOMO_ENABLED=true`, `MOMO_PARTNER_CODE`, `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY`, `MOMO_URL`. URL live: `https://payment.momo.vn`; thử nghiệm: `https://test-payment.momo.vn`. IPN `https://TEN_MIEN/payments/momo/ipn`, return `https://TEN_MIEN/payments/momo/return`. API tạo giao dịch dùng `captureWallet`, `autoCapture=true`.
- Chuyển khoản: `BANK_TRANSFER_ENABLED=true`, `BANK_NAME`, `BANK_ACCOUNT_NUMBER`, `BANK_ACCOUNT_NAME`. Hệ thống tạo nội dung `AS MA_DAT_CHO` và giữ nguyên số tiền/chỉ dẫn trong từng yêu cầu. Nhân viên kiểm tra sao kê thực trước khi xác nhận.

Trong cloud có giới hạn mạng, cần cho phép HTTPS đến `payment.momo.vn` và/hoặc `test-payment.momo.vn` theo môi trường đã chọn. SMTP cần quyền TCP đến host/port của nhà cung cấp. Không thay proxy hoặc đi vòng policy. Compose gắn CA bundle của máy chủ chỉ đọc; có thể chọn đường dẫn bằng `AIRLINE_CA_BUNDLE`, vẫn giữ xác minh TLS.

Sau khi cấu hình:

```bash
docker compose up -d db1 db2 db3 app worker mail-worker
docker compose restart -t 2 worker mail-worker
```

Nếu đổi biến do Compose inject hoặc bind mount, dùng `docker compose up -d --force-recreate app worker mail-worker`. Trước khi mở bán, quản trị viên xem **Email & thanh toán**. Nhãn “Đã có cấu hình” chỉ kiểm tra thông tin đã được khai báo, không thay thế giao dịch xác minh với nhà cung cấp.

## Quy trình đối chiếu và hoàn tiền

1. Chuyển khoản của khách chưa làm xuất vé. Tài chính đối chiếu đúng mã đặt chỗ, số tiền và nội dung trên tài khoản nhận; nhập mã giao dịch sao kê rồi xác nhận. Mã giao dịch không dùng cho hai đặt chỗ.
2. VNPay/MoMo chỉ ghi nhận kết quả sau IPN có chữ ký hợp lệ, đúng merchant, mã yêu cầu và số tiền. Trang return từ trình duyệt không xác nhận thu tiền. IPN được trả lời sau commit tài chính; worker tiếp tục xuất vé qua outbox. Callback lặp không thu/phát vé hai lần. Lưu mã yêu cầu trước khi gọi nhà cung cấp để không mất dấu nếu timeout.
3. Đổi vé đã trả cần tài chính duyệt; khách thực hiện thanh toán vé mới trên hành trình mới. Vé cũ chỉ đi vào quy trình hoàn sau khi vé mới được thanh toán. Chưa có kết quả thì giữ trạng thái chờ; không tự coi timeout là đã thu hoặc đã hoàn.
4. Hoàn tiền thật được xử lý **qua chuyển khoản ngân hàng do tài chính thực hiện**, kể cả vé mua bằng VNPay/MoMo. Khách cung cấp tài khoản nhận hoàn trong hành trình; dữ liệu được mã hóa. Tài chính duyệt, người khác kiểm tra giao dịch chi trên sao kê và nhập mã xác nhận chi. Chỉ lúc đó sổ hoàn và trạng thái hành trình mới là đã hoàn. Không có API hoàn tự động trực tiếp vào ví/thẻ.
5. Hủy chuyến hoặc đổi vé cũng không tự ghi hoàn tiền thật thành công. Khoản hoàn chờ thông tin nhận tiền và xác nhận chi theo quy trình trên. Thay đổi tài khoản nhận hoàn phải phê duyệt lại.

Nếu mất phản hồi tạo thanh toán, hệ thống thử lại với cùng order/request ID; không tạo đơn thu khác. Nếu nhà cung cấp trả mã đơn trùng nhưng chưa gửi IPN hoặc không trả lại URL, khoản thanh toán vẫn chờ để đối chiếu với merchant/yêu cầu gửi lại IPN, không tự phát vé. Cần nghiệm thu những tình huống này với tài khoản merchant thật trước khi mở bán.

## Kiểm tra trước vận hành

Gửi email xác minh/đặt lại mật khẩu đến địa chỉ do đơn vị kiểm soát; xác minh link, hạn và xử lý lại mail lỗi. Với từng merchant đã cấu hình, thực hiện giao dịch thành công/từ chối, thử IPN gửi lại, sai số tiền và mất kết nối; đối chiếu booking, vé, khoản thu và sao kê. Kiểm tra quy trình hoàn qua ngân hàng với hai tài khoản tài chính. Không chạy bài kiểm tra bằng tiền thật khi chưa được chủ tài khoản cho phép.

Khả năng khai thác ngoài phạm vi phần mềm nội bộ như AODB/FIDS/BHS, chứng nhận nghiệp vụ, HA, tải và DR cần nghiệm thu riêng. Xem `implementation-progress.md`; không lấy số lượng module hoặc kiểm thử cục bộ làm bằng chứng nghiệm thu hàng không.
