# QR chuyển khoản ngân hàng

Phương thức `BANK` có thể nhận chuyển khoản thật với nhân viên tài chính đối chiếu sao kê. QR được sinh tại backend bằng thư viện BaconQrCode đã có, không gửi thông tin tài khoản hoặc mã đặt chỗ tới dịch vụ tạo ảnh bên ngoài.

Cấu hình riêng trong `backend/.env`:

```dotenv
BANK_TRANSFER_ENABLED=true
BANK_TRANSFER_MODE=live
BANK_NAME="TEN NGAN HANG"
BANK_BIN=970415
BANK_ACCOUNT_NUMBER="SO TAI KHOAN NHAN"
BANK_ACCOUNT_NAME="TEN CHU TAI KHOAN"
```

`BANK_BIN` là mã NAPAS gồm sáu chữ số; ví dụ `970415` là VietinBank. Không đưa tài khoản nhận tiền cá nhân vào `.env.example`, seeder hoặc tài liệu Git. Sau khi thay cấu hình, chạy `php artisan config:clear` và khởi động lại web/worker.

Mỗi yêu cầu mới lưu ngân hàng, BIN, số tài khoản, chủ tài khoản và nội dung `AS <mã đặt chỗ>` vào snapshot thanh toán. QR đọc từ snapshot và số tiền phải thanh toán; PNR nhiều người/nhiều chặng sử dụng tổng tiền cả hành trình. Đổi cấu hình không sửa người nhận trong yêu cầu cũ. Snapshot cũ chưa có BIN vẫn hiện hướng dẫn chuyển khoản; chế độ `mock` không hiển thị QR nhận tiền thật.

Khách quét QR bằng ứng dụng ngân hàng, kiểm tra người nhận, số tiền và nội dung, rồi thực hiện chuyển tiền. Nút **Tôi đã chuyển khoản** chỉ gửi báo cáo: nhân viên tài chính vẫn phải kiểm tra sao kê và nhập mã giao dịch thực trước khi xác nhận. Sinh QR không cung cấp thông báo tiền vào hoặc tự xác nhận thanh toán; cần thêm tích hợp webhook ngân hàng/payOS/SePay để tự động đối soát. QR này không phải lệnh chuyển tiền hay xác nhận đã nhận tiền, cũng không thu hồi được ở ngân hàng khi giữ chỗ hết hạn.

`MOMO_PERSONAL_PHONE` chỉ lưu số cá nhân và hiển thị cho quản trị viên trong **Email & thanh toán**. Số điện thoại không thay thế `MOMO_PARTNER_CODE`, `MOMO_ACCESS_KEY`, `MOMO_SECRET_KEY`; chỉ bật `MOMO_ENABLED` khi thông tin merchant và môi trường đã được cấu hình. Không tự tạo QR MoMo từ số điện thoại.

Tham khảo: [VietQR Generate và mẫu payload](https://www.vietqr.io/generate), [danh sách BIN ngân hàng](https://www.vietqr.io/api-bank/demo), [thông tin tích hợp MoMo for Business](https://developers.momo.vn/v3/docs/payment/api/other/postman/).

Kiểm tra: `BankTransferQrTest` đối chiếu payload/checksum với mẫu công bố, kiểm tra dữ liệu không hợp lệ và không hiển thị QR live ở mock; `BankTransferQrCheckoutTest` kiểm tra snapshot, tổng tiền nhóm và báo cáo chuyển khoản không tự xuất vé. Lượt kiểm tra liên quan gồm 13 tests/53 assertions. Đã giải mã ảnh QR từ trang thanh toán trên trình duyệt ở desktop và màn hình 390 px với tài khoản tổng hợp trong `airline_test`: BIN, tài khoản, tổng tiền và nội dung đều khớp. Chưa thử chuyển tiền thực hoặc xác nhận bằng ứng dụng ngân hàng.
