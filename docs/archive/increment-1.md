# Tiến độ thực thi — increment 1

Baseline: prompt_codex_hang_khong.txt + 10 DOCX trong docs/sources; đã trích xuất toàn bộ văn bản và xem sơ đồ nhúng. Repository ban đầu chỉ có README. Mục tiêu phiên đầu là một luồng chạy được theo điều khoản chia nhiều phiên của prompt, không phải tuyên bố đủ 20 phân hệ.

| Module | Đã viết trong increment 1 | Còn thiếu |
|---|---|---|
| M01 | Airport seed + public lookup, DB1 | CRUD, nhà ga, cửa/vị trí đỗ, phê duyệt/version |
| M02 | Lịch/chuyến seed để demo | Route/schedule CRUD, xung đột, approval, mùa lịch |
| M03 | Đọc trạng thái chuyến để chặn giao dịch | State transitions, mốc thực tế, trễ/hủy, notification |
| M04 | Aircraft seed liên kết flight | CRUD, hồ sơ, khả dụng, hours/cycles |
| M05 | Chưa triển khai | Toàn bộ work order, hạn, giải phóng kỹ thuật |
| M06 | Chưa triển khai | Năng định, duty/rest, phân công/phê duyệt |
| M07 | Đăng ký, consent, hồ sơ mình, email verify, reset/password | Nhu cầu hỗ trợ, retention/erasure, CSKH scoped |
| M08 | Search, hold/expire/cancel trước trả tiền, sandbox Saga, ticket | Đa khách/đa chặng/dịch vụ, đổi/hoàn tiền sau phát hành |
| M09 | Check-in window, seat lock, opaque QR, boarding role/scope/expiry/dedup | Đổi ghế/cấp lại QR, no-show/offload, offline, camera |
| M10 | Chưa triển khai | Tag, scans/custody, truy vết/ngoại lệ |
| M11 | Chưa triển khai | Nhiệm vụ, SLA, timeline, bằng chứng |
| M12 | Payment DB3, danh sách giao dịch có quyền | Ledger đầy đủ, phí/thuế, refunds, ERP |
| M13 | Mail sandbox cho verify/reset | Thông báo booking/chuyến, khiếu nại, đa kênh |
| M14 | KPI cục bộ boarding | Dashboard đa miền, AI simulator + giải thích |
| M15 | Chưa triển khai | Simulator/telemetry trùng/trễ/sai thứ tự |
| M16 | Auth/session/CSRF/RBAC, user role UI, audit, health, first-party API | MFA, scoped permissions chi tiết, cấu hình, backup/DR |
| M17 | Một hạng giá, inventory, TTL, locks, idempotency | CRUD fare class/rules, đồng bộ thay đổi capacity từ DB1 |
| M18 | Chưa triển khai | Resource allocation + overlap lock/approval |
| M19 | Chưa triển khai | Disruption/recovery approval và sự kiện liên miền |
| M20 | Đối chiếu read-only booking/ticket/payment | Hồ sơ sai lệch, remediation, khóa kỳ/export |

## File và nền tảng

- `compose.yaml`, `docker/Dockerfile`: app/worker, ba MySQL server/volume.
- `backend/database/migrations`, `seeders`: dữ liệu/khóa/unique theo chủ sở hữu.
- `backend/app/Modules`: catalog, booking/inventory, boarding, outbox finance, audit.
- `backend/app/Http`, `routes`, `resources/views`: public, passenger, staff, admin, finance và API.
- `scripts`: setup noninteractive, local secrets, tests DB riêng, E2E server riêng.
- `docs`: yêu cầu gốc/bổ sung, kiến trúc, ERD, RBAC, API, nguồn.

## Kết quả kiểm chứng

Ngày kiểm chứng: 2026-10-30. Chi tiết tại [validation.md](validation.md).

- `scripts/setup.sh`: chạy thực tế và chạy lại thành công; migration báo Nothing to migrate; seed không thay đổi bản ghi đã có.
- `scripts/test.sh`: **24 passed, 102 assertions**, không skipped/disabled (14 integration + 10 data cases unit).
- `scripts/e2e.sh`: **2 passed** trên Chromium hệ thống, form thực có CSRF, đăng ký/verify/hold/pay/check-in/QR/boarding và mobile.
- Topology kiểm tra bằng ba server UUID khác nhau; query xác nhận bảng thuộc đúng node.
- Hai tiến trình tranh ghế cuối: đúng một HELD, một 409. Replay payment/outbox: một charge và một ticket. Lỗi TCP finance → PAYMENT_PENDING → replay sau phục hồi → CONFIRMED.
- Tài khoản test riêng bị từ chối đọc `airline.users`; dọn test chỉ nhắm schema `airline_test`. Dữ liệu demo sau test vẫn có 7 flights, 7 inventory và 4 migration records.
- `composer validate --strict --no-check-publish`: hợp lệ. PHP lint và Pint đã chạy. HTTP `/health/ready` và catalog trả 200.
- Đã lưu `install_script` và `start_skill` trong draft môi trường. **Chưa Publish**, chưa kiểm chứng phục hồi snapshot trong một task mới.
- Ảnh desktop/mobile từ trình duyệt thực: `docs/screenshots/`.

Các lỗi phát hiện/sửa trong phiên: dev server Laravel lọc biến injected (thêm --no-reload); role mặc định trong model mới tạo; dọn test liệt kê tất cả schema thay vì schema hiện tại (đã giới hạn schema và tách username). Dữ liệu demo do phiên này tạo bị dọn ở lượt đầu đã được khôi phục, sau đó kiểm chứng không bị ảnh hưởng bởi bộ test cuối.

## Điểm tiếp tục

1. Hoàn thiện M01–06 và đồng bộ FlightPublished/FlightChanged qua outbox DB1→DB2 để tạo chuyến và inventory qua UI; seed không là chức năng quản trị.
2. M10 nối hành lý sau check-in, M11 mặt đất, M18 tài nguyên.
3. M19 gián đoạn có approval và Saga/compensation liên miền; refund sandbox và M20 hồ sơ sai lệch/khóa kỳ.
4. M13 đầy đủ; M14/15 simulator có giải thích, không tác động an toàn thật.
5. Security/load/UAT, backup restore, accessibility, production readiness.
