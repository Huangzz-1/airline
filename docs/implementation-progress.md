# Phạm vi chức năng và trạng thái triển khai

**Mốc lọc toàn hệ thống:** min/max giá, hành trình và quá cảnh tính trên mọi lịch đã công bố chưa bay, gồm nối chuyến hợp lệ 1–2 điểm dừng; giữ nguyên khi đổi tuyến/ngày/hãng/hạng hoặc lọc không có kết quả. Mốc cập nhật theo dữ liệu lịch/giá, xem [bộ lọc](flight-search.md).

**Bộ lọc khoảng 03/11/2026:** giá, tổng thời gian quá cảnh và toàn hành trình có thanh kéo hai đầu theo dữ liệu thực. Lọc từ–đến ở backend, giữ URL/đặt lại, hỗ trợ chuột/bàn phím/mobile; bay thẳng có 0 phút chờ. Xem [quy tắc](flight-search.md) và [kiểm chứng](validation.md).

**Dữ liệu nhiều hãng 03/11/2026:** thêm 1.008 chuyến trong 14 ngày, 4 hãng, 6 sân bay, tồn/hạng giá/cabin; tìm theo hãng thực tế, 0/1/2 điểm dừng, hiển thị hãng trong preview và hai lượt. Xem [dữ liệu và quy tắc nối chuyến](multi-airline-demo.md). Giá/lịch là mô phỏng, chưa kết nối hãng hoặc Traveloka.

**Bổ sung 03/11/2026:** triển khai [8 nhóm nghiệp vụ](eight-business-features.md), gồm giao diện, API, migration ba nút và kiểm thử. Các số đếm baseline/audit cũ bên dưới không được tự hiểu là đã hoàn thiện toàn bộ 20 phân hệ; tài liệu mới ghi cụ thể phạm vi và giới hạn.

**Thay đổi phạm vi mới nhất:** bỏ đặt nhiều hành khách; mỗi đặt mới chỉ một người ở cả giao diện/API. Bấm Chọn mở hộp xem lịch bay và giá; khứ hồi xem lượt đi rồi chọn về, xem cả hai lượt rồi mới nhập thông tin. Các mô tả 1–9 khách ở baseline cũ bên dưới là lịch sử, không còn áp dụng cho đặt mới.

**Luồng đặt chuyến đi mới 03/11/2026:** thay trình đặt nhiều chặng bằng Một chiều/Khứ hồi; nhãn khứ hồi vàng, lọc 0/1/2 điểm dừng trên chuyến có thật, chọn hai lượt rồi nhập hành khách/liên hệ trước thanh toán. Chi tiết và giới hạn tại [Đặt chuyến đi](trip-booking.md). Phạm vi mới này thay thế các hướng dẫn thêm/bớt nhiều chặng ở những bản cập nhật cũ bên dưới.

**Cập nhật giao diện tìm chuyến 03/11/2026:** trang `/` có thanh tìm kiếm, bộ lọc, giá theo ngày gần đó, sáu kiểu sắp xếp và phân trang phía server; tự cập nhật, hỗ trợ điện thoại và chuyển lựa chọn nhiều khách/hạng vé sang PNR. Phạm vi dữ liệu và API tại [bộ lọc tìm chuyến](flight-search.md).

**Cập nhật 02/11/2026:** đã bổ sung nhiều hành khách chung PNR, khứ hồi/nhiều chặng (tối đa 9 khách, 6 chặng), thanh toán chung với phân bổ thu/hoàn từng vé, sơ đồ cabin theo tàu và snapshot theo chuyến; check-in/đổi tàu kiểm tra đúng khoang. Các giới hạn một khách/một chặng và cabin chung trong baseline 30/10 dưới đây được thay thế theo [phạm vi mới](reservations-and-cabins.md). Vẫn chưa có interline, biểu giá trẻ em/em bé không ghế hoặc sơ đồ hãng được chứng nhận.

