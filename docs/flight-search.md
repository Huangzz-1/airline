# Trang tìm kiếm và bộ lọc chuyến bay

Đã có [dữ liệu nhiều hãng](multi-airline-demo.md): bộ lọc chọn một/nhiều hãng thực sự theo tất cả các chuyến thành phần. Thẻ kết quả, preview và tóm tắt hai lượt hiển thị hãng tương ứng; phối hợp hãng được đánh dấu Tự nối chuyến. Bộ lọc điểm dừng hiển thị số lượng chuyến phù hợp, chỉ ghi Chưa có khi bằng 0.

Trang `/` dùng thanh tìm kiếm phía trên, bộ lọc bên trái và thẻ kết quả bên phải, tham khảo cách bố trí trong [Traveloka](https://www.traveloka.com/vi-vn/flight). Giao diện giữ thương hiệu Airline Smart. Trên điện thoại, nút **Bộ lọc** mở/đóng bảng lọc; thanh ngày có thể cuộn ngang.

Điểm đi và điểm đến để trống khi mở mới. Chọn điểm đi để mở điểm đến khả dụng qua mạng đường bay (kể cả nối chuyến). Chỉ có Một chiều / Khứ hồi; nhãn Khứ hồi nền vàng. Nút **Chọn** mở hộp chi tiết chuyến; người dùng tiếp tục chọn lượt về nếu cần, xem cả hai lượt rồi tới `/trip/passengers`. Đặt mới dành cho một hành khách; `passenger_count` chỉ nhận `1`. Xem [luồng đặt chuyến đi](trip-booking.md).

Bộ lọc dùng dữ liệu hiện có:

| Bộ lọc | Quy tắc |
|---|---|
| Còn đủ chỗ | Kiểm tra tồn tổng, quota hạng và sức chứa cabin cho một hành khách; loại chuyến đóng bán/đang đổi tàu |
| Hãng / điểm dừng | Airline Smart (`AS`), Vietnam Airlines (`VN`), Vietjet (`VJ`), Bamboo (`QH`), Vietravel (`VU`); bay thẳng, một hoặc hai điểm dừng (`0/1/2`). Bên cạnh điểm dừng là số chuyến phù hợp; bằng 0 ghi Chưa có |
| Giờ khởi hành / đến | Bốn khoảng 00–06, 06–12, 12–18, 18–24 giờ Việt Nam; có thể chọn nhiều khoảng |
| Giá | Thanh kéo hai đầu, giá VND tối thiểu–tối đa cho một hành khách trên lượt đang chọn, cộng các chuyến thành phần; phải khớp cùng hạng giá |
| Thời gian quá cảnh | Thanh kéo hai đầu, tổng số phút chờ giữa tất cả chặng; bay thẳng là 0 phút. Chọn mức tối thiểu lớn hơn 0 sẽ loại bay thẳng |
| Thời gian hành trình | Thanh kéo hai đầu, tổng số phút từ khởi hành chặng đầu đến đến chặng cuối, gồm bay và quá cảnh |
| Hạng vé | Lọc hạng đang mở bán; giá, quota và sức chứa phải khớp cùng hạng |
| Loại tàu / số hiệu | Loại tàu từ hồ sơ máy bay; tìm số hiệu không phân biệt hoa/thường |

Các điều kiện khác nhau kết hợp AND, nhiều lựa chọn trong cùng nhóm kết hợp OR. Sắp xếp theo đề xuất (ưu tiên đủ chỗ và khởi hành sớm), giá tăng/giảm, thời gian bay, giờ khởi hành hoặc giờ đến. Thanh ngày hiển thị giá thấp nhất còn đủ chỗ cho những ngày gần ngày đang chọn, áp dụng các bộ lọc còn lại.

Ba khoảng hỗ trợ kéo bằng chuột/chạm hoặc dùng Tab và phím mũi tên/Home/End. Hai đầu không vượt nhau; có giá trị VND hoặc giờ/phút ngay phía trên. Mốc min/max cố định lấy từ **toàn bộ hành trình đang hiển thị trong hệ thống**, không phụ thuộc tuyến, ngày, hãng, hạng hay bất kỳ bộ lọc nào đang chọn. Bao gồm tất cả chuyến đã công bố chưa khởi hành và các kết hợp 1–2 điểm dừng hợp lệ trên mọi tuyến/ngày; giá xét từng hạng chung trên các chặng. Không tính lịch quá khứ, nháp hoặc đã hủy. Kết quả vẫn chịu các bộ lọc như trước. Khi dữ liệu lịch/hạng giá đổi, mốc được tính lại; dữ liệu tạm được lưu theo dấu vân tay đầy đủ của lịch/giá nên không dùng nhầm mốc của dữ liệu cũ.

Mốc của thanh không nới ra theo tham số URL. Nếu một URL cũ có khoảng ngoài dữ liệu hiện có, thanh hiển thị trong min/max thật và có hướng dẫn chọn lại, còn kết quả vẫn áp dụng khoảng đã yêu cầu cho đến khi chọn lại. Không có dữ liệu/đồng nhất giá trị toàn hệ thống thì thanh tương ứng không kéo được. Đưa về toàn khoảng hoặc Đặt lại để bỏ giới hạn; URL và tải lại giữ lựa chọn. Không cần JavaScript vẫn gửi được các giá trị range qua nút Áp dụng.

Mỗi khoảng hiển thị **một thanh ngang chung**: đầu trái là min, đầu phải là max; đoạn xanh nằm giữa hai nút, phần ngoài khoảng màu xám. Có thể kéo từng nút hoặc kéo cả đoạn xanh để dịch khoảng, bấm đoạn xám để di chuyển đầu gần nhất. Trong lúc giữ chuột/chạm, số phía trên đổi ngay và thanh không bị dựng lại; thả tay mới cập nhật kết quả. Hai input native phục vụ bàn phím/form được ẩn về hình thức khi điều khiển chung sẵn sàng.

Backend lọc/sắp xếp toàn bộ kết quả phù hợp trước khi phân trang 8 chuyến. Thông tin lịch/tàu đọc DB1; tồn, hạng giá và lượng chỗ đã giữ đọc DB2 bằng truy vấn theo lô, không join qua hai instance. Giữ chỗ hết hạn không tính vào tồn đang sử dụng. Kết quả tìm kiếm chỉ là thông tin tại thời điểm truy vấn; thao tác giữ chỗ vẫn khóa tồn và tính lại giá bằng service hiện có.

Đổi bộ lọc tự cập nhật kết quả, số lượng, phân trang và URL; nút **Tìm chuyến bay** / **Áp dụng bộ lọc** vẫn dùng được. Lựa chọn được khôi phục khi tải lại hoặc Back/Forward. Request cũ trả về chậm bị bỏ qua. Khi đang tải hoặc tải lỗi, thao tác đặt trên dữ liệu cũ bị khóa; người dùng có thể tìm lại. Nút đặt vé đơn cập nhật giá hiển thị khi đổi hạng.

Nối chuyến được ghép từ các chuyến đã công bố: cùng sân bay, chờ 60 phút đến 6 giờ, tối đa 24 giờ mỗi lượt, không lặp sân bay. Có dữ liệu hãng mô phỏng và định mức hành lý theo snapshot điều kiện vé. Chưa có kết nối hãng đối tác, khuyến mãi, suất ăn hoặc Wi-Fi. Không kết nối dữ liệu/đặt vé qua Traveloka.

API `GET /api/v1/flights` và `GET /` với `Accept: application/json` giữ cấu trúc phân trang, nhận thêm:

```text
origin, destination, date, passenger_count, page
sort=recommended|price_asc|price_desc|duration|departure|arrival
price_min, price_max, duration_min, duration_max, layover_min, layover_max, available_only=1
fare_classes[]=ECONOMY|FLEX|BUSINESS|PREMIUM|<mã hạng đã cấu hình>
departure_periods[]=night|morning|afternoon|evening
arrival_periods[]=night|morning|afternoon|evening
aircraft_types[]=<loại tàu trong dữ liệu>
flight_number, carriers[]=AS|VN|VJ|QH|VU, stops[]=0|1|2
trip_type=ONE_WAY|ROUND_TRIP, return_date
```

Mỗi khoảng yêu cầu `min <= max` khi cùng có giá trị; hai đầu được tính bao gồm. `duration_min/max` là phút (0–2880, max ít nhất 1), `layover_min/max` là phút (0–1440), `price_min/max` là VND nguyên không âm. `layover_max=0` chỉ lấy bay thẳng. Ngày và giờ lọc theo `Asia/Ho_Chi_Minh`; chuyến đến qua ngày hiển thị `+1`. Request giao diện `GET /` kèm `X-Flight-Results: 1` trả JSON gồm HTML kết quả và trạng thái lọc; không thay đổi dạng trả về mặc định của API.

Mỗi kết quả có `journey_key`, `stop_count`, `via`, `legs`, `duration_minutes` (toàn hành trình) và `layover_minutes` (tổng chờ); với nối chuyến, `id` và `journey_key` là chuỗi các ID chuyến thành phần, ví dụ `123-456`, để không bị hiểu nhầm thành một chuyến đơn có thể đặt qua API cũ. Chỉ chuyến bay thẳng có `id` số nguyên. Đặt nối chuyến bằng toàn bộ `legs` qua luồng chuyến đi/PNR. Giá là tổng các chuyến cùng lượt. Nhập thông tin chưa tạo giữ chỗ; chỉ **Tiếp tục** mới tạo PNR và bắt đầu hạn giữ chỗ.
