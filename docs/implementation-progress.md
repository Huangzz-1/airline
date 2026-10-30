# Phạm vi chức năng và trạng thái triển khai

Ngày: 2026-10-30. Ứng dụng đã có giao diện khách hàng, nhân viên và quản trị. Bản local dùng SMTP Mailpit, OTP và VNPay/MoMo Mock/chuyển khoản thử nghiệm; không cần dịch vụ bên ngoài. Có thể chuyển kết nối thật bằng cấu hình theo deployment.md; các giới hạn nghiệp vụ còn lại được ghi rõ bên dưới.

## Phạm vi đã triển khai

Mọi hàng dưới đây có giao diện, service/API, bảng dữ liệu sở hữu theo node, phân quyền và kiểm thử thực thi. Các giới hạn vẫn được giữ rõ ở cột cuối.

| Module | Luồng thực tế | Node / vai trò | Phần chưa bao phủ đầy đủ |
|---|---|---|---|
| M01 | Tạo sân bay/tài nguyên; sửa/ngừng dùng bằng phiên bản chờ duyệt độc lập; CSV sân bay nhập nguyên tử vào hàng chờ; xuất danh mục | DB1 / data_admin | Nhập CSV các loại khác; cấu trúc nhiều cấp nhà ga đầy đủ |
| M02 | Đường bay; lịch đơn/lặp theo ngày trong tuần; trình/duyệt đơn hoặc đợt nguyên tử; khóa tàu, kiểm tra crew/rest/gate; outbox mở bán | DB1 / operations, ops_manager | Mỗi đợt lặp tối đa 31 ngày; chưa có bộ tối ưu mùa/slot thực |
| M03 | State machine Scheduled→Boarding→Gate Closed→Departed→Arrived; actual timestamps, audit; chặn đóng khi còn việc bắt buộc và chặn đi khi khách checked-in chưa xử lý | DB1 / operations scoped sân bay | Chưa tích hợp AODB/FIDS thật; override khai thác nâng cao |
| M04 | Hồ sơ/cấu hình, phiên bản sửa có duyệt; giờ/chặng cộng một lần khi ARRIVED | DB1 / fleet_manager | Nhiều cabin/seat map riêng, linh kiện và vòng đời tài sản nâng cao |
| M05 | Work order khóa tàu; bằng chứng hoàn tất; người khác giải phóng; còn lệnh chưa giải phóng thì không khả dụng; highlight quá hạn | DB1 / maintenance | Vật tư/kho, chữ ký số và tiêu chuẩn kỹ thuật thật |
| M06 | Hồ sơ/năng định/license/khả dụng; phiên bản thay đổi; phân công kiểm tra năng định, hạn, nhiệm vụ tối đa 12h và nghỉ 12h; duyệt cùng lịch; thay tổ bay sau công bố có duyệt độc lập, giữ lịch sử phân công cũ | DB1 / crew_scheduler, ops_manager | Chưa có portal xác nhận từng thành viên hoặc quy tắc hãng/chứng nhận |
| M07 | Signup/consent, OTP/link xác minh, reset/change password qua SMTP Mailpit và email xếp hàng được mã hóa, profile và hỗ trợ có ownership | DB2 / passenger, customer_service | DPIA, retention/erasure pháp lý đầy đủ |
| M08 | Tìm chuyến, giữ/expire/hủy; đổi giữ chỗ không tăng TTL; VNPay/MoMo/chuyển khoản qua Saga, vé duy nhất/in vé; đổi vé đã trả có tài chính duyệt, khách thanh toán vé mới trước khi hoàn vé cũ, thất bại bảo toàn vé cũ | DB2/3 / passenger, finance | Một khách/một chặng; không có interline, nhiều khách/chặng trong một PNR |
| M09 | Sơ đồ ghế, cửa sổ check-in, QR opaque/in thẻ; nhân viên tra cứu khách bằng mã đặt chỗ/tên/email và làm thủ tục tại quầy; đổi ghế/cấp lại QR vô hiệu mã cũ; boarding chống trùng/expiry/sai chuyến/sai sân bay; no-show/offload có lý do và lịch sử hành lý | DB2 / passenger, airport_agent | Không offline; camera cần trình duyệt hỗ trợ BarcodeDetector/HTTPS; chưa là IATA BCBP |
| M10 | Tag idempotent; Accepted→Screened→Loaded→Transferred/Delivered; custody/timestamps; Mishandled/Offloaded; truy vết của chính mình hoặc staff đúng sân bay | DB2 / airport_agent | Hành trình nhiều chặng, đối tác BHS/BRS, quy trình bồi thường nâng cao |
| M11 | Giao việc đúng nhân viên/sân bay; SLA; bắt đầu/hoàn tất, bằng chứng; chặn đóng chuyến khi còn việc bắt buộc | DB1 / operations, ground_agent | Critical path tự tối ưu, nhà cung cấp/hợp đồng |
| M12 | VNPay HMAC-SHA512, MoMo HMAC-SHA256, IPN kiểm tra merchant/số tiền; chuyển khoản chờ đối chiếu sao kê; ledger thu/hoàn; tài khoản hoàn mã hóa, duyệt và xác nhận chi độc lập/idempotency | DB3 / finance | Local có VNPay/MoMo Mock; bộ kết nối thật đã có, chưa nghiệm thu merchant thật; hoàn qua ngân hàng do tài chính thực hiện, chưa API hoàn vào ví/thẻ; chưa thuế/ERP |
| M13 | In-app notifications qua sự kiện, read ownership; hỗ trợ/khiếu nại và phản hồi theo lịch sử; mail auth qua SMTP có worker/retry, nội dung Việt hóa | DB2 / passenger, customer_service | Mailpit hoạt động local; SMTP thật đổi cấu hình, chưa SMS/push hoặc bồi thường nâng cao |
| M14 | KPI có mẫu số đủ điều kiện; forecast rules-demo-v1: yếu tố đầu vào, thời gian, phiên bản, giải thích, chỉ hỗ trợ | Tổng hợp / analyst, operations, ops_manager | Không phải ML đã huấn luyện; không có drift/MAE đã đo; độ tin cậy chỉ mô phỏng |
| M15 | Device/threshold; telemetry event ID+sequence unique, late event không đè latest; CLI simulator normal/duplicate/late/alert/disconnect | DB1 / integration_admin scoped | Chưa MQTT/broker thật hoặc agent thiết bị offline có hàng đợi bền |
| M16 | RBAC 15 vai trò; admin local được khởi tạo từ .env, admin tạo nhân viên và bắt đổi mật khẩu tạm, khóa/mở và cấp quyền người khác; audit cả ba node; outbox monitoring; CSRF/session/rate limit; REST; backup 3 node/restore chỉ vào test | Theo node / admin, auditor | MFA/SSO, secret manager, HA, audit sink bất biến ngoài DB, SLA/RTO/RPO chưa nghiệm thu |
| M17 | Nhiều hạng giá với giá/quota riêng, cùng khóa tồn tổng; TTL, chống ghế cuối, không giảm quota dưới số đã giữ/bán, snapshot giá booking | DB2 / data_admin | Fare bucket dùng chung cabin/sơ đồ ghế; không fare-rule engine/thuế động |
| M18 | Terminal/gate/stand/counter; allocation dưới resource/airport lock, overlap check; đổi/gỡ có audit, gate version + notification | DB1 / operations | Không tối ưu nhiều tài nguyên/công suất chia sẻ tự động |
| M19 | DELAY/CANCEL và đổi tàu có duyệt độc lập; kiểm tra tàu/crew/resource; đổi tàu qua outbox, giữ hai tàu và đóng băng tồn chỗ, remap ghế/cấp QR mới, retry sau mất mạng; hủy chờ capture rồi refund/revoke QR/offload bags | DB1→2→3 / operations, ops_manager | Đổi tàu chỉ cùng loại, trước BOARDING, đủ chỗ và chưa có khách BOARDED; chưa nhiều cabin/khác năng định/tự re-accommodation; trễ khi còn allocation phải điều phối lại |
| M20 | Đối chiếu booking/ticket/payment theo kỳ; mismatch và căn cứ; ghi ngoại lệ có trách nhiệm; người khác khóa kỳ; CSV của kỳ khóa | DB3 + nguồn DB2 / finance | Ngoại lệ không tự sửa tiền nguồn; chưa ERP/đối tác nhiều tiền tệ |

