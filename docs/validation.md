# Kiểm chứng thực thi — 2026-10-30

## Lượt kiểm tra cuối

- **88 PHP tests passed, 780 assertions**, 153.55 giây, không skipped/disabled: 78 integration tests trên ba MySQL instance và 10 trường hợp biên SeatMap.
- **11 browser tests passed**, 1.9 phút, Chromium hệ thống, CSRF thật. Giao diện không còn banner mô phỏng, số module hoặc trường nhập UUID trong thao tác thông thường.
- PHP lint, Blade compile và PHP lint view đã biên dịch, Pint, cú pháp JavaScript, bash -n, Compose config và git diff --check đã qua.
- Health HTTP 200/status=ok. App, worker nghiệp vụ, worker email và ba MySQL instance đang chạy; dịch vụ web/worker test được dừng và xóa.
- Backup/restore **49 bảng** khớp counts và tham chiếu inventory→flight; dữ liệu ứng dụng trước/sau restore không đổi.

## Kiểm tra chức năng và quyền

`JourneyTest` và `OperationsWorkflowTest`: topology/quyền DB test; signup không nâng quyền; xác minh signed URL, reset một lần; hold/expiry/cancel, hai tiến trình tranh ghế cuối; sandbox chỉ trong test, decline/network retry; check-in/QR/boarding, ownership/role/airport/time windows; lịch/crew/approval/batch, license/rest/conflict, master revision/CSV staging và rollback; MRO, tài nguyên, ground SLA, baggage/custody, đổi/hoàn, hủy trong lúc chờ thanh toán, reconciliation/lock kỳ/CSV; IoT trùng/trễ và KPI; hỗ trợ append-only và trang workspace theo vai trò.

`FlightResourceChangeTest` (12): đổi tàu prepare/commit/ack trên node thật, giữ hai tàu/freeze tồn, remap/cấp QR mới, thiếu chỗ giữ nguyên phương án cũ, phân quyền/stale/reject, chặn MRO/sửa cấu hình/publish xung đột, replay không xoay QR lần hai, đóng bán thủ công, DB1/DB2 mất TCP và khôi phục, expiry không chặn relay, thay tổ bay/lịch sử inactive, giữ ghế hợp lệ trước remap, payment pending và phương án hủy không ghi đè swap.

`LocalServicesTest` (6): OTP giới hạn thử/hạn/resend/mã cũ, không lộ mã qua trang xác minh; Mock VNPay ownership/server amount/kết quả ký/idempotency; MoMo decline và capture; production từ chối mock; bootstrap không ghi đè admin hoặc chạy production.

`UsablePortalTest` (7): chứng từ chỉ cho chủ/agent đúng sân bay và vé hợp lệ; không in QR thu hồi; tra cứu/làm thủ tục theo sân bay và audit nhân viên thực; chọn mã đặt chỗ của chính mình, giao diện dùng tên nghiệp vụ; admin tạo nhân viên, bắt đổi mật khẩu, khóa/mở và thu hồi truy cập, không tự khóa; đăng ký vẫn xếp mail được mã hóa khi SMTP chưa truy cập được; đánh dấu thông báo tại chỗ có ownership.

`PaymentGatewayTest` (9): simulator bị chặn khi tắt, merchant chưa cấu hình không làm đổi booking; báo chuyển khoản không xuất vé, tài chính xác nhận theo sao kê, idempotency/mã chuyển khoản không dùng hai lần; VNPay ký đúng query và kiểm tra IPN/amount, return không capture; MoMo ký yêu cầu và kiểm tra IPN/amount/replay; timeout giữ pending và dùng cùng order ID; callback thành công vẫn được ghi nhận khi phản hồi tạo thanh toán bị mất; đổi vé thật chờ khách trả vé mới, hoàn vé cũ chờ chi; hủy chuyến không ghi hoàn thật tự động; tài khoản nhận hoàn được mã hóa, phải duyệt và người khác xác nhận chi.

Các lần mô phỏng mất TCP dùng cổng local không lắng nghe rồi khôi phục cấu hình đến DB thật, không giả lập DB thành công. Provider HTTP và IPN dùng **khóa/payload kiểm thử**, MoMo dùng Http::fake, không phát sinh giao dịch thật. SMTP Mailpit đã gửi/nhận thực trên Docker network; browser đọc OTP, reset link và thông báo từ hộp thư local. Chưa dùng SMTP/merchant thật, đúng phạm vi kiểm thử local được yêu cầu. Cần nghiệm thu merchant/SMTP theo deployment.md khi có tài khoản của đơn vị triển khai.

## Browser coverage

