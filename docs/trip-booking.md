# Đặt chuyến đi: một chiều / khứ hồi

**Cập nhật:** đặt mới chỉ dành cho một hành khách. Nút Chọn mở hộp thông tin lịch bay trước khi sang bước tiếp theo. Một chiều: xem chi tiết → Tiếp tục → nhập thông tin; khứ hồi: xem lượt đi → Chọn lượt về → xem cả hai lượt → Tiếp tục → nhập thông tin. Hộp có tên sân bay, giờ đi/đến, từng chuyến, thời gian nối chuyến, hạng vé và giá vừa kiểm tra lại. Đóng bằng nút ×, Escape hoặc bấm nền; không giữ chỗ khi chỉ xem. Không có JavaScript thì hiển thị trang chi tiết với cùng thông tin và nút tiếp tục.

Luồng khách hàng hiện tại: **tìm chuyến → Chọn → xem trước lượt đi → chọn/xem trước lượt về (khứ hồi) → Tiếp tục → thông tin hành khách và liên hệ → Tiếp tục → thanh toán**. Mọi nút trên danh sách kết quả dùng chữ **Chọn**. Không giữ chỗ khi chỉ xem/chọn lịch trình; giữ chỗ 10 phút bắt đầu sau khi hoàn thành thông tin và tiếp tục sang thanh toán.

Không còn giao diện tự ghép nhiều chặng hoặc thêm/bớt sân bay. Chỉ có `ONE_WAY` và `ROUND_TRIP` cho đặt mới. Các mã đặt chỗ cũ vẫn đọc được, không xóa dữ liệu. `/plan` chuyển sang trang tìm chuyến mới; liên kết cũ có `flight_id` chuyển qua bước chọn và nhập thông tin.

## Tìm chuyến và điểm dừng

- Điểm đi/đến ban đầu trống; điểm đến gồm các sân bay có thể tiếp cận trong tối đa ba chuyến qua mạng đường bay đang hoạt động.
- Một chiều chỉ chọn và hiển thị lượt đi. Khứ hồi có ngày đi/ngày về; chọn lượt đi trước, sau đó danh sách đảo chiều để chọn lượt về. Chuyến khứ hồi luôn có nhãn **Khứ hồi nền vàng** ở cả hai lượt.
- Bộ lọc **Bay thẳng / 1 điểm dừng / 2+ điểm dừng** dùng các chuyến đã công bố. Hiện hỗ trợ tối đa hai điểm dừng mỗi lượt, tương ứng ba chuyến bay; số tiền bên cạnh nhóm lọc là giá thấp nhất còn đủ chỗ theo các điều kiện đang áp dụng.
- Nối chuyến cùng sân bay, chờ 60 phút đến 6 giờ, không quay lại sân bay đã qua, tổng một lượt không vượt 24 giờ. Đây là cấu hình của ứng dụng, chưa phải quy tắc nối chuyến được hãng/sân bay nghiệm thu.
- Giá một lịch trình là tổng giá các chuyến thành phần cho cùng hạng; chỗ khả dụng là mức nhỏ nhất còn lại trên các chuyến đó, có tính quota và sức chứa cabin. Khác hạng giữa lượt đi và về được hỗ trợ. Chưa ghép hạng khác nhau trong cùng một lượt.
- Bộ lọc giá, giờ đi/đến, loại tàu, thời gian bay, số hiệu, sắp xếp và phân trang tiếp tục áp dụng cho toàn bộ lịch trình. Thời gian bay hiển thị gồm thời gian chờ nối chuyến.

## Thông tin hành khách

Trang `/trip/passengers` có thông tin hành khách và liên hệ bên trái, tóm tắt chuyến đi/giá bên phải; khứ hồi có hai khối **Chuyến đi / Chuyến về**, một chiều chỉ có **Chuyến đi**. Mỗi người nhập họ, chữ đệm/tên, quốc tịch và giới tính; ngày sinh/hộ chiếu là tùy chọn. Nếu nhập hộ chiếu, phải có hạn dùng còn hiệu lực tới ngày kết thúc chuyến đi. Họ tên lưu vào vé khớp thông tin vừa nhập.

