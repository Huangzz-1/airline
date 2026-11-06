# Đặt chỗ và sơ đồ cabin

## Sử dụng giao diện

Mở trang chủ, chọn **Một chiều / Khứ hồi**, sân bay và ngày; mỗi đặt mới dành cho một hành khách. Bộ lọc bên trái có Bay thẳng / 1 điểm dừng / 2+ điểm dừng. Bấm **Chọn** để xem hộp thông tin chuyến, tiếp tục chọn/xem lượt về nếu khứ hồi; nhãn khứ hồi nền vàng. Trang tiếp theo nhập họ tên, thông tin hành khách/liên hệ và xem tóm tắt giá, sau đó **Tiếp tục** mới sang thanh toán. Không còn trình thêm/bớt nhiều chặng. Xem [luồng đặt chuyến đi](trip-booking.md).

Mỗi lượt tối đa ba chuyến bay liên tiếp (hai điểm dừng), cùng sân bay nối, chờ 60 phút tới 6 giờ và tổng lượt không quá 24 giờ. Đây là cấu hình ứng dụng, chưa phải quy tắc của hãng/sân bay được nghiệm thu. Đặt mới một hành khách, thanh toán chung các chuyến và có vé/ghế/QR riêng trên mỗi chuyến. PNR nhiều người đã có vẫn được bảo toàn. Ghế chọn sau thanh toán khi check-in mở. Vé cũ vẫn giữ dữ liệu và layout tương thích.

## Quy tắc mã đặt chỗ chung

Các vé mới lưu [điều kiện đổi/hoàn và hành lý](eight-business-features.md). Khách có thể sửa thông tin trước thanh toán hoặc gửi CS duyệt sau xuất vé; mua hành lý bổ sung trước khi bắt đầu checkout. Số tiền hoàn tự nguyện trừ phí theo snapshot, còn hoàn vé cũ trong quy trình đổi được đối chiếu riêng.

- PNR mới có 1 hành khách × tối đa 6 chuyến = 6 vé thành phần. Các vé thành phần có mã phụ để phân biệt; chứng từ hiển thị cả PNR chung và mã phụ.
- Giữ chỗ khóa các dòng tồn chuyến theo thứ tự ID và tạo tất cả vé trong một giao dịch DB2. Thiếu tồn tổng, quota hạng giá hoặc chỗ trong cabin ở bất kỳ chặng nào thì rollback toàn bộ lần đặt.
- Các vé dùng chung một hạn giữ chỗ 10 phút. Gửi lại cùng khóa yêu cầu và cùng nội dung trả về PNR cũ; đổi nội dung với cùng khóa trả 409.
- Trước thanh toán, việc hủy thực hiện ở PNR chung. API thanh toán/hủy riêng của vé thành phần ban đầu trả 409. Nếu một chặng đã hủy/hết hạn, có thể hủy những chỗ chưa thanh toán còn lại và đặt lại.
- Một checkout intent chứa tổng tiền và danh sách phân bổ theo từng vé. Một kết quả xác nhận ghi tất cả khoản phân bổ/ledger trong một giao dịch DB3; sự kiện chuyển sang DB2 để xuất từng vé. Callback lặp không tạo khoản thu hoặc vé lần hai.
- Khi worker còn xử lý kết quả, trang PNR hiển thị chờ thanh toán và cho cập nhật trạng thái. Không xem phản hồi return của trình duyệt là bằng chứng đã thu tiền.
- Sau thanh toán, đổi/hoàn vẫn theo từng hành khách và chặng. Số tiền hoàn lấy từ khoản phân bổ của vé đó. Vé thay thế giữ liên kết PNR và người đi. Tổng tiền đặt ban đầu trên PNR không tự cộng thêm các giao dịch đổi vé về sau.
- Đổi vé kiểm tra lại thứ tự và khoảng cách với các chặng còn hiệu lực của chính hành khách khi gửi yêu cầu và khi phê duyệt. Mỗi hành khách chỉ có một yêu cầu đổi chưa hoàn tất; hành khách khác trong cùng PNR vẫn có thể yêu cầu riêng.
- Người đặt quản lý PNR, nhận email và làm thủ tục cho danh sách khách. Người đi chưa có tài khoản riêng vẫn có vé và QR riêng; không tạo tài khoản giả thay cho hành khách.

## Cabin và sơ đồ ghế

Trong **Đội tàu bay**, có thể chọn cấu hình cabin khi thêm tàu hoặc mở **Xem và cấu hình sơ đồ cabin**. Trang này cho xem cấu hình đang lưu và xem trước các mẫu:

| Mẫu | Cấu hình |
|---|---|
| Theo loại tàu | ATR/DHC/Q400 dùng mẫu khu vực; 787/777/330/350/380 dùng thân rộng; các loại khác dùng thân hẹp |
| Thân hẹp | Thương gia 2–2; phổ thông 3–3 |
| Thân rộng | Thương gia 1–2–1; phổ thông 3–3–3 |
| Khu vực | Phổ thông 2–2 |
| Kiểu hiện có | Sáu ghế mỗi hàng, giữ tương thích các chuyến cũ |

Các mẫu là cấu hình minh họa có số ghế khớp sức chứa, không phải sơ đồ được nhà sản xuất/hãng chứng nhận. Backend cũng hỗ trợ cabin tùy chỉnh qua đề nghị sửa danh mục `seat_layout`: mã khoang, tên, số hàng, các nhóm chữ ghế và số ghế hàng cuối.

