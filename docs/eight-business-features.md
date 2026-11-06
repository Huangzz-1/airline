# Tám nhóm nghiệp vụ bổ sung

Migration: `2026_11_03_010000_eight_business_features.php`, áp dụng trên cả ba kết nối theo cơ chế hiện có. Worker `php artisan airline:work` xử lý kết quả thanh toán/hoàn, thông báo và hồ sơ hỗ trợ quá hạn. Đặt mới vẫn chỉ có **một hành khách**, một chiều hoặc khứ hồi; chuyến nối là vé thành phần trong PNR.

| Nhóm | Giao diện / vai trò | Đã triển khai |
|---|---|---|
| 1. Sửa thông tin khách | Khách: `/reservations/{id}/details`; CS: `/workspace/passenger-revisions` | Trước thanh toán cập nhật ngay; sau xuất vé chờ CS duyệt/từ chối. Có so sánh trước/sau, lý do, phản hồi và lịch sử mã hóa. Đổi định danh thu hồi QR và yêu cầu check-in lại. |
| 2. Hành lý và mua thêm | Trang PNR/vé; luồng tag cho nhân viên hành lý | Hiển thị định mức ký gửi; gói thêm 5–40 kg trước checkout, cập nhật tổng tiền. Tối đa 64 kg định mức tổng, 32 kg/kiện; từ chối tag vượt tổng đã mua. |
| 3. Điều kiện đổi/hoàn | Data admin: `/workspace/fare-rules`; khách xem trước đặt và trên vé | Cho phép đổi/hoàn, phí, hạn thao tác, định mức và đơn giá hành lý. Snapshot trên từng vé; chính sách mới không đổi vé đã đặt. Đổi gồm giá mới + hành lý + phí đổi; hoàn tự nguyện trả sau phí. |
| 4. Sao kê và điều chỉnh | Finance: `/workspace/bank-statements`, `/workspace/financial-adjustments` | Nhập CSV, ghép mã/số tiền, nhận diện thiếu/thừa/không khớp/trùng. Nhân viên kiểm tra chứng từ rồi xác nhận thu. Điều chỉnh sổ cần lý do, kiểm tra phiên bản và người khác duyệt; giữ bút toán thu/hoàn gốc. |
| 5. Báo cáo doanh thu | Finance, analyst, admin: `/workspace/revenue` | Lọc ngày/đường bay, nhóm ngày/tháng/đường bay; thu, hoàn, điều chỉnh, thu ròng; xuất CSV mở bằng Excel. Theo ngày giao dịch ghi sổ ở Việt Nam. |
| 6. Check-in và danh sách lên tàu | Airport agent, operations, ops manager: `/workspace/boarding-control` | Mở/khóa/theo lịch, giờ tùy chỉnh và lý do, giới hạn sân bay. Chốt sau đóng cửa boarding, xuất CSV, phiên bản mã hóa bất biến; dữ liệu đổi phải chốt lại trước khởi hành. |
| 7. Phân công hỗ trợ | CS: `/workspace/support-desk`; khách: `/support/{id}` | Giao nhân viên, ưu tiên, hạn, lọc quá hạn và lịch sử. Chỉ người được giao xử lý hồ sơ đã phân công. Worker nâng quá hạn lên khẩn cấp và báo nhóm hỗ trợ một lần cho mỗi lần giao hạn. |
| 8. Khách bị gián đoạn | Operations/ops manager: `/workspace/passenger-recovery`; khách: `/recovery-offers/{id}` | Xem khách, chuyến nối, tổ bay, tài nguyên, nhiệm vụ; đề xuất chuyến còn chỗ, quản lý khác duyệt rồi thông báo khách. Khách chủ động gửi yêu cầu đổi hoặc đặt mới. |

## Thử trên giao diện

1. Đặt vé đến trang PNR, mở sửa thông tin; chọn hành lý trước thanh toán và kiểm tra tổng. Sau xuất vé, yêu cầu sửa chuyển sang hồ sơ CS duyệt.
2. Data admin sửa điều kiện của một hạng giá. Đặt vé mới để thấy chính sách mới; vé trước đó giữ điều kiện đã ghi nhận.
3. Tạo chuyển khoản ngân hàng, dùng đúng nội dung/mã thanh toán hiển thị làm `booking_reference` trong CSV bên dưới. Finance nhập, xem phân loại, kiểm tra giao dịch ngân hàng rồi xác nhận đã thu.
4. Finance A lập điều chỉnh; finance B duyệt. Mở báo cáo theo đường bay và xuất CSV để đối chiếu.
5. Nhân viên sân bay khóa check-in, kiểm tra khách không thể làm thủ tục, rồi mở lại. Sau boarding/no-show và đóng cửa, chốt danh sách, xem/xuất từng phiên bản.
6. Khách tạo hỗ trợ; CS phân công và đặt hạn; người được giao trả lời/giải quyết. Worker theo dõi quá hạn và nâng ưu tiên.
7. Đi qua quy trình chậm/hủy chuyến hiện có, mở Hành khách bị gián đoạn. Operations lập phương án, ops manager khác duyệt. Khách mở thông báo và tiếp tục.