## Chính sách nghiệp vụ hiện tại

- VND nguyên; hạng giá là bucket thương mại dùng chung cabin. Tổng ghế và quota hạng đều được kiểm tra dưới inventory lock.
- Check-in 24h→30 phút trước giờ bay; boarding 60→10 phút. QR random 256 bit, không PII.
- Hoàn tự nguyện trước 30 phút, toàn bộ giá vé và cần finance duyệt; khoản thật chỉ ghi hoàn sau xác nhận chi qua ngân hàng bởi người khác. Đổi vé đã trả dùng chính sách mua mới toàn bộ/hoàn cũ toàn bộ, người duyệt thấy cả hai số tiền; không tự thu tiền thật.
- Lịch lặp từng đợt ≤31 ngày, batch ≤31 ID. Crew: ít nhất 1 pilot+1 cabin, nhiệm vụ ≤12h, nghỉ ≥12h, turnaround tàu 30 phút. Đây là cấu hình demo, không là quy định được chứng nhận.
- Giữ cửa tự động khi công bố: -45/+15 phút. FlightChanged version cũ/trùng không ghi đè projection mới.
- Đổi tàu: đề nghị ghi snapshot/version; ops_manager khác người lập duyệt. DB1 giữ cả tàu cũ/mới trong suốt chuẩn bị và commit DB2. DB2 chặn mutation trên tồn chỗ, tính cả thanh toán/đổi/hoàn đang chờ; không đủ chỗ thì giữ nguyên tàu, ghế và QR cũ. Khi thành công giữ các ghế hợp lệ trước, chuyển ghế ngoài cấu hình sang ghế trống và thay mọi QR của khách đã check-in. Chỉ mở lại tồn khi DB1 xác nhận hoàn tất; chuyến bị đóng bán thủ công vẫn đóng. Không tăng trần bán khi đổi lên tàu lớn hơn.
- Thay tổ bay: crew_scheduler đề nghị cả danh sách, ops_manager duyệt độc lập và kiểm tra lại năng định/license/nghỉ/xung đột. Phân công cũ chuyển inactive; snapshot và audit lưu trong phương án. Giờ bay, vé, ghế và QR không đổi.

