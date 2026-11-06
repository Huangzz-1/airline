# Đối chiếu dự án với 20 phân hệ trong tài liệu DOCX

Ngày rà soát: **03/11/2026**. Đối tượng là mã nguồn đang có trong workspace, gồm cả thay đổi chưa commit; không chỉ nhánh remote.

**Cập nhật sau rà soát:** đã bổ sung [8 nhóm nghiệp vụ](eight-business-features.md). Các số đếm/bảng dưới là snapshot trước đợt bổ sung này, chưa phải kết quả đánh giá lại 100 chức năng.

Nguồn yêu cầu: `C:\Users\Admin\Downloads\activity và sequence.docx`, SHA-256 `3A4201E57CFF832CDBB6524C6574523B69D772001810F036CF72B415A4B00FC0`.

**Kết quả: cả 20 nhóm nghiệp vụ đều có phần triển khai tương ứng, nhưng dự án chưa bao phủ đầy đủ 100 chức năng M01-F01…M20-F05 trong tài liệu.** Menu được gom theo vai trò và công việc, không có quy tắc một phân hệ bằng một mục menu. Ba node cơ sở dữ liệu cũng không có nghĩa là chỉ ba phân hệ.

Phương pháp: trích danh mục 100 mã chức năng; đọc các sơ đồ Activity và các ràng buộc Sequence liên quan; đối chiếu route, controller/service, migration, giao diện và kiểm thử. Các sơ đồ được coi là yêu cầu để kiểm tra, không phải chỉ dẫn thực thi đối với công cụ hay môi trường. Đánh giá phạm vi prototype/sandbox ghi trong tài liệu, không tự thêm yêu cầu kết nối sân bay, ngân hàng hoặc thiết bị thật.

Quy ước: **Đã có luồng chính** = tìm thấy đường thực thi cho nội dung chính; **Một phần** = có nền tảng nhưng thiếu một đầu vào, đầu ra hoặc bước cụ thể trong sơ đồ; **Chưa thấy triển khai** = chưa tìm thấy chức năng chuyên biệt tương ứng. Đây là kiểm tra phạm vi mã nguồn, không phải chứng nhận nghiệm thu mọi nhánh hoặc tỷ lệ hoàn thành dự án.

Trong 100 dòng đối chiếu: **49 đã có luồng chính, 44 một phần, 7 chưa thấy triển khai**. Không quy đổi các số này thành phần trăm tiến độ vì khối lượng và mức độ quan trọng của từng chức năng khác nhau.

## Bản đồ 20 phân hệ

| Mã | Phân hệ | Điểm vào giao diện | Luồng chính / Một phần / Chưa thấy |
|---|---|---|---|
| M01 | Dữ liệu nền và sân bay | `/workspace/catalog` | 1 / 4 / 0 |
| M02 | Đường bay và lịch bay | `/workspace/scheduling` | 4 / 1 / 0 |
| M03 | Điều hành chuyến bay | `/workspace/scheduling, /staff` | 4 / 1 / 0 |
| M04 | Đội tàu bay | `/workspace/fleet, /workspace/fleet-cabin` | 4 / 1 / 0 |
| M05 | Bảo dưỡng tàu bay | `/workspace/maintenance` | 2 / 3 / 0 |
| M06 | Tổ bay và phân công | `/workspace/crew, /workspace/recovery` | 2 / 3 / 0 |
| M07 | Hành khách và khách hàng | `/profile, /trip/passengers, /journeys` | 3 / 2 / 0 |
| M08 | Đặt chỗ và vé | `/, /trip/passengers, /reservations/{id}, /journeys` | 3 / 1 / 1 |
| M09 | Check-in và boarding | `/journeys/{id}, /staff, /staff/passengers` | 3 / 2 / 0 |
| M10 | Hành lý | `/workspace/baggage, /baggage/{id}` | 2 / 3 / 0 |
| M11 | Dịch vụ mặt đất | `/workspace/ground` | 1 / 3 / 1 |
| M12 | Tài chính nghiệp vụ | `/workspace/finance` | 1 / 2 / 2 |
| M13 | Thông báo và chăm sóc khách hàng | `/workspace/customer, /support/{id}, /workspace/notifications` | 2 / 3 / 0 |
| M14 | AI và phân tích dữ liệu | `/workspace/analytics` | 1 / 2 / 2 |
| M15 | IoT mô phỏng | `/workspace/iot, /api/v1/telemetry; CLI airline:iot-simulate` | 3 / 2 / 0 |
| M16 | Quản trị và tích hợp | `/staff, /admin/connections, /workspace/audit, /workspace/integrations` | 3 / 2 / 0 |
| M17 | Giá vé và tồn chỗ | `/workspace/fare` | 2 / 3 / 0 |
| M18 | Phân bổ tài nguyên sân bay | `/workspace/resources` | 5 / 0 / 0 |
| M19 | Điều hành khi gián đoạn | `/workspace/disruptions, /workspace/recovery` | 2 / 3 / 0 |
| M20 | Đối soát và kiểm soát doanh thu | `/workspace/finance, /workspace/reports/{id}/export` | 1 / 3 / 1 |

