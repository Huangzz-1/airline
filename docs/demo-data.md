# Bộ dữ liệu thử nghiệm sân bay

**Bổ sung nhiều hãng:** [1.008 chuyến trong 14 ngày](multi-airline-demo.md), Vietnam Airlines/Vietjet/Bamboo/Vietravel, có bay thẳng, 1–2 điểm dừng và khứ hồi. Lệnh `php artisan airline:demo-flights` chỉ bổ sung lịch/tồn/hạng giá, không tạo giao dịch và không yêu cầu đổi cấu hình thanh toán.

Để thử các chức năng mới **nhiều hành khách, khứ hồi/nhiều chặng và cabin theo tàu**, chạy thêm `php artisan airline:demo-reservations` trong `backend`. Lệnh tạo ba tàu có sơ đồ riêng, sáu chuyến AS9700–AS9705 và hai PNR đã thanh toán. Mở `/plan` để đặt mới; xem [hướng dẫn đầy đủ](reservations-and-cabins.md). Mã PNR và liên kết mẫu nằm trong `backend/storage/app/private/demo/reservations.json`.

Bộ dữ liệu này dành cho thử các chức năng hiện có của Airline Smart trên máy local. Tên sân bay là danh mục tham khảo; hành khách, nhân viên, chuyến bay, mã tàu, thời gian bay, khoảng cách, giá vé và giao dịch đều được mô phỏng. Sơ đồ ghế tuân theo mô hình sáu ghế mỗi hàng hiện có của ứng dụng.

## Tạo dữ liệu

Sau khi khởi chạy và migrate ứng dụng, thêm `LOCAL_DEMO_PASSWORD` vào `backend/.env` với một mật khẩu thử nghiệm riêng dài ít nhất 16 ký tự, rồi chạy:

```bash
cd backend
php artisan airline:demo-data
```

Trên workspace Windows đã chuẩn bị PHP riêng:

```powershell
cd airline\backend
& '..\..\.runtime\php\php.exe' artisan airline:demo-data
```

Lệnh yêu cầu môi trường `local`, cả hai chế độ thanh toán là `mock`, và SMTP là Mailpit local. Không cần bật `AIRLINE_SAMPLE_DATA` hoặc bộ giả lập thanh toán cũ. Lệnh không xóa bảng, không chạy `migrate:fresh`, không đổi mật khẩu hay quyền của tài khoản cũ. Chạy lại sau khi hoàn tất chỉ báo vị trí tài liệu, giữ nguyên mọi kết quả thử nghiệm. Nếu bị gián đoạn, chạy lại để tiếp tục những bước chưa hoàn tất. Đây là nhập fixture vào ba cơ sở dữ liệu, không phải một giao dịch nguyên tử trên cả ba máy chủ.

## Dữ liệu được tạo

- 22 sân bay Việt Nam, 44 đường bay hai chiều và danh mục nhà ga, cửa, quầy, vị trí đỗ.
- 30 tàu bay với các loại A320, A321, B787, A350, ATR72; có tàu dự phòng, đang bảo dưỡng và đã giải phóng kỹ thuật.
- Tổ bay có hai phi công và bốn tiếp viên cho mỗi tổ, cùng tổ dự phòng, hồ sơ sắp hết hạn và đã hết hạn giấy phép.
- Lịch bay gồm bảy ngày lịch sử, ngày hiện tại và 14 ngày tiếp theo; 22 vòng bay khứ hồi mỗi ngày phục vụ toàn bộ 22 sân bay, có thời gian quay đầu và tổ riêng cho mỗi chiều. Có thêm chuyến nháp, chờ duyệt, đang boarding, đóng cửa, đang bay, đã đến, chậm và hủy.
- Ba hạng giá mỗi chuyến, hạn mức chỗ, hành khách, vé đã phát hành, ghế và QR được mã hóa, phiên thanh toán, khoản thu và sổ tiền tương ứng.
- Hành lý có đầy đủ lịch sử tiếp nhận, soi chiếu, xếp tàu, trả khách, trung chuyển và thất lạc; nhiệm vụ mặt đất và phân bổ cửa, quầy, vị trí đỗ.
- Hồ sơ đổi vé, hoàn tiền, khoản chuyển chờ đối chiếu; yêu cầu hỗ trợ có hội thoại; kỳ đối soát mở và đã khóa.
- Đề nghị sửa danh mục, thay tàu/tổ bay và gián đoạn chờ duyệt; thiết bị mô phỏng có cảnh báo và số liệu đến trễ; nhật ký thao tác trên các node.

Số lượng chính xác, mã đặt chỗ, thẻ hành lý, ID hồ sơ và liên kết hành trình nằm trong `backend/storage/app/private/demo/report.md`. Danh sách toàn bộ tài khoản ở `accounts.csv`, dữ liệu tình huống ở `scenarios.json` trong cùng thư mục. Các file này được Git bỏ qua và không chứa mật khẩu.

## Tài khoản để thử

Mật khẩu chung của tài khoản mới nằm trong `LOCAL_DEMO_PASSWORD` tại `backend/.env`. Tài khoản quản trị ban đầu giữ nguyên thông tin đăng nhập cũ.

