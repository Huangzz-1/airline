# Yêu cầu và baseline (2026-10-30)

Đã đọc prompt, toàn bộ văn bản của cả 10 DOCX và rà soát các sơ đồ nhúng; bản trích xuất đầy đủ nằm trong `docs/sources/` để tra cứu. Tài liệu khảo sát là mô phỏng học thuật, không phải quy tắc được chứng nhận cho khai thác hàng không thật.

## Căn cứ

| Nguồn | Nội dung dùng để thiết kế |
|---|---|
| Biên bản thu thập thông tin | Actor, điểm bàn giao, trạng thái chuyến/khách/hành lý |
| Business Analysis | FR-001–074, BR-001–025, NFR-001–018, M17–20 bổ sung |
| UseCase và đặc tả | UC-01–48; M17-UC03/04; M18–20 là đề xuất mới |
| Kiến trúc tổng thể | MVC, Modular Monolith, Laravel/MySQL, backend sở hữu nghiệp vụ |
| Activity/Sequence 6 module đầu | M01–06, xung đột lịch và phê duyệt kỹ thuật |
| MD2 Activity/Sequence | UC-18–39, check-in, thanh toán, QR, hành lý |
| Form phỏng vấn | Nhân viên check-in/boarding/hành lý/điều hành; từ chối quét lặp |
| Form workshop | Scheduled→Boarding→Gate Closed→Departed→Arrived; Tagged→Accepted→Screened→Loaded |
| Form quan sát | Trình tự kiểm tra booking, giấy tờ, ghế, hành lý; ngoại lệ |
| Form biểu mẫu/log | Khóa thống nhất, timestamp, custody, audit và chất lượng dữ liệu |

## Yêu cầu gốc

M01–M16 và phần mở rộng M17–M20: dữ liệu nền, lịch bay, khai thác, đội bay, bảo dưỡng, tổ bay, hành khách, booking, check-in/boarding, hành lý, mặt đất, tài chính, chăm sóc, AI/KPI, IoT, quản trị, giá/tồn, tài nguyên, gián đoạn, đối soát. Kiểm tra quyền và chủ sở hữu từng đối tượng; không phát hành/boarding trùng; UTC trong DB và Asia/Ho_Chi_Minh khi hiển thị; không lưu dữ liệu thẻ; AI chỉ hỗ trợ; IoT không điều khiển thiết bị thật.

## Bổ sung bắt buộc từ prompt

Ba MySQL **server** độc lập: DB1 vận hành, DB2 hành khách/thương mại, DB3 tài chính. Không FK xuyên node. Backend phối hợp qua service; giao dịch cục bộ, outbox, retry, idempotency và Saga bù trừ. Đây là thay đổi so với kiến trúc một MySQL/XAMPP trong bản DOCX.

## Bản mô phỏng tích hợp hiện tại

Đã triển khai các luồng cơ bản M01–M20: master/CSV/version approval, lịch đơn/lặp và công bố, khai thác, MRO/crew, hành khách, booking/đổi/hoàn, QR, baggage/ground, tài chính/đối soát, support/notifications, KPI/AI và IoT mô phỏng, RBAC/audit/backup. Ma trận phạm vi chính xác, test mapping và các phần chưa bao phủ nằm trong [implementation-progress.md](implementation-progress.md).

Các chính sách demo bổ sung được ghi rõ: một khách/một chặng mỗi booking; nhiều fare bucket cùng cabin; hold 10 phút; check-in 24h→30 phút; boarding 60→10 phút; full sandbox refund trước 30 phút; paid exchange mua mới/hoàn cũ có finance duyệt; lịch lặp từng đợt ≤31 ngày; crew nghỉ 12h/nhiệm vụ ≤12h; giữ gate -45/+15 phút. Không coi các con số này là quy tắc đã được một hãng hàng không chứng nhận.

Không suy ra đầy đủ toàn bộ FR/NFR hoặc production readiness từ việc có đủ 20 phân hệ. Những tích hợp thật, nhiều khách/chặng, đổi tàu sau công bố, offline, MFA/HA và quy tắc tổ chức còn thiếu được giữ rõ; không tự công bố hoặc push GitHub.