Liên hệ gồm tên, điện thoại và email; cần xác nhận đã kiểm tra thông tin trước khi bấm **Tiếp tục**. Khách chưa đăng nhập được nhập thông tin trước, sau đó đăng nhập/xác minh email và quay lại bản nháp. Bản nháp lưu mã hóa trong session; thông tin hộ chiếu/liên hệ lưu mã hóa trong DB2 bằng APP_KEY. Không đưa các dữ liệu đó vào URL hoặc QR. Email liên hệ khác email người đặt cũng nhận thông báo kết quả thanh toán; quyền xem vé vẫn thuộc tài khoản người đặt.

Nút **Lưu** trong khung từng người kiểm tra thông tin và thu gọn khung, chưa tạo đặt chỗ. Mục hành lý/chỗ ngồi mô tả chức năng hiện có: chưa bán hành lý trả trước, gói bảo hiểm hoặc Refund Okay; chọn ghế sau thanh toán khi check-in mở. Không hiển thị phụ phí hoặc hạn mức hành lý không có trong dữ liệu.

## Kiểm tra và thanh toán

Lựa chọn chuyến được mã hóa/ký bằng APP_KEY, có hiệu lực 30 phút. Backend kiểm tra lại chuyến/hạng, kết nối, giá và đủ chỗ khi mở thông tin và trước giữ chỗ. Giá thay đổi cần kiểm tra lại; tổng cuối được kiểm tra trong transaction giữ chỗ. Một chặng hết chỗ khiến toàn bộ giữ chỗ rollback. Gửi lại cùng khóa và cùng thông tin trả cùng mã đặt chỗ, kể cả khi chính lần giữ chỗ trước đã chiếm những chỗ cuối.

PNR có `outbound_count` để phân biệt các chuyến của lượt đi và về, tối đa 6 chuyến cho khứ hồi, **1 hành khách**; thanh toán một lần cho cả chuyến đi, vé/ghế/check-in/QR riêng từng người và chuyến thành phần. Đổi, hoàn và đối soát tiếp tục theo vé thành phần. Không tự xác nhận đã nhận chuyển khoản từ nút báo chuyển khoản.

## Kiểm tra bước xem trước và giới hạn một người

Đã kiểm tra backend bằng TripBookingFlowTest, FlightSearchTest, PlanSearchTest, ReservationTest, BankTransferQrCheckoutTest và HomePageTest: lựa chọn/xác nhận giá, chặn nhiều người ở API và form, không tạo giữ chỗ khi xem trước, chống xử lý lặp, thanh toán và khả năng xử lý PNR cũ. Những bài kiểm thử PNR nhiều người dùng fixture lịch sử trong tests/Support; service tạo mới vẫn luôn kiểm tra đúng một hành khách.

Playwright đã qua 11 kịch bản, xác nhận theo từng bộ: flight-search (3), home (2), journey (2), reservations (4). Bao gồm xem lượt đi/lượt về và nối chuyến, đóng bằng Escape/trả focus, lỗi tải không cho tiếp tục, giữ bản nháp qua đăng nhập, một người thanh toán chung các chuyến, email, check-in/QR và giao diện điện thoại. Các bộ chạy riêng khi cần để không vượt giới hạn đăng nhập 5 lần/phút của môi trường thử nghiệm. Đã mở thêm luồng khứ hồi trên cổng local 8000 ở máy tính/điện thoại, chỉ xem thông tin và form, không tạo đặt chỗ hay thanh toán trên dữ liệu chính.

Migration `2026_11_03_000000_trip_passenger_details` thêm trường nullable vào DB2, không thay dữ liệu cũ. Đã sao lưu DB2 trước khi áp dụng local. Khi triển khai cần giữ APP_KEY hiện tại để đọc thông tin đã mã hóa.

API đặt mới `POST /api/v1/reservations` nhận `trip_type=ONE_WAY|ROUND_TRIP`, `segments`, `passengers`, tùy chọn `outbound_count`, `contact_details`, `passengers[].details`. Một chiều mặc định tất cả segment là lượt đi; khứ hồi mặc định một segment đi và một segment về, hoặc truyền `outbound_count` cho lịch trình nối chuyến. `MULTI_CITY` bị từ chối với đặt mới.