| Email | Chức năng chính |
|---|---|
| `demo.passenger@airline.test` | Đặt vé, thanh toán, check-in, xem vé/QR, hoàn tiền |
| `demo.passenger.2@airline.test` | Đổi vé, vé đã boarding, hoàn vé cũ |
| `demo.passenger.3@airline.test` | Hành lý thất lạc, vé chuyến bị hủy |
| `demo.airport_agent.han@airline.test` | Phục vụ khách, check-in, quét QR, hành lý tại HAN |
| `demo.airport_agent.sgn@airline.test` | Kiểm tra giới hạn sân bay; bàn giao tại SGN |
| `demo.operations.han@airline.test` | Lập/trình lịch, giao việc, tài nguyên, gián đoạn |
| `demo.ops_manager.han@airline.test` | Duyệt lịch, thay tàu/tổ bay và gián đoạn |
| `demo.finance@airline.test` | Đối chiếu chuyển khoản, duyệt hoàn/đổi, tạo kỳ đối soát |
| `demo.finance.2@airline.test` | Người duyệt/chi hoàn và khóa kỳ độc lập |
| `demo.data_admin@airline.test` | Sân bay, đường bay, tài nguyên, giá và hạn mức |
| `demo.data_admin.2@airline.test` | Duyệt thay đổi danh mục do người khác đề nghị |
| `demo.fleet_manager@airline.test` | Hồ sơ và cấu hình tàu bay |
| `demo.maintenance@airline.test` | Mở lệnh bảo dưỡng |
| `demo.maintenance.2@airline.test` | Hoàn tất lệnh bảo dưỡng |
| `demo.maintenance.3@airline.test` | Giải phóng kỹ thuật độc lập |
| `demo.crew_scheduler@airline.test` | Hồ sơ, giấy phép, phân công và thay tổ bay |
| `demo.ground_agent.han@airline.test` | Thực hiện nhiệm vụ mặt đất được giao |
| `demo.customer_service@airline.test` | Phản hồi hỗ trợ, xem hội thoại |
| `demo.analyst@airline.test` | KPI, thống kê và dự báo mô phỏng |
| `demo.integration_admin.han@airline.test` | Thiết bị, cảnh báo, số liệu và kết nối |
| `demo.auditor@airline.test` | Nhật ký kiểm toán |
| `demo.admin@airline.test` | Tài khoản, phân quyền, khóa/mở và cấu hình dịch vụ |

Các vai trò gắn sân bay đều có tài khoản tại 22 sân bay, theo mẫu `demo.VAI_TRO.ma_san_bay@airline.test`, mã sân bay viết thường. Các email `demo.p0001@airline.test` đến `demo.p0720@airline.test` là hành khách có lịch sử vé. Một quản trị viên không có toàn bộ quyền nghiệp vụ; hãy đăng nhập bằng đúng vai trò trong bảng.

Tình huống tài khoản đặc biệt:

- `demo.passenger.unverified@airline.test`: chưa xác minh email, dùng Mailpit để nhận OTP.
- `demo.staff.temporary@airline.test`: phải đổi mật khẩu tạm trước khi sử dụng nghiệp vụ.
- `demo.staff.disabled@airline.test`: đang bị khóa, dùng quản trị để thử mở lại.

## Bắt đầu thử

1. Mở http://localhost:8000, đăng nhập hành khách chính. Vào **Hành trình của tôi**, mở vé của AS9902 để chọn ghế/check-in, in vé và thẻ lên tàu. Hoặc tìm chuyến mới theo HAN → SGN, chọn ngày trong 14 ngày tới, giữ chỗ rồi thử VNPay/MoMo hoặc chuyển khoản.
2. Mở AS9901 ở cùng tài khoản, xem thẻ lên tàu và mã hành lý. Đăng nhập nhân viên sân bay HAN để thử quét QR, tiếp nhận/soi chiếu/xếp hành lý và tra cứu hành khách theo mã đặt chỗ trong báo cáo.
3. Đăng nhập tài chính để xem khoản chuyển đang chờ; với hồ sơ hoàn đã duyệt, dùng người thứ hai xác nhận chi bằng mã giao dịch thử khác nhau. Có cả hoàn đã xong và bị từ chối để đối chiếu.
4. Dùng tài khoản khai thác và quản lý khai thác riêng: tìm AS9910 (nháp), AS9911 (chờ duyệt), AS9920/AS9921 (đề nghị chậm/hủy), AS9940 (đã hủy), AS9941 (đã chậm). Phương án thay tàu/tổ bay có sẵn trong **Đổi tàu & tổ bay**.
5. Các vai trò khác vào **Bàn làm việc** và chọn phân hệ được cấp quyền. Báo cáo riêng ghi ID lệnh, yêu cầu hỗ trợ, phương án, kỳ đối soát và thẻ hành lý cụ thể.

## Giới hạn thời gian khi thử

AS9901 được tạo cách giờ khởi hành 55 phút để thử boarding ngay: cửa nhận QR từ -60 đến -10 phút, check-in đóng trước 30 phút. AS9902 được tạo cách giờ khởi hành tám giờ, trong cửa sổ check-in 24 giờ. Vé chưa thanh toán và giữ chỗ cho đổi vé hết hạn sau 10 phút; nếu hết hạn, tạo yêu cầu mới từ một vé đã xác nhận. Thời điểm tạo chính xác nằm trong báo cáo riêng. Những lịch này tuân theo đồng hồ thực, không tự kéo dài khi chạy lại seeder.

Thanh toán và email tiếp tục dùng cấu hình local. Mailpit tại http://localhost:8025 nhận thông báo, OTP và khôi phục mật khẩu. Đăng ký tài khoản mới vẫn thực hiện quy trình xác minh email bình thường.