## CSV sao kê

UTF-8, tùy chọn BOM, tối đa 1 MB/2.000 dòng. Đủ năm cột; thứ tự có thể khác. Giao diện có mẫu trống.

```csv
transaction_ref,booking_reference,amount,currency,booked_at
BANK-DEMO-000001,MA_THANH_TOAN,1590000,VND,2026-11-03 14:30:00
```

Mã giao dịch dài 6–120 ký tự, duy nhất trong cùng nguồn `source`. Số tiền nguyên VND dương, không dấu phân cách hàng nghìn. Ngày theo giờ Việt Nam; tương lai quá 5 phút bị từ chối. Nhập cùng tệp/giao dịch không cộng thu lần nữa. Cùng mã giao dịch nhưng khác dữ liệu từ chối toàn bộ tệp mới. Thiếu/thừa/sai mã không thể xác nhận xuất vé. Nhập tệp chưa tạo khoản thu: phải kiểm tra và xác nhận; chưa kết nối tự động đọc tài khoản ngân hàng.

## Quy tắc và giới hạn

- Mặc định trong `FareRules::DEFAULTS` phục vụ chạy thử: 20 kg ký gửi, mua thêm 30.000 VND/kg, phí đổi/hoàn 0, hạn 30 phút. Cần cấu hình điều kiện kinh doanh thực tế; đây không phải chính sách công bố của một hãng hàng không.
- Mua thêm chỉ **trước khi bắt đầu checkout**. Giá trên vé tách giá cơ bản, hành lý và phí đổi. Vé cũ thiếu snapshot hành lý được ghi rõ để hỗ trợ kiểm tra, không tự gán định mức.
- Hoàn do đổi/hủy chuyến đi qua hồ sơ hoàn hiện có. Khoản thu qua ngân hàng/cổng cần tài khoản nhận, duyệt và xác nhận chi độc lập; thông báo hoàn không đồng nghĩa tự chuyển tiền ra ngân hàng.
- Check-in tự động mặc định từ trước bay 24 giờ đến trước bay 30 phút. Khung tùy chỉnh không được vượt mốc an toàn trước bay 10 phút. Chốt danh sách chỉ khi đã đóng cửa/khởi hành/đến và không còn khách check-in chưa xử lý. Chuyến cũ chưa từng chốt không bị ép hồi tố; chuyến đã chốt phải có phiên bản khớp trước khi khởi hành.
- Hỗ trợ mặc định 24 giờ cho khiếu nại/hành lý, 48 giờ cho trợ giúp; CS có thể điều chỉnh với lý do. Phản hồi không tự kéo dài hạn.
- Phương án thay thế: **bay thẳng cùng điểm đi/đến trong 7 ngày**, tối thiểu 60 phút với các vé liên quan. Chưa tối ưu lại toàn bộ chuỗi nối chuyến. Kiểm tra lại giá, trạng thái, chỗ khi duyệt và khách tiếp tục; không tự giữ chỗ/thu tiền/miễn điều kiện vé. Cùng hạng đủ điều kiện gửi yêu cầu đổi chờ finance; khác hạng hoặc không đủ điều kiện mở luồng đặt mới.
- Báo cáo tối đa 366 ngày mỗi lần; xuất CSV mở bằng Excel, chưa tạo `.xlsx`. Thu ròng là thu vé trừ hoàn cộng điều chỉnh đã duyệt; không phải báo cáo lợi nhuận.

## Kiểm chứng

`backend/tests/Feature/BusinessFeaturesTest.php`: quyền/vai trò/sân bay, duyệt độc lập, mã hóa, thao tác lặp, snapshot, phí/tổng tiền, rollback CSV lỗi, ngày Việt Nam, manifest, quá hạn và phương án hết hiệu lực. `tests/e2e/business.spec.js`: thao tác trình duyệt qua nhiều vai trò. Các fixture từ chối chạy nếu cả database và tài khoản ở ba kết nối không phải `airline_test`; thanh toán kiểm thử dùng mock.
