# Chạy và sử dụng trên máy local

## Khởi động

```bash
./scripts/setup.sh
```

Ứng dụng: cổng **8000**. Hộp thư Mailpit: cổng **8025**. SMTP nội bộ: mailpit:1025. Không cần tên miền, thông tin ngân hàng hoặc merchant thật để kiểm thử. Các cổng web chỉ bind loopback; ba MySQL không publish port. Nếu đổi APP_PORT, cập nhật APP_URL cùng cổng để liên kết email đúng địa chỉ.

`backend/.env` là nơi lưu cấu hình và toàn bộ mật khẩu/khóa của bản local. Root `.env` là liên kết tới file này để Compose sử dụng cùng giá trị. Script tạo key ngẫu nhiên một lần, không in hoặc đặt lại credential đã tồn tại. File không nằm trong Git/gói bàn giao. Muốn chuyển cấu hình cũ sang bộ dịch vụ local, chạy `python3 scripts/configure.py --local` rồi khởi động lại app/worker.

## Đăng nhập quản trị và tạo nhân viên

- Mở `backend/.env` bằng trình soạn thảo riêng, xem `LOCAL_ADMIN_EMAIL` và `LOCAL_ADMIN_PASSWORD`.
- Đăng nhập ứng dụng. Lần đầu phải đổi mật khẩu tạm thời; từ lần sau dùng mật khẩu đã đổi. Chạy lại setup không ghi đè mật khẩu tài khoản.
- Tạo nhân viên trong **Quản lý tài khoản**, chọn vai trò và sân bay. Cấp mật khẩu tạm cho người thử qua kênh riêng. Nhân viên tự đổi mật khẩu lần đầu.
- Nếu đã có quản trị viên, bootstrap giữ nguyên tài khoản hiện tại. Không nâng quyền tài khoản có email trùng. Bootstrap chỉ chạy local, không chạy trong production.

## Tạo dữ liệu khai thác

Bản cài sạch không tự tạo chuyến/nhân sự giả. Khởi tạo dữ liệu qua giao diện:

1. Tạo nhân viên quản lý danh mục; thêm sân bay HAN/SGN, đường bay HAN→SGN và cửa A2 tại HAN. Có thể nhập CSV sân bay qua hàng chờ duyệt.
2. Tạo nhân viên quản lý đội tàu và điều phối tổ bay. Thêm tàu A320, sức chứa phù hợp; thêm ít nhất một phi công và một tiếp viên năng định A320, giấy phép còn hạn.
3. Tạo nhân viên khai thác và quản lý khai thác riêng, cùng sân bay HAN. Nhân viên lập lịch nháp, điều phối phân công tổ bay, nhân viên trình lịch, quản lý khác người lập phê duyệt.
4. Chuyến xuất hiện cho khách sau khi worker xác nhận mở bán. Để thử check-in, đặt giờ khởi hành cách thời điểm thử 45–120 phút; check-in đóng trước 30 phút, cửa lên tàu đóng trước 10 phút.

Các script kiểm thử tự dựng fixture trong DB test riêng; không cần nhập tay để chạy test. `AIRLINE_SAMPLE_DATA` mặc định false và luôn bị chặn trong production.

## Đăng ký, OTP và quên mật khẩu

Khách đăng ký bằng một địa chỉ thử nghiệm, ví dụ địa chỉ thuộc miền `.test`. Mở Mailpit, tìm thư gửi đến địa chỉ đó. Nhập OTP 6 số hoặc dùng liên kết xác minh. OTP có hiệu lực 10 phút, tối đa 5 lần thử; gửi lại sau ít nhất 60 giây, mã cũ hết hiệu lực. Không hiển thị OTP trong giao diện ứng dụng hoặc API.

Quên mật khẩu gửi liên kết đến Mailpit; token có hạn và chỉ dùng một lần. Sau các cập nhật đặt vé, thanh toán, thay đổi chuyến hoặc hỗ trợ, người dùng nhận thông báo trong ứng dụng và email. Worker mail riêng lưu job được mã hóa và thử lại khi SMTP lỗi.

## Thanh toán local

- Chọn **VNPay (thử nghiệm)** hoặc **MoMo (thử nghiệm)**. Mở trang thanh toán thử nghiệm, chọn thành công hoặc từ chối. Kết quả được ký/kiểm tra như callback của bộ kết nối, ghi tài chính rồi chuyển sự kiện để xuất vé. Không gọi cổng ngoài hoặc thu tiền thật.
- Chọn **Chuyển khoản ngân hàng (thử nghiệm)** để xem tài khoản giả được khai báo trong `.env`. Không chuyển tiền vào tài khoản này. Khách nhập mã giao dịch thử và bấm đã chuyển. Nhân viên tài chính đối chiếu trong **Hoàn tiền & đối soát**, nhập mã sao kê thử khác nhau cho mỗi khoản rồi xác nhận hoặc từ chối.
- Khoản thu lưu `mode=mock`; gửi lại cùng kết quả không tạo vé/khoản thu lần hai. Thay đổi số tiền từ trình duyệt không thay đổi giá đã chốt.
- Hoàn tiền cần thông tin tài khoản nhận hoàn thử nghiệm, phê duyệt tài chính và người khác xác nhận chi. Chưa xác nhận chi thì trạng thái vẫn chờ hoàn.

## Nâng cấp cấu hình sau này

Giữ nguyên logic nghiệp vụ, chuyển `PAYMENT_MODE=gateway` cùng merchant Sandbox/live và URL tương ứng; ngân hàng thật dùng `BANK_TRANSFER_MODE=live` cùng tài khoản được phép nhận tiền. SMTP dùng host/port/credential thật. Đổi APP_URL sang HTTPS, bật SESSION_SECURE_COOKIE, khai báo TRUSTED_PROXIES nếu có reverse proxy. Xem deployment.md. Không dùng dữ liệu thu/hoàn thử nghiệm làm sổ tiền thật; có thể chọn DB triển khai riêng qua cấu hình hiện có.

## Kiểm thử

```bash
./scripts/test.sh
./scripts/e2e.sh
```

Không chạy đồng thời hai lệnh hoặc restore. E2E có web, worker nghiệp vụ và worker mail riêng trên DB airline_test. Browser nhận OTP/reset/thông báo qua SMTP Mailpit thật và thực hiện thanh toán Mock/chuyển khoản trên giao diện; không có endpoint bỏ xác minh/cấp quyền công khai. Dịch vụ test tự dừng và xóa sau chạy; Mailpit giữ hộp thư local để xem.