Ngày: 2026-10-30. Ứng dụng đã có giao diện khách hàng, nhân viên và quản trị. Bản local dùng SMTP Mailpit, OTP và VNPay/MoMo Mock/chuyển khoản thử nghiệm; không cần dịch vụ bên ngoài. Có thể chuyển kết nối thật bằng cấu hình theo deployment.md; các giới hạn nghiệp vụ còn lại được ghi rõ bên dưới.

## Phạm vi đã triển khai

Mọi hàng dưới đây có giao diện, service/API, bảng dữ liệu sở hữu theo node, phân quyền và kiểm thử thực thi. Các giới hạn vẫn được giữ rõ ở cột cuối.

| Module | Luồng thực tế | Node / vai trò | Phần chưa bao phủ đầy đủ |
|---|---|---|---|
| M01 | Tạo sân bay/tài nguyên; sửa/ngừng dùng bằng phiên bản chờ duyệt độc lập; CSV sân bay nhập nguyên tử vào hàng chờ; xuất danh mục | DB1 / data_admin | Nhập CSV các loại khác; cấu trúc nhiều cấp nhà ga đầy đủ |
| M02 | Đường bay; lịch đơn/lặp theo ngày trong tuần; trình/duyệt đơn hoặc đợt nguyên tử; khóa tàu, kiểm tra crew/rest/gate; outbox mở bán | DB1 / operations, ops_manager | Mỗi đợt lặp tối đa 31 ngày; chưa có bộ tối ưu mùa/slot thực |
| M03 | State machine Scheduled→Boarding→Gate Closed→Departed→Arrived; actual timestamps, audit; chặn đóng khi còn việc bắt buộc và chặn đi khi khách checked-in chưa xử lý | DB1 / operations scoped sân bay | Chưa tích hợp AODB/FIDS thật; override khai thác nâng cao |
| M04 | Hồ sơ/cấu hình, phiên bản sửa có duyệt; sơ đồ cabin theo tàu, snapshot theo chuyến; giờ/chặng cộng một lần khi ARRIVED | DB1 / fleet_manager | Chưa có sơ đồ hãng được chứng nhận, linh kiện và vòng đời tài sản nâng cao |
| M05 | Work order khóa tàu; bằng chứng hoàn tất; người khác giải phóng; còn lệnh chưa giải phóng thì không khả dụng; highlight quá hạn | DB1 / maintenance | Vật tư/kho, chữ ký số và tiêu chuẩn kỹ thuật thật |
| M06 | Hồ sơ/năng định/license/khả dụng; phiên bản thay đổi; phân công kiểm tra năng định, hạn, nhiệm vụ tối đa 12h và nghỉ 12h; duyệt cùng lịch; thay tổ bay sau công bố có duyệt độc lập, giữ lịch sử phân công cũ | DB1 / crew_scheduler, ops_manager | Chưa có portal xác nhận từng thành viên hoặc quy tắc hãng/chứng nhận |
| M07 | Signup/consent, OTP/link xác minh, reset/change password qua SMTP Mailpit và email xếp hàng được mã hóa, profile và hỗ trợ có ownership | DB2 / passenger, customer_service | DPIA, retention/erasure pháp lý đầy đủ |
| M08 | Tìm chuyến, giữ/expire/hủy; PNR tối đa 9 khách/6 chặng, một chiều/khứ hồi/nhiều chặng, giữ toàn hành trình và thanh toán chung; vé riêng từng khách/chặng; đổi vé đã trả có tài chính duyệt, mua mới trước khi hoàn cũ | DB2/3 / passenger, finance | Chưa interline, biểu giá trẻ em/em bé không ghế; thay lịch trước thanh toán bằng hủy PNR và đặt lại |
| M09 | Sơ đồ ghế, cửa sổ check-in, QR opaque/in thẻ; nhân viên tra cứu khách bằng mã đặt chỗ/tên/email và làm thủ tục tại quầy; đổi ghế/cấp lại QR vô hiệu mã cũ; boarding chống trùng/expiry/sai chuyến/sai sân bay; no-show/offload có lý do và lịch sử hành lý | DB2 / passenger, airport_agent | Không offline; camera cần trình duyệt hỗ trợ BarcodeDetector/HTTPS; chưa là IATA BCBP |
| M10 | Tag idempotent; Accepted→Screened→Loaded→Transferred/Delivered; custody/timestamps; Mishandled/Offloaded; truy vết của chính mình hoặc staff đúng sân bay | DB2 / airport_agent | Hành trình nhiều chặng, đối tác BHS/BRS, quy trình bồi thường nâng cao |
| M11 | Giao việc đúng nhân viên/sân bay; SLA; bắt đầu/hoàn tất, bằng chứng; chặn đóng chuyến khi còn việc bắt buộc | DB1 / operations, ground_agent | Critical path tự tối ưu, nhà cung cấp/hợp đồng |
| M12 | VNPay HMAC-SHA512, MoMo HMAC-SHA256, IPN kiểm tra merchant/số tiền; chuyển khoản chờ đối chiếu sao kê; ledger thu/hoàn; tài khoản hoàn mã hóa, duyệt và xác nhận chi độc lập/idempotency | DB3 / finance | Local có VNPay/MoMo Mock; bộ kết nối thật đã có, chưa nghiệm thu merchant thật; hoàn qua ngân hàng do tài chính thực hiện, chưa API hoàn vào ví/thẻ; chưa thuế/ERP |
| M13 | In-app notifications qua sự kiện, read ownership; hỗ trợ/khiếu nại và phản hồi theo lịch sử; mail auth qua SMTP có worker/retry, nội dung Việt hóa | DB2 / passenger, customer_service | Mailpit hoạt động local; SMTP thật đổi cấu hình, chưa SMS/push hoặc bồi thường nâng cao |
| M14 | KPI có mẫu số đủ điều kiện; forecast rules-demo-v1: yếu tố đầu vào, thời gian, phiên bản, giải thích, chỉ hỗ trợ | Tổng hợp / analyst, operations, ops_manager | Không phải ML đã huấn luyện; không có drift/MAE đã đo; độ tin cậy chỉ mô phỏng |
| M15 | Device/threshold; telemetry event ID+sequence unique, late event không đè latest; CLI simulator normal/duplicate/late/alert/disconnect | DB1 / integration_admin scoped | Chưa MQTT/broker thật hoặc agent thiết bị offline có hàng đợi bền |
| M16 | RBAC 15 vai trò; admin local được khởi tạo từ .env, admin tạo nhân viên và bắt đổi mật khẩu tạm, khóa/mở và cấp quyền người khác; audit cả ba node; outbox monitoring; CSRF/session/rate limit; REST; backup 3 node/restore chỉ vào test | Theo node / admin, auditor | MFA/SSO, secret manager, HA, audit sink bất biến ngoài DB, SLA/RTO/RPO chưa nghiệm thu |
| M17 | Nhiều hạng giá với giá/quota riêng, khóa tồn tổng và kiểm tra sức chứa cabin vật lý; TTL, chống ghế cuối, snapshot giá từng vé và tổng tiền PNR | DB2 / data_admin | Các hạng ECONOMY/FLEX dùng chung khoang phổ thông; chưa fare-rule engine/thuế động |
| M18 | Terminal/gate/stand/counter; allocation dưới resource/airport lock, overlap check; đổi/gỡ có audit, gate version + notification | DB1 / operations | Không tối ưu nhiều tài nguyên/công suất chia sẻ tự động |
| M19 | DELAY/CANCEL và đổi tàu có duyệt độc lập; giữ hai tàu, đóng băng tồn, kiểm tra đủ chỗ từng khoang, remap cùng cabin/cấp QR mới; hủy chờ capture rồi refund/revoke QR/offload bags | DB1→2→3 / operations, ops_manager | Đổi tàu chỉ cùng loại, trước BOARDING và chưa có khách BOARDED; chưa khác năng định/tự re-accommodation; trễ khi còn allocation phải điều phối lại |
| M20 | Đối chiếu booking/ticket/payment theo kỳ; mismatch và căn cứ; ghi ngoại lệ có trách nhiệm; người khác khóa kỳ; CSV của kỳ khóa | DB3 + nguồn DB2 / finance | Ngoại lệ không tự sửa tiền nguồn; chưa ERP/đối tác nhiều tiền tệ |