Sửa cấu hình cần một quản lý đội tàu khác phê duyệt. Tàu đang có lịch công bố còn hiệu lực hoặc được giữ cho phương án thay tàu không thể sửa trực tiếp. Khi công bố chuyến mới, sơ đồ được chụp vào tồn chuyến ở DB2. Các chuyến cũ tiếp tục dùng sơ đồ sáu ghế/hàng để không làm sai ghế đã phát hành.

Check-in và đổi ghế kiểm tra ghế thực sự tồn tại và đúng khoang: BUSINESS/PREMIUM dùng khoang tương ứng, các hạng phổ thông/FLEX dùng chung ECONOMY. Các hạng giá cùng khoang dùng chung giới hạn vật lý; quota giá không thể làm bán vượt số ghế trong khoang. Trần bán toàn chuyến được kiểm tra riêng với sơ đồ ghế vật lý.

Đổi tàu cùng loại tiếp tục đi qua quy trình phê duyệt và outbox hiện có. Bước chuẩn bị kiểm tra cả tổng chỗ và chỗ từng khoang. Khi đủ chỗ, giữ ghế hợp lệ, chuyển ghế không còn hợp lệ sang ghế trống trong cùng khoang, xoay QR và lưu sơ đồ mới. Khi không đủ chỗ trong khoang đã mua, phương án thất bại và giữ tàu/ghế/QR cũ.

## Cấu trúc dữ liệu và API

DB2 bổ sung `reservations`, `reservation_passengers`, `reservation_segments`; mỗi dòng `bookings` vẫn là một khách trên một chuyến. Các tham chiếu mới nullable nên vé cũ tiếp tục hoạt động. `aircraft.seat_layout` ở DB1 là cấu hình nguồn; `inventory.seat_layout` và receipt thay tàu ở DB2 là các snapshot. DB3 bổ sung `checkout_intents.reservation_id/items`; các khoản thu và ledger vẫn phân bổ theo booking, không ghi trùng tổng tiền PNR.

Các endpoint cần session, xác minh email, vai trò passenger và CSRF như API hiện có:

| Method | Endpoint | Nội dung |
|---|---|---|
| GET | `/api/v1/reservations` | Danh sách PNR của mình |
| POST | `/api/v1/reservations` | Giữ toàn bộ hành trình |
| GET | `/api/v1/reservations/{uuid}` | PNR, khách, chặng và vé thành phần của mình |
| POST | `/api/v1/reservations/{uuid}/pay` | `method`: VNPAY/MOMO/BANK, thanh toán chung |
| POST | `/api/v1/reservations/{uuid}/cancel` | Hủy các giữ chỗ chưa thanh toán |

Ví dụ tạo PNR; thay flight ID bằng các chuyến đang mở bán, đúng chiều và đúng thứ tự giờ:

```json
{
  "idempotency_key": "request-unique-2026-001",
  "trip_type": "ROUND_TRIP",
  "passengers": [
    {"name": "Nguyễn Minh Anh", "date_of_birth": "1990-01-01"}
  ],
  "segments": [
    {"flight_id": 1001, "fare_class": "ECONOMY"},
    {"flight_id": 1002, "fare_class": "BUSINESS"}
  ]
}
```

`GET/POST /workspace/fleet-cabin` dành cho fleet_manager: GET xem trước với `aircraft_id`, `layout_preset`; POST gửi đề nghị với thêm `reason`. Phê duyệt tiếp tục dùng `master_approve` và người độc lập. Endpoint thêm tàu nhận tùy chọn `layout_preset`; bỏ trống giữ kiểu ghế hiện có.

## Dữ liệu mẫu và kiểm thử

Sau bộ dữ liệu local đầy đủ, chạy:

```bash
cd backend
php artisan airline:demo-reservations
```

Lệnh chỉ chạy local với thanh toán mock, tạo ba tàu A320/ATR72/B787 có cabin riêng, sáu chuyến AS9700–AS9705 và hai PNR đã thanh toán của `demo.passenger@airline.test`. Một PNR khứ hồi có 2 khách/2 chặng, một PNR một chiều có 2 khách/1 chuyến (bộ dữ liệu cũ vẫn giữ các PNR đã tạo trước đó). ID, mã và liên kết được lưu trong `backend/storage/app/private/demo/reservations.json`. Chạy lại giữ nguyên kết quả thử nghiệm. Các chuyến mẫu theo đồng hồ thực, không tự kéo dài cửa sổ check-in.

Kiểm thử bổ sung: `ReservationTest`, `CabinConfigurationTest`, `CabinLayoutTest` và `tests/e2e/reservations.spec.js`. Tất cả kiểm thử DB chạy riêng trên user/schema `airline_test`; không chạy song song PHPUnit và E2E vì chúng dùng chung DB test.

```bash
./scripts/test.sh
./scripts/e2e.sh tests/e2e/reservations.spec.js
```

Helper E2E hỗ trợ tùy chọn `E2E_PHP_BINARY` và `E2E_BACKEND_PATH` cho runtime PHP trực tiếp; bắt buộc cấu hình cả database và username là `airline_test`. Khi không đặt, helper tiếp tục dùng Docker như trước.