## Đối chiếu 100 chức năng

### M01 – Dữ liệu nền và sân bay

Quyền: data_admin.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php), [MasterDataService.php](../backend/app/Modules/Operations/MasterDataService.php), [MasterFileService.php](../backend/app/Modules/Operations/MasterFileService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M01-F01 | Tạo/sửa hồ sơ sân bay và mã IATA/ICAO | Một phần | Có mã sân bay 3 ký tự, tên, thành phố, múi giờ và sửa có duyệt; chưa có trường ICAO riêng hoặc vị trí tọa độ. |
| M01-F02 | Quản lý nhà ga, khu vực và cửa | Một phần | Có TERMINAL/GATE/STAND/COUNTER theo sân bay; chưa có khu vực và quan hệ nhà ga → khu vực → cửa. |
| M01-F03 | Danh mục múi giờ và quy đổi thời điểm | Một phần | Có kiểm tra timezone IANA, lưu UTC và tạo lịch theo múi giờ sân bay; giao diện chủ yếu UTC+7, chưa có màn hình danh mục/quy đổi múi giờ độc lập. |
| M01-F04 | Kiểm tra trùng mã, quan hệ dữ liệu | Đã có luồng chính | Có kiểm tra trùng sân bay/đường bay/tài nguyên, khóa ngoại và kiểm tra dữ liệu tham chiếu. |
| M01-F05 | Tra cứu, nhập/xuất và audit dữ liệu nền | Một phần | Có tìm kiếm, audit, xuất CSV nhiều danh mục; nhập CSV mới hỗ trợ sân bay, vào hàng chờ duyệt nguyên tử. |

### M02 – Đường bay và lịch bay

Quyền: operations, ops_manager; crew_scheduler gán tổ bay.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M02-F01 | Tạo và quản lý đường bay | Đã có luồng chính | Tạo đường bay, kiểm tra hai đầu khác nhau và tồn tại; sửa thời lượng/khoảng cách/đang hoạt động qua phiên bản chờ duyệt. |
| M02-F02 | Lập lịch bay định kỳ theo mùa | Một phần | Có action season tạo lịch theo thứ trong khoảng tối đa 31 ngày; chưa lưu thực thể mùa và quy luật lặp để quản lý/chỉnh sửa lâu dài. |
| M02-F03 | Phát sinh lịch chuyến bay khai thác | Đã có luồng chính | Phát sinh các flight nháp từ kỳ/ngày trong tuần, có kiểm tra và transaction cả đợt. |
| M02-F04 | Kiểm tra xung đột lịch tàu bay | Đã có luồng chính | Công bố kiểm tra tàu khả dụng, khoảng quay đầu và xung đột; khóa nguồn lực khi phê duyệt. |
| M02-F05 | Công bố, sửa và ghi lịch sử lịch bay | Đã có luồng chính | Có trình/duyệt đơn hoặc đợt, version, audit, sự kiện mở bán và các phương án điều chỉnh đã công bố. |

### M03 – Điều hành chuyến bay

Quyền: operations, ops_manager.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M03-F01 | Tạo hồ sơ chuyến bay khai thác | Đã có luồng chính | Có tạo flight với số hiệu, đường bay, tàu, giờ đi/đến và trạng thái nháp. |
| M03-F02 | Cập nhật trạng thái theo vòng đời | Đã có luồng chính | Có state machine BOARDING → GATE_CLOSED → DEPARTED → ARRIVED, chặn trạng thái không hợp lệ và điều kiện chưa hoàn tất. |
| M03-F03 | Ghi giờ kế hoạch và giờ thực tế | Một phần | Có giờ kế hoạch và actual_departure/actual_arrival; giờ thực tế tự lấy lúc chuyển trạng thái, chưa có nhập/hiệu đính thời điểm thực tế riêng. |
| M03-F04 | Điều phối thay đổi giờ/tàu bay | Đã có luồng chính | Có delay/cancel và phương án đổi tàu/tổ bay có người duyệt độc lập; đổi tàu giới hạn cùng loại trước boarding. |
| M03-F05 | Nhật ký sự kiện và dashboard điều hành | Đã có luồng chính | Có danh sách/dashboard khai thác, trạng thái, KPI và audit sự kiện; quyền xem nhật ký tùy vai trò. |

### M04 – Đội tàu bay

Quyền: fleet_manager.

Căn cứ: [MasterDataService.php](../backend/app/Modules/Operations/MasterDataService.php), [SeatMap.php](../backend/app/Modules/Boarding/SeatMap.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M04-F01 | Quản lý loại/dòng tàu bay | Một phần | Có type trên từng aircraft và preset cabin; chưa có danh mục loại/dòng tàu với mã và cấu hình dùng chung độc lập. |
| M04-F02 | Hồ sơ từng tàu bay và số đăng ký | Đã có luồng chính | Có hồ sơ tàu, số đăng ký duy nhất, sức chứa, sơ đồ cabin và phiên bản sửa có duyệt. |
| M04-F03 | Theo dõi khả dụng/trạng thái khai thác | Đã có luồng chính | Có AVAILABLE/MAINTENANCE, kiểm tra trước công bố/chuyển trạng thái và giữ tàu trong phương án thay đổi. |
| M04-F04 | Cộng giờ bay và chu kỳ cất hạ cánh | Đã có luồng chính | ARRIVED cộng giờ bay và chu kỳ một lần; chuyển lại trạng thái không được phép. |
| M04-F05 | Tra cứu lý lịch, lịch sử và audit tàu bay | Đã có luồng chính | Có tra cứu đội tàu, bản sửa trước/sau và audit theo đối tượng; nhật ký đầy đủ nằm ở khu vực kiểm toán. |

### M05 – Bảo dưỡng tàu bay

Quyền: maintenance.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M05-F01 | Định mức và lịch bảo dưỡng | Một phần | Có due_at cho từng lệnh và tổng giờ/chu kỳ của tàu; chưa có định mức theo giờ/chu kỳ và tính mốc bảo dưỡng tiếp theo. |
| M05-F02 | Tạo/duyệt lệnh bảo dưỡng | Một phần | Có mở → hoàn tất → giải phóng, người giải phóng độc lập; chưa có bước trình/duyệt lệnh trước khi bắt đầu thực hiện. |
| M05-F03 | Ghi hạng mục, vật tư và kết quả | Một phần | Có tiêu đề lệnh và evidence dạng văn bản; chưa có danh sách hạng mục, vật tư sử dụng và kết quả có cấu trúc. |
| M05-F04 | Khóa/mở trạng thái đủ điều kiện khai thác | Đã có luồng chính | Mở lệnh khóa MAINTENANCE; chỉ AVAILABLE khi mọi lệnh đã RELEASED, kiểm tra người giải phóng độc lập. |
| M05-F05 | Cảnh báo quá hạn và lịch sử bảo dưỡng | Đã có luồng chính | Có danh sách lệnh, hạn, trạng thái, bằng chứng và đánh dấu quá hạn trên giao diện; có audit lịch sử. |

### M06 – Tổ bay và phân công

Quyền: crew_scheduler, ops_manager.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php), [FlightChangeService.php](../backend/app/Modules/Operations/FlightChangeService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M06-F01 | Hồ sơ nhân sự và chứng chỉ | Một phần | Có hồ sơ nhân sự, vị trí và hạn giấy phép; chứng chỉ chưa thành danh sách nhiều loại/số hiệu riêng. |
| M06-F02 | Quản lý năng định và ngày hiệu lực | Một phần | Có qualification và license_expires; chưa có nhiều năng định với khoảng hiệu lực bắt đầu/kết thúc riêng. |
| M06-F03 | Lịch trực, nghỉ và khả dụng | Một phần | Có available và kiểm tra nhiệm vụ/nghỉ từ lịch phân công; chưa có lịch ca trực/nghỉ phép riêng. |
| M06-F04 | Phân công tổ bay theo chuyến | Đã có luồng chính | Phân công theo chuyến, đủ pilot/cabin; thay tổ bay có duyệt và giữ lịch sử phân công trước đó. |
| M06-F05 | Phát hiện trùng lịch, thiếu điều kiện | Đã có luồng chính | Có kiểm tra trùng nhiệm vụ, hạn giấy phép, năng định, khả dụng, duty tối đa và khoảng nghỉ. |

### M07 – Hành khách và khách hàng

Quyền: passenger; airport_agent tra cứu tại quầy.

Căn cứ: [ReservationService.php](../backend/app/Modules/Booking/ReservationService.php), [TripDetails.php](../backend/app/Modules/Booking/TripDetails.php), [CustomerService.php](../backend/app/Modules/Customer/CustomerService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M07-F01 | Quản lý hồ sơ khách hàng | Đã có luồng chính | Có đăng ký, consent, xác minh email, profile/liên hệ, đổi/reset mật khẩu và quyền sở hữu. |
| M07-F02 | Hồ sơ hành khách theo booking | Đã có luồng chính | Có reservation_passengers gắn PNR, tên riêng từng người và vé từng người/từng chuyến; thông tin bổ sung mã hóa. |
| M07-F03 | Xác thực dữ liệu, giấy tờ mô phỏng | Một phần | Có kiểm tra định dạng ngày sinh/quốc tịch/giấy tờ, thời hạn trên form; chưa có quy trình xác minh giấy tờ với kết quả và lịch sử xác thực riêng. |
| M07-F04 | Tra cứu lịch sử đặt chỗ | Đã có luồng chính | Có Hành trình của tôi, chi tiết PNR/vé và tra cứu hành khách tại quầy theo quyền/sân bay. |
| M07-F05 | Ẩn thông tin nhạy cảm và phân quyền | Một phần | Có ownership, RBAC và mã hóa giấy tờ/liên hệ; chưa thấy chính sách che từng trường nhạy cảm theo vai trò trong dữ liệu phản hồi. |

### M08 – Đặt chỗ và vé

Quyền: passenger; finance duyệt đổi/hoàn.

Căn cứ: [FlightSearch.php](../backend/app/Modules/Flights/FlightSearch.php), [ReservationService.php](../backend/app/Modules/Booking/ReservationService.php), [ExchangeService.php](../backend/app/Modules/Booking/ExchangeService.php), [OutboxRelay.php](../backend/app/Modules/Finance/OutboxRelay.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M08-F01 | Tìm chuyến/hạng giá và tạo đơn | Đã có luồng chính | Có tìm/lọc chuyến, hạng giá, nối chuyến, một chiều/khứ hồi và tạo PNR từ thông tin hành khách. |
| M08-F02 | Giữ chỗ có hạn và chống xử lý lặp | Đã có luồng chính | Có giữ 10 phút, expire, idempotency và khóa tồn chỗ; cả nhóm/chuyến được giữ trong transaction. |
| M08-F03 | Chỉnh khách/tiện ích trong đơn | Chưa thấy triển khai | Nhập/sửa trên form trước tạo PNR được; chưa thấy action sửa danh sách/thông tin khách hoặc thêm tiện ích vào đơn đã tạo và tính lại giá/tồn. |
| M08-F04 | Phát hành, kiểm tra vé điện tử | Đã có luồng chính | Thanh toán hợp lệ phát vé riêng từng người/chuyến, chống xuất trùng; có trang xem/in vé và kiểm tra quyền. |
| M08-F05 | Đổi, hủy và hoàn tiền giả lập | Một phần | Có hủy giữ chỗ, đổi vé đã trả và hoàn có duyệt; chính sách mua mới/hoàn toàn bộ cố định, chưa có quy tắc phí đổi/hủy cấu hình như đầu vào sơ đồ. |

### M09 – Check-in và boarding

Quyền: passenger, airport_agent.

Căn cứ: [BoardingService.php](../backend/app/Modules/Boarding/BoardingService.php), [PassengerChanges.php](../backend/app/Modules/Boarding/PassengerChanges.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M09-F01 | Mở/khóa check-in theo chuyến | Một phần | Có cửa sổ check-in theo giờ bay/trạng thái; chưa có action cấu hình hoặc chủ động mở/khóa riêng cửa sổ check-in cho từng chuyến. |
| M09-F02 | Xác minh booking/vé và chọn chỗ | Đã có luồng chính | Kiểm tra vé đã trả, đúng người, thời gian và ghế còn trống/đúng cabin; có tự làm thủ tục và làm tại quầy. |
| M09-F03 | Phát QR thẻ lên tàu bay | Đã có luồng chính | Có QR opaque, lưu hash/token mã hóa, thời hạn, thu hồi/cấp lại và chứng từ in. |
| M09-F04 | Quét QR và chống quét trùng | Đã có luồng chính | Quét kiểm tra đúng chuyến/sân bay, thời gian, trạng thái và chống dùng QR lần hai. |
| M09-F05 | Chốt danh sách hành khách lên tàu | Một phần | Có trạng thái BOARDED, tra cứu khách, no-show/offload và điều kiện đóng/khởi hành; chưa có bản danh sách boarding chốt theo phiên bản riêng. |

### M10 – Hành lý

Quyền: airport_agent; passenger xem kiện của mình.

Căn cứ: [BaggageService.php](../backend/app/Modules/Baggage/BaggageService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M10-F01 | Đăng ký hành lý và hạn mức | Một phần | Có đăng ký cân nặng 0.1–32 kg mỗi kiện; chưa có hạn mức theo vé/hạng và tính phần vượt/phí hành lý. |
| M10-F02 | Phát mã thẻ hành lý | Đã có luồng chính | Sinh mã tag duy nhất, gắn booking/hành khách/chuyến và chống yêu cầu tạo trùng. |
| M10-F03 | Quét tiếp nhận/bàn giao qua các điểm | Một phần | Có mốc quét, custody, actor, vị trí và chống trùng; tag gắn một booking/chuyến, chưa nối bàn giao thật sang nhiều chuyến kế tiếp. |
| M10-F04 | Theo dõi trạng thái và trả hành lý | Đã có luồng chính | Có timeline trạng thái, xác nhận DELIVERED tại sân bay đến sau ARRIVED, quyền tra cứu theo chủ vé/sân bay. |
| M10-F05 | Tiếp nhận bất thường/thất lạc mô phỏng | Một phần | Có MISHANDLED/OFFLOADED và yêu cầu hỗ trợ BAGGAGE; chưa có hồ sơ thất lạc riêng liên kết kiện, nguyên nhân và quy trình giải quyết. |

### M11 – Dịch vụ mặt đất

Quyền: operations, ground_agent.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M11-F01 | Danh mục dịch vụ và đơn vị thực hiện | Chưa thấy triển khai | Chưa thấy danh mục dịch vụ mặt đất, đơn vị cung ứng và phạm vi phục vụ; hiện nhiệm vụ dùng tiêu đề tự nhập. |
| M11-F02 | Lập nhiệm vụ quay đầu chuyến bay | Một phần | Có tạo nhiều ground_task theo chuyến và hạn; chưa mô hình hóa chuyến đến/đi, dịch vụ, khoảng turnaround và ca làm trong kế hoạch. |
| M11-F03 | Phân công nhân lực và thiết bị | Một phần | Có assigned_to kiểm tra vai trò/sân bay; chưa có thiết bị phục vụ hoặc phân công thiết bị cho nhiệm vụ. |
| M11-F04 | Ghi nhận tiến độ hoàn tất từng bước | Đã có luồng chính | Có PENDING/IN_PROGRESS/COMPLETED, bằng chứng, thời điểm hoàn tất và audit; chỉ nhân viên được giao cập nhật. |
| M11-F05 | Báo cáo SLA, tồn đọng và bàn giao | Một phần | Có SLA bằng due_at, hiển thị quá hạn và KPI; chưa có báo cáo SLA chuyên biệt/biên bản bàn giao tác vụ. |

### M12 – Tài chính nghiệp vụ

Quyền: finance.

Căn cứ: [FinanceService.php](../backend/app/Modules/Finance/FinanceService.php), [CheckoutService.php](../backend/app/Modules/Finance/CheckoutService.php), [OutboxRelay.php](../backend/app/Modules/Finance/OutboxRelay.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M12-F01 | Danh mục phí và nguồn doanh thu | Chưa thấy triển khai | Chưa thấy bảng/service/form quản lý mã khoản phí, nguồn thu và quy tắc áp dụng. |
| M12-F02 | Ghi sổ nghiệp vụ bán/đổi/hủy vé | Đã có luồng chính | Có ledger CAPTURE/REFUND liên kết booking, phát sinh từ thanh toán/đổi/hoàn; chống ghi bút toán thu/hoàn lặp. |
| M12-F03 | Hoàn tiền và điều chỉnh mô phỏng | Một phần | Có hoàn tiền và luồng duyệt/chi; chưa có phiếu điều chỉnh số tiền độc lập và bút toán điều chỉnh chung. |
| M12-F04 | Báo cáo tổng hợp theo kỳ/đường bay | Chưa thấy triển khai | Có bảng sổ giao dịch và kỳ đối soát; chưa thấy báo cáo tổng hợp thu/chi có bộ lọc kỳ và đường bay theo sơ đồ này. |
| M12-F05 | Phân quyền duyệt giao dịch điều chỉnh | Một phần | Có phân quyền finance và người duyệt/chi độc lập cho hoàn/đổi; chưa có quy trình phê duyệt phiếu điều chỉnh giao dịch chung. |

### M13 – Thông báo và chăm sóc khách hàng

Quyền: passenger, customer_service; mọi vai trò xem hộp thư của mình.

Căn cứ: [CustomerService.php](../backend/app/Modules/Customer/CustomerService.php), [Events.php](../backend/app/Modules/Shared/Events.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M13-F01 | Thiết lập mẫu email/SMS sandbox | Một phần | Có email template viết trong code và SMTP/Mailpit; chưa có CRUD/phiên bản mẫu email-SMS sandbox hoặc kênh SMS. |
| M13-F02 | Kích hoạt thông báo theo sự kiện | Đã có luồng chính | Các sự kiện thanh toán, thay đổi chuyến, hỗ trợ tạo thông báo in-app và đưa email vào queue. |
| M13-F03 | Lưu trạng thái gửi và chống gửi lặp | Một phần | Có outbox/inbox chống xử lý event trùng, queue retry và read_at; chưa có lịch sử trạng thái gửi theo từng kênh/người nhận hoặc delivery receipt. |
| M13-F04 | Ghi nhận yêu cầu hỗ trợ khách | Đã có luồng chính | Có phiếu ASSISTANCE/COMPLAINT/BAGGAGE, nội dung, liên kết booking, trạng thái và nhân viên phản hồi. |
| M13-F05 | Tra cứu hội thoại và thời hạn xử lý | Một phần | Có hội thoại append-only và ownership; chưa có due_at/SLA, chuyển cấp khi quá hạn hoặc theo dõi hạn xử lý từng hồ sơ. |

### M14 – AI và phân tích dữ liệu

Quyền: analyst, operations, ops_manager.

Căn cứ: [AnalyticsService.php](../backend/app/Modules/Analytics/AnalyticsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M14-F01 | Làm sạch dữ liệu sự kiện chuyến bay | Chưa thấy triển khai | Chưa thấy pipeline nhập/làm sạch lịch sử sự kiện chuyến bay, đánh dấu dữ liệu thiếu và lưu nguồn dữ liệu đã xử lý. |
| M14-F02 | Huấn luyện/đánh giá mô hình trễ chuyến | Chưa thấy triển khai | forecast chỉ là rules-demo-v1; chưa có dataset train/test, huấn luyện, lưu model hoặc báo cáo chỉ số đánh giá. |
| M14-F03 | Phát hiện cảm biến bất thường | Đã có luồng chính | Telemetry so giá trị với threshold và gắn alert; đây là phát hiện theo ngưỡng, không phải mô hình ML. |
| M14-F04 | Giải thích độ tin cậy/cảnh báo | Một phần | Có yếu tố đầu vào, risk, model version, thời gian và giải thích; confidence cố định 0.6, chưa được đo/hiệu chuẩn bằng dữ liệu đánh giá. |
| M14-F05 | Dashboard KPI và giám sát chất lượng mô hình | Một phần | Có KPI vận hành; chưa có dashboard chất lượng mô hình, drift hoặc sai số dự báo theo cửa sổ thời gian. |

### M15 – IoT mô phỏng

Quyền: integration_admin.

Căn cứ: [AnalyticsService.php](../backend/app/Modules/Analytics/AnalyticsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M15-F01 | Xây nguồn cảm biến ảo Wokwi/script | Đã có luồng chính | Có airline:iot-simulate với normal/duplicate/late/alert/disconnect; tài liệu cho phép Wokwi hoặc script, không bắt buộc cả hai. |
| M15-F02 | Chuẩn hóa schema bản tin và đồng hồ | Đã có luồng chính | Có schema event ID/device/sequence/value/event_time, chuẩn hóa UTC, received_at và kiểm tra lệch giờ tương lai. |
| M15-F03 | Thu nhận API, xác thực, chống trùng | Đã có luồng chính | Có API telemetry qua xác thực/vai trò/sân bay, khóa thiết bị và chống trùng event ID/sequence. |
| M15-F04 | Lưu/biểu diễn sự kiện thời gian | Một phần | Có bảng sự kiện và giá trị mới nhất, phân biệt đến trễ; chưa có biểu đồ chuỗi thời gian/bộ chọn cửa sổ quan sát theo sơ đồ. |
| M15-F05 | Phát cảnh báo và thử lỗi kết nối | Một phần | Có alert flag và kịch bản CLI disconnect; disconnect mới không gửi dữ liệu/in thông báo, chưa có trạng thái kết nối, hàng đợi gửi lại và kiểm thử đứt/kết nối lại đầy đủ. |

### M16 – Quản trị và tích hợp

Quyền: admin, auditor, integration_admin tùy chức năng.

Căn cứ: [Access.php](../backend/app/Modules/Shared/Access.php), [Audit.php](../backend/app/Modules/Shared/Audit.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M16-F01 | Tài khoản, xác thực và RBAC | Đã có luồng chính | Có tài khoản, session, email verification, đổi/reset mật khẩu, khóa tài khoản và RBAC 15 vai trò. |
| M16-F02 | Ma trận vai trò và kiểm quyền API | Một phần | Có ma trận quyền trong code, middleware/service kiểm quyền và test 403; chưa thấy audit riêng cho mọi quyết định cho phép/từ chối như đầu ra sơ đồ. |
| M16-F03 | Quản lý cấu hình hệ thống | Một phần | Có config PHP/.env và màn hình xem kết nối; chưa có CRUD cấu hình hệ thống với phiên bản, phạm vi và lịch sử áp dụng. |
| M16-F04 | Nhật ký truy cập, kiểm toán, backup | Đã có luồng chính | Có audit trên 3 node, sự kiện đăng nhập/đăng xuất và script backup/restore; restore chỉ vào airline_test. Lần rà soát này không chạy restore. |
| M16-F05 | Hợp đồng API và quan trắc tích hợp | Đã có luồng chính | Có API v1, tài liệu hợp đồng, health check và trang outbox attempts/delivered; chưa suy diễn rằng đã có hệ thống quan trắc ngoài ứng dụng. |

### M17 – Giá vé và tồn chỗ

Quyền: data_admin.

Căn cứ: [BookingService.php](../backend/app/Modules/Booking/BookingService.php), [ReservationService.php](../backend/app/Modules/Booking/ReservationService.php), [FlightSearch.php](../backend/app/Modules/Flights/FlightSearch.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M17-F01 | Quản lý cấu trúc hạng giá và quy tắc | Một phần | Có mã/tên hạng, giá, quota, active; chưa có thực thể luật giá và kiểm tra các quy tắc áp dụng mâu thuẫn. |
| M17-F02 | Thiết lập tồn chỗ theo chuyến/hạng | Đã có luồng chính | Có tồn tổng chuyến, quota từng hạng, giới hạn cabin vật lý và đóng/mở bán. |
| M17-F03 | Báo giá theo điều kiện bán mô phỏng | Một phần | Báo giá dùng giá hạng hiện tại, cộng các chặng và số người; chưa có hạn hiệu lực/điều kiện bán theo thời điểm và căn cứ luật giá. |
| M17-F04 | Giữ/giải phóng tồn chỗ an toàn | Đã có luồng chính | Giữ/giải phóng dưới inventory lock, TTL, transaction nhóm và kiểm tra request lặp. |
| M17-F05 | Kiểm tra oversell và đồng bộ booking | Một phần | Có ngăn oversell ở thời điểm đặt, kiểm tra cabin/quota và test tranh ghế cuối; chưa có danh sách cảnh báo sai lệch tồn–booking hoặc tác vụ đối chiếu tồn riêng. |

### M18 – Phân bổ tài nguyên sân bay

Quyền: operations; data_admin quản lý danh mục.

Căn cứ: [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M18-F01 | Danh mục quầy/cửa/vị trí đỗ | Đã có luồng chính | Có danh mục TERMINAL/GATE/STAND/COUNTER theo sân bay, trạng thái active và sửa có duyệt. |
| M18-F02 | Lập lịch sử dụng tài nguyên | Đã có luồng chính | Có allocation với starts_at/ends_at, liên kết tài nguyên/chuyến và lịch đã phân bổ để tra cứu. |
| M18-F03 | Phân công tài nguyên theo chuyến | Đã có luồng chính | Có giao tài nguyên cho chuyến, kiểm tra cùng sân bay và cập nhật cửa/version qua sự kiện. |
| M18-F04 | Kiểm tra chồng lấn/khả dụng | Đã có luồng chính | Có khóa tài nguyên/sân bay, kiểm tra active, khung giờ và overlap để từ chối phân bổ trùng. |
| M18-F05 | Điều chỉnh và audit thay đổi tài nguyên | Đã có luồng chính | Có gỡ rồi phân bổ lại với lý do, audit và thông báo thay đổi cửa; không tự tối ưu tài nguyên. |

### M19 – Điều hành khi gián đoạn

Quyền: operations, ops_manager, crew_scheduler.

Căn cứ: [FlightChangeService.php](../backend/app/Modules/Operations/FlightChangeService.php), [OperationsService.php](../backend/app/Modules/Operations/OperationsService.php), [WorkflowRelay.php](../backend/app/Modules/Shared/WorkflowRelay.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M19-F01 | Tiếp nhận sự kiện trễ/hủy/đổi chuyến | Đã có luồng chính | Có đề nghị delay/cancel và đổi tàu/tổ bay, lý do, mã theo dõi và trạng thái. |
| M19-F02 | Phân tích ảnh hưởng nguồn lực liên quan | Một phần | Có kiểm tra nguồn lực, số booking ảnh hưởng, before/after và mapping ghế; chưa tổng hợp ảnh hưởng dây chuyền khách nối chuyến/tổ bay/tài nguyên thành một bảng đầy đủ. |
| M19-F03 | Đề xuất phương án xử lý thay thế | Một phần | Có đề xuất thủ công đổi giờ/tàu/tổ bay và hủy; chưa có phương án hành trình thay thế cho khách hoặc bảng so sánh lựa chọn/ưu nhược như sơ đồ. |
| M19-F04 | Phê duyệt và thực hiện thay đổi | Đã có luồng chính | Có người duyệt độc lập, kiểm tra version/xung đột và thực hiện qua outbox/saga có retry/compensation. |
| M19-F05 | Thông báo, lịch sử và báo cáo sự cố | Một phần | Có danh sách phương án, lịch sử và thông báo; chưa có báo cáo sự cố tổng hợp kết quả xử lý/nhóm bị ảnh hưởng riêng. |

### M20 – Đối soát và kiểm soát doanh thu

Quyền: finance.

Căn cứ: [FinanceService.php](../backend/app/Modules/Finance/FinanceService.php). Giao diện/điều phối chung: [WorkspaceController.php](../backend/app/Http/Controllers/WorkspaceController.php), [routes/web.php](../backend/routes/web.php).

| Mã | Chức năng trong DOCX | Đánh giá | Triển khai và khoảng thiếu |
|---|---|---|---|
| M20-F01 | Nhập dữ liệu giao dịch sandbox | Chưa thấy triển khai | Dữ liệu mock tạo qua ứng dụng/seed/API; chưa có chức năng tải tệp giao dịch sandbox, kỳ nhập và truy vết đợt nhập. |
| M20-F02 | Đối chiếu booking/vé/thu hoàn | Một phần | Có đối chiếu booking/ticket/payment theo kỳ; chưa đối chiếu đầy đủ refund và ledger thu/hoàn thành các nguồn riêng. |
| M20-F03 | Phát hiện và phân nhóm chênh lệch | Một phần | Có MATCHED/MISMATCH, căn cứ amount/status/ticket; chưa có nhóm nguyên nhân sai lệch và tổng hợp số lượng theo nhóm/ngưỡng. |
| M20-F04 | Xử lý, phê duyệt điều chỉnh đối soát | Một phần | Có ghi chú chuyển MISMATCH thành EXCEPTION và người khác khóa kỳ; chưa có phiếu điều chỉnh nguồn kèm bước phê duyệt từng điều chỉnh. |
| M20-F05 | Khóa kỳ và xuất báo cáo kiểm soát | Đã có luồng chính | Có chặn khóa khi còn MISMATCH, người khóa khác người tạo và xuất CSV chỉ cho kỳ CLOSED. |

## Các use case tổng hợp cần lưu ý thêm

- **UC-18/19 (M07):** đã có hồ sơ/consent và phiếu ASSISTANCE gắn booking; chưa mô hình hóa yêu cầu hỗ trợ đặc biệt cho từng người trong PNR và chuyển giao chuyên trách.
- **UC-28 (M10):** có mã kiện và tra cứu; chưa thấy chứng từ in thẻ hành lý chuyên biệt như bước “In thẻ” trong Activity. Không nhầm với trang in vé/thẻ lên tàu.
- **UC-37 (M13):** có in-app/email; chưa có lựa chọn SMS, chuyển kênh khi gửi thất bại và lịch sử giao nhận theo kênh.
- **UC-38 (M13):** có khiếu nại, phản hồi và đóng hồ sơ; chưa có hồ sơ bồi thường, chuyển cấp thẩm quyền và chi trả bồi thường riêng.
- **UC-39 (M13):** có hội thoại từng phiếu và hộp thư; chưa có timeline tổng hợp toàn bộ tương tác của một khách với bộ lọc loại tương tác và che trường theo ngữ cảnh.

Các UC này được ghi riêng để không đếm hai lần vào tổng 100 mã F; số dòng “đã có” không khẳng định đã đáp ứng tất cả bước mở rộng của UC tổng hợp.

## Kiểm chứng đã thực hiện

Đã chạy lại bộ kiểm thử sẵn có, không thêm kiểm thử chỉ để xác nhận tên menu:

```text
PHP 8.4.27 / PHPUnit 11.5.57
vendor/bin/phpunit --filter OperationsWorkflowTest --colors=never
OK (30 tests, 309 assertions)
Time: 02:11.493
```

Bộ này kiểm tra lịch bay/công bố, phân quyền/sân bay, crew/rest, bảo dưỡng, tài nguyên, SLA/state machine, hành lý, hoàn/hủy/trễ, đối soát/khóa kỳ/CSV, IoT/risk rules, hỗ trợ, hạng giá, giao diện workspace, phiên bản danh mục, QR/offload, replay/outbox và đổi vé. Test chạy trên ba schema/user `airline_test`, không reset dữ liệu `airline` chính. Không chạy song song với worker E2E. Không coi 30 bài qua là bằng chứng nghiệm thu đủ 100 chức năng.

Các nguồn kiểm thử liên quan khác đã đọc: [OperationsWorkflowTest.php](../backend/tests/Feature/OperationsWorkflowTest.php), [FlightResourceChangeTest.php](../backend/tests/Feature/FlightResourceChangeTest.php), [TripBookingFlowTest.php](../backend/tests/Feature/TripBookingFlowTest.php), [ReservationTest.php](../backend/tests/Feature/ReservationTest.php), [PaymentGatewayTest.php](../backend/tests/Feature/PaymentGatewayTest.php). Lần rà soát này không chạy lại toàn bộ các bộ đó hoặc E2E, không thực hiện thanh toán thật và không thử restore.

## Thứ tự bổ sung để bám tài liệu

1. **AI M14:** pipeline lịch sử chuyến bay, chia train/test, huấn luyện/đánh giá, lưu model và chỉ số chất lượng. Đây là chênh lệch rõ nhất giữa tên phân hệ và triển khai hiện tại.
2. **Tài chính/đối soát M12/M20:** danh mục phí/nguồn thu, báo cáo theo kỳ/đường bay, nhập file giao dịch, nhóm nguyên nhân sai lệch và phiếu điều chỉnh có duyệt.
3. **Bảo dưỡng/tổ bay/mặt đất M05/M06/M11:** định mức, vật tư, nhiều chứng chỉ/năng định, ca trực/nghỉ, dịch vụ/nhà cung ứng, thiết bị và bàn giao SLA.
4. **Đơn đặt chỗ/vận chuyển M07/M08/M09/M10:** sửa thông tin khách/tiện ích sau tạo đơn, kiểm tra giấy tờ có lịch sử, mask trường, check-in cấu hình, manifest phiên bản, hạn mức hành lý/thẻ in/hồ sơ thất lạc.
5. **Thông báo/cấu hình/giá M13/M16/M17:** mẫu có phiên bản, SMS sandbox và delivery log, SLA hỗ trợ/bồi thường, cấu hình có audit và luật giá có thời hạn.
6. **Danh mục/lịch/gián đoạn/IoT M01/M02/M04/M15/M19:** ICAO/cấu trúc vị trí, mùa bay lưu được, danh mục loại tàu, biểu đồ và kết nối IoT, ảnh hưởng dây chuyền/phương án thay thế cho hành khách.

Các hạn chế về interline, tiêu chuẩn hàng không được chứng nhận hay merchant thật chỉ nên xét khi phạm vi nghiệm thu yêu cầu; không dùng chúng để phủ nhận các chức năng sandbox đã được tài liệu cho phép. Việc bỏ giao diện tự đặt nhiều chặng là yêu cầu mới của người dùng; vẫn có một chiều/khứ hồi và bay nối chuyến 0/1/2 điểm dừng, không ghi nhận đây là lỗi thiếu chức năng trong lần đối chiếu này.