## Chính sách nghiệp vụ hiện tại

- VND nguyên; ECONOMY/FLEX dùng chung khoang phổ thông, BUSINESS/PREMIUM dùng khoang tương ứng khi chuyến có sơ đồ phân khoang. Tổng ghế, quota hạng và sức chứa khoang được kiểm tra dưới inventory lock. Chuyến cũ giữ sơ đồ sáu ghế/hàng để tương thích vé đã có.
- Check-in 24h→30 phút trước giờ bay; boarding 60→10 phút. QR random 256 bit, không PII.
- Hoàn tự nguyện trước 30 phút, toàn bộ giá vé và cần finance duyệt; khoản thật chỉ ghi hoàn sau xác nhận chi qua ngân hàng bởi người khác. Đổi vé đã trả dùng chính sách mua mới toàn bộ/hoàn cũ toàn bộ, người duyệt thấy cả hai số tiền; không tự thu tiền thật.
- Lịch lặp từng đợt ≤31 ngày, batch ≤31 ID. Crew: ít nhất 1 pilot+1 cabin, nhiệm vụ ≤12h, nghỉ ≥12h, turnaround tàu 30 phút. Đây là cấu hình demo, không là quy định được chứng nhận.
- Giữ cửa tự động khi công bố: -45/+15 phút. FlightChanged version cũ/trùng không ghi đè projection mới.
- Đổi tàu: đề nghị ghi snapshot/version; ops_manager khác người lập duyệt. DB1 giữ cả tàu cũ/mới trong suốt chuẩn bị và commit DB2. DB2 chặn mutation trên tồn chỗ, tính cả thanh toán/đổi/hoàn đang chờ; không đủ chỗ thì giữ nguyên tàu, ghế và QR cũ. Khi thành công giữ các ghế hợp lệ trước, chuyển ghế ngoài cấu hình sang ghế trống và thay mọi QR của khách đã check-in. Chỉ mở lại tồn khi DB1 xác nhận hoàn tất; chuyến bị đóng bán thủ công vẫn đóng. Không tăng trần bán khi đổi lên tàu lớn hơn.
- Thay tổ bay: crew_scheduler đề nghị cả danh sách, ops_manager duyệt độc lập và kiểm tra lại năng định/license/nghỉ/xung đột. Phân công cũ chuyển inactive; snapshot và audit lưu trong phương án. Giờ bay, vé, ghế và QR không đổi.

