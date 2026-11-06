# Dữ liệu chuyến bay nhiều hãng

Đã thêm **1.008 chuyến từ 04–17/11/2026**, gồm 4 hãng × 18 chuyến/ngày × 14 ngày, 16 tàu A320 và 6 sân bay: HAN, SGN, DAD, CXR, PQC, DLI. Có hai chiều để thử khứ hồi khác hãng.

| Mã | Hãng hiển thị | Hạng mẫu |
|---|---|---|
| VN | Vietnam Airlines | Phổ thông, linh hoạt, thương gia |
| VJ | Vietjet Air | Phổ thông, linh hoạt |
| QH | Bamboo Airways | Phổ thông, linh hoạt, thương gia |
| VU | Vietravel Airlines | Phổ thông, linh hoạt |

Tên/mã hãng minh họa có thể đối chiếu [IATA–Vietjet](https://www.iata.org/en/about/members/airline-list/vietjet/486/) và [Bamboo Airways](https://www.bambooairways.com/au/en/explore/flight-schedule). **Giờ, số hiệu 80xx, tàu, giá, hành lý và điều kiện vé là dữ liệu mô phỏng do ứng dụng tạo**, không phải lịch/giá/chính sách hiện hành của các hãng, không lấy giá từ Traveloka hoặc kết nối đặt vé thật.

## Thử ngay

Mở [HAN → SGN ngày 04/11](http://localhost:8000/?origin=HAN&destination=SGN&date=2026-11-04), hoặc chọn ngày bất kỳ đến 17/11. Tích một hãng chỉ trả hành trình do hãng đó khai thác trên tất cả các chặng; tích nhiều hãng có thể trả hành trình kết hợp giữa các hãng đã chọn.

Các giờ mẫu của VN theo Việt Nam; VJ/QH/VU được xếp lệch 15/30/45 phút:

| HAN → SGN | Giờ và sân bay |
|---|---|
| Bay thẳng | HAN 06:50 → SGN 09:05; thêm chuyến 17:00 |
| 1 điểm dừng | HAN 07:30 → DAD 09:00; DAD 10:35 → SGN 12:05; chờ 95 phút |
| 2 điểm dừng | HAN 07:30 → DAD 09:00; DAD 10:30 → CXR 11:35; CXR 13:10 → SGN 14:15; chờ 90/95 phút |

Chiều về có SGN → CXR → DAD → HAN, chuyến qua DAD và bay thẳng. Ngoài ra có SGN ↔ Phú Quốc, SGN ↔ Đà Lạt. Mỗi tàu quay lại sân bay xuất phát sau vòng bay trong ngày, không có lịch giao nhau, ít nhất 60 phút quay đầu. Giờ đến có thể dài hơn mẫu nếu thời lượng chuẩn trong đường bay hiện có lớn hơn.

Thẻ kết quả, hộp xem trước và tóm tắt hai lượt hiển thị đúng tên hãng. Hành trình nhiều hãng được ghi **Tự nối chuyến**, cần kiểm tra nhận lại hành lý và làm thủ tục lại; chưa có cam kết liên danh/chuyển tiếp hành lý. Bộ tìm kiếm cho 60–360 phút nối, tối đa 2 điểm dừng và tổng hành trình 24 giờ; đây là quy tắc ứng dụng thử nghiệm, chưa phải MCT do sân bay/hãng xác nhận.

## Tạo thêm các ngày khác

Sau migration `2026_11_03_020000_flight_carriers.php`, chạy trong `backend`:

```bash
php artisan airline:demo-flights --preview --days=14
php artisan airline:demo-flights --days=14
php artisan airline:demo-flights --start=2026-11-18 --days=14
```

PowerShell với PHP riêng của workspace:

```powershell
& '..\..\.runtime\php\php.exe' artisan airline:demo-flights --days=14
```

Mặc định từ ngày mai theo Việt Nam, tối đa 31 ngày/lần. Lệnh chỉ chạy local/testing, chỉ tạo lịch/tồn/hạng giá; không tạo tài khoản, booking, thanh toán/chuyển tiền và không đổi cấu hình ngân hàng. `--preview` chỉ xem kế hoạch. Ngày hôm nay bỏ qua chuyến cách hiện tại dưới 90 phút.

Chạy lại cùng khoảng ngày không nhân đôi chuyến, không đổi giá/hạng/vé đã có. Không tự bật lại đường bay ngừng hoạt động, sửa tàu đã đổi cấu hình hoặc dùng tàu đang bảo dưỡng. Nếu xung đột, lệnh dừng; dữ liệu đã nhập được giữ và có thể chạy lại sau xử lý nguyên nhân. Không có giao dịch nguyên tử giữa hai DB. Dấu nhận diện: `flights.demo_source=marketplace-v1`; báo cáo: `backend/storage/app/private/demo/multi-airline-flights.json`.

Các chuyến nhập ở SCHEDULED để test tìm/đặt vé, chưa gán tổ bay/nhiệm vụ mặt đất. Muốn thử khởi hành cần phân công và hoàn tất điều kiện khai thác. Lịch tự tạo sau này suy ra hãng từ đầu số VN/VJ/QH/VU; lịch AS cũ giữ Airline Smart.

## Kiểm chứng

`MultiAirlineFlightsTest` kiểm tra lịch tàu, tồn/cabin, chạy lại giữ vé/giá, lọc hãng và số điểm dừng, giá cộng từng chặng, giờ nối, tên hãng trong preview, bảo toàn đường bay ngừng hoạt động. `multi-airline.spec.js` kiểm tra bộ lọc/số lượng, preview, khứ hồi khác hãng và giao diện điện thoại.