## Chứng cứ và giới hạn kiểm chứng

**Lượt cuối: 88 PHP tests / 780 assertions, 11 E2E qua; backup/restore 49 bảng khớp counts và dữ liệu ứng dụng không đổi.** Xem [validation.md](validation.md) cho bằng chứng và phạm vi kiểm chứng. Kiểm thử bao gồm ba server UUID độc lập, đảo mạng TCP, replay sau mất ack, last-seat hai tiến trình, quyền theo đối tượng/sân bay, Saga đổi/hoàn/hủy/đổi tàu, thay tổ bay, CSV staging/rollback và UI thật. Không coi unit/integration/E2E là thay thế UAT hàng không, pentest, stress test hoặc kiểm thử HA.

Lịch sử increment đầu được giữ trong [archive/increment-1.md](archive/increment-1.md). Không reset dữ liệu ứng dụng để chạy test. Các fixture onboarding cũ đã được sao lưu và dọn bằng công cụ có guard sau khi xác minh chưa có tài khoản, giao dịch hoặc hoạt động người dùng; dữ liệu mẫu chỉ còn trong DB test. Mặc định không seed mẫu. Trước vận hành thật: cấu hình dịch vụ theo deployment.md, đánh giá quy tắc với đơn vị khai thác và nghiệm thu UAT/an ninh/tải/DR. Không đồng nhất việc có đủ màn hình với nghiệm thu toàn bộ đặc tả.