1. Signup → nhận OTP từ email SMTP Mailpit và nhập trên UI → giữ chỗ → chuyển khoản → khách báo giao dịch → finance đăng nhập độc lập, đối chiếu và xác nhận → vé xuất → chọn ghế → QR → agent boarding; quét lặp bị từ chối; thiếu CSRF bị 419. Tài khoản ngân hàng trong DB test được ghi rõ là kiểm thử.
2. Mobile 390×844 không tràn ngang; lọc không có chuyến có empty state.
3. Fleet thêm tàu → operations lập nháp → crew thêm và phân công pilot/cabin → trình duyệt → ops_manager công bố → inventory thực và chuyến xuất hiện công khai.
4. Staff chọn mã đặt chỗ để tag/scan, Accepted→Screened→Loaded; custody 4 sự kiện; khách tra cứu đúng bag của mình.
5. Khách gửi yêu cầu → CSKH phản hồi/resolved → khách đọc lịch sử đầy đủ.
6. Quên mật khẩu nhận email SMTP qua Mailpit và dùng reset link đúng một lần.
7. VNPay Mock đi hết thanh toán, khoản thu, vé và email thông báo qua Mailpit.
8. MoMo Mock đi hết thanh toán, khoản thu, vé và email thông báo qua Mailpit.
9. Admin tạo nhân viên qua form → đăng nhập lần đầu bắt đổi mật khẩu → bàn làm việc đúng vai trò, không có thao tác quản trị ngoài quyền.
10. Agent tra cứu mã đặt chỗ → chọn ghế → làm thủ tục → in thẻ QR, tạo PDF bằng chế độ print của trình duyệt.
11. Operations đề nghị đổi tàu 18→12 → manager duyệt → worker thật hoàn tất → remap 3F→1A → khách nhận QR mới, agent từ chối mã cũ và nhận mã mới.

E2E dùng web port 8001, APP_ENV=local, user/schema airline_test, worker nghiệp vụ và worker mail riêng. Helper chỉ tạo tài khoản trong DB test; không có endpoint public để bỏ xác minh hoặc cấp admin. Simulator cũ chỉ dùng fixture ngoài luồng trình duyệt; luồng khách kiểm tra chuyển khoản và VNPay/MoMo Mock bằng giao diện, không gọi cổng ngoài. Các lượt mới đọc OTP/reset qua SMTP thực, không tạo signed URL bằng helper để bỏ qua email. Camera phụ thuộc BarcodeDetector/HTTPS/quyền thiết bị; chưa có bài kiểm tra camera vật lý.

Ảnh/PDF thật từ browser: [bàn làm việc](screenshots/staff-dashboard.png), [phục vụ hành khách](screenshots/passenger-desk.png), [thẻ lên tàu](screenshots/boarding-pass.png), [PDF](screenshots/boarding-pass.pdf), [đổi tàu](screenshots/aircraft-change.png).

## Dữ liệu ứng dụng và sao lưu

Fixture onboarding cũ được sao lưu trước khi dọn. Công cụ cleanup chỉ chạy local, đòi hỏi không có users/bookings/giao dịch/audit/outbox hoặc thay đổi nghiệp vụ và kiểm tra danh tính fixture. Đã xóa 7 chuyến/inventory, 2 tàu, 2 nhân sự, 1 lệnh và 1 thiết bị mẫu; giữ 3 sân bay, 2 đường bay, 12 tài nguyên nền. Không xóa dữ liệu người dùng. Seed mẫu mặc định tắt; production luôn không seed mẫu. Sau đó bootstrap đã tạo quản trị viên local duy nhất từ credential ngẫu nhiên trong backend/.env; mật khẩu bắt đổi lần đầu, không được in ra hoặc đóng gói. Test bật fixture ở tiến trình test.

Sau cleanup, backup ba node được phục hồi bằng user hạn chế vào **airline_test**. Counts cả 49 bảng và tham chiếu inventory→flight trùng baseline, main trước/sau không đổi: **1 admin local, 0 bookings, 0 flights, 14 migrations**. Các bảng thanh toán/đổi tàu trong main đang trống, nên lần restore này xác minh schema/counts/invariant, không chứng minh khôi phục Saga đang chạy hoặc mọi giá trị bit-for-bit. Không globally atomic, chưa là nghiệm thu DR/RTO/RPO. APP_KEY phải giữ riêng; backup không nằm trong Git/gói mã nguồn.

Ba server UUID khác nhau: DB1 c90469db-d438-11f1-9c29-16e2073315b4; DB2 c8efa380-d438-11f1-8395-327815a736bb; DB3 c90ec26e-d438-11f1-a42c-16356d1c0b01. Test chỉ yêu cầu độc lập, không hardcode các ID.

Hướng dẫn startup môi trường đã lưu bản nháp, chưa Publish snapshot. Các giới hạn nghiệp vụ/triển khai được ghi trong implementation-progress.md và deployment.md; số kiểm thử không thay thế nghiệm thu hàng không hoặc xác minh kết nối nhà cung cấp thật.

## Setup và cấu hình local

Đã chạy lại scripts/setup.sh thành công sau khi thêm Mailpit/Mock/OTP. Byte hash của backend/.env trước/sau giữ nguyên, credential và APP_KEY không đổi; admin có sẵn không bị đặt lại mật khẩu. Đã kiểm tra migration root .env cũ sang symlink backend/.env bằng thư mục tạm, giữ credential Compose đang có hiệu lực và không ghi đè sau chạy lần hai.

Mailpit v1.32.0 được pin bằng image digest, nhận SMTP local thực; cả VNPay, MoMo và BANK ở chế độ thử nghiệm đã sẵn sàng. Các khoản checkout/payment có trường mode. Không có giao dịch thật. Bản local dùng ba MySQL instance cho nghiệp vụ, Mailpit chỉ là hộp thư thử nghiệm phụ trợ.

Sau thay đổi nhãn chứng từ, hai E2E portal được chạy lại và qua (13.2 giây); ảnh/PDF đã tạo lại.