## Chứng cứ và giới hạn kiểm chứng

**Lượt cuối: 88 PHP tests / 780 assertions, 11 E2E qua; backup/restore 49 bảng khớp counts và dữ liệu ứng dụng không đổi.** Xem [validation.md](validation.md) cho bằng chứng và phạm vi kiểm chứng. Kiểm thử bao gồm ba server UUID độc lập, đảo mạng TCP, replay sau mất ack, last-seat hai tiến trình, quyền theo đối tượng/sân bay, Saga đổi/hoàn/hủy/đổi tàu, thay tổ bay, CSV staging/rollback và UI thật. Không coi unit/integration/E2E là thay thế UAT hàng không, pentest, stress test hoặc kiểm thử HA.

Lịch sử increment đầu được giữ trong [archive/increment-1.md](archive/increment-1.md). Không reset dữ liệu ứng dụng để chạy test. Các fixture onboarding cũ đã được sao lưu và dọn bằng công cụ có guard sau khi xác minh chưa có tài khoản, giao dịch hoặc hoạt động người dùng; dữ liệu mẫu chỉ còn trong DB test. Mặc định không seed mẫu. Trước vận hành thật: cấu hình dịch vụ theo deployment.md, đánh giá quy tắc với đơn vị khai thác và nghiệm thu UAT/an ninh/tải/DR. Không đồng nhất việc có đủ màn hình với nghiệm thu toàn bộ đặc tả.
