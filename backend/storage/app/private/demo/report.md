# Bộ dữ liệu thử nghiệm sân bay

Tạo lúc 17:42 02/11/2026 (giờ Việt Nam).

Tất cả hành khách, nhân viên, chuyến bay, số đăng ký tàu, giao dịch và tài khoản ngân hàng là mô phỏng. Tên sân bay được dùng làm danh mục tham khảo; thời gian bay, khoảng cách, sức chứa và giá là giả định kiểm thử.

Ứng dụng: http://localhost:8000 — Mailpit: http://localhost:8025

Mật khẩu chung: xem `LOCAL_DEMO_PASSWORD` trong `backend/.env`. Tài khoản quản trị cũ giữ nguyên.

## Tài khoản chính

| Email | Vai trò | Sân bay |
|---|---|---|
| demo.airport_agent.han@airline.test | airport_agent | HAN |
| demo.operations.han@airline.test | operations | HAN |
| demo.ops_manager.han@airline.test | ops_manager | HAN |
| demo.ground_agent.han@airline.test | ground_agent | HAN |
| demo.integration_admin.han@airline.test | integration_admin | HAN |
| demo.airport_agent.sgn@airline.test | airport_agent | SGN |
| demo.operations.sgn@airline.test | operations | SGN |
| demo.ops_manager.sgn@airline.test | ops_manager | SGN |
| demo.ground_agent.sgn@airline.test | ground_agent | SGN |
| demo.integration_admin.sgn@airline.test | integration_admin | SGN |
| demo.airport_agent.dad@airline.test | airport_agent | DAD |
| demo.operations.dad@airline.test | operations | DAD |
| demo.ops_manager.dad@airline.test | ops_manager | DAD |
| demo.ground_agent.dad@airline.test | ground_agent | DAD |
| demo.integration_admin.dad@airline.test | integration_admin | DAD |
| demo.admin@airline.test | admin | Toàn hệ thống |
| demo.data_admin@airline.test | data_admin | Toàn hệ thống |
| demo.fleet_manager@airline.test | fleet_manager | Toàn hệ thống |
| demo.maintenance@airline.test | maintenance | Toàn hệ thống |
| demo.crew_scheduler@airline.test | crew_scheduler | Toàn hệ thống |
| demo.finance@airline.test | finance | Toàn hệ thống |
| demo.customer_service@airline.test | customer_service | Toàn hệ thống |
| demo.analyst@airline.test | analyst | Toàn hệ thống |
| demo.auditor@airline.test | auditor | Toàn hệ thống |
| demo.data_admin.2@airline.test | data_admin | Toàn hệ thống |
| demo.fleet_manager.2@airline.test | fleet_manager | Toàn hệ thống |
| demo.maintenance.2@airline.test | maintenance | Toàn hệ thống |
| demo.crew_scheduler.2@airline.test | crew_scheduler | Toàn hệ thống |
| demo.finance.2@airline.test | finance | Toàn hệ thống |
| demo.customer_service.2@airline.test | customer_service | Toàn hệ thống |
| demo.maintenance.3@airline.test | maintenance | Toàn hệ thống |
| demo.staff.temporary@airline.test | airport_agent | HAN |
| demo.staff.disabled@airline.test | airport_agent | HAN |
| demo.passenger.unverified@airline.test | passenger | Toàn hệ thống |
| demo.passenger@airline.test | passenger | Toàn hệ thống |
| demo.passenger.2@airline.test | passenger | Toàn hệ thống |
| demo.passenger.3@airline.test | passenger | Toàn hệ thống |

Danh sách đầy đủ: `accounts.csv` cùng thư mục; các tài khoản ở sân bay khác dùng mẫu `demo.VAI_TRO.ma_san_bay@airline.test`.

## Tình huống sẵn sàng

### checkin

Vé đã thanh toán, có thể check-in, chọn ghế và in vé.

- reference: `SHOHA8V3`
- booking_id: `39da67cb-0f43-4153-aa00-eb948c030418`
- flight: `AS9902`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/39da67cb-0f43-4153-aa00-eb948c030418

### boarding

Đã check-in ghế 2A, QR còn hiệu lực để quét lên tàu; hành lý đã soi chiếu.

- reference: `5JBTWOOV`
- booking_id: `72350b05-ed42-45d3-9261-1dcf5ed86665`
- flight: `AS9901`
- baggage_tag: `BGHVB1HWWTEVHG`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/72350b05-ed42-45d3-9261-1dcf5ed86665

### boarded

Khách đã lên tàu, QR quét lại sẽ bị từ chối.

- reference: `2DNR1G7A`
- booking_id: `38d9b853-6acf-4088-be33-3ba48ab1afdd`
- email: `demo.passenger.2@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/38d9b853-6acf-4088-be33-3ba48ab1afdd

### mishandled

Hành lý thất lạc cần xử lý và bàn giao lại.

- reference: `CU1GNRY9`
- booking_id: `d902b30c-59ba-4bd1-9848-ade017ce4674`
- baggage_tag: `BGQ07LOTVTPLOL`
- email: `demo.passenger.3@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/d902b30c-59ba-4bd1-9848-ade017ce4674

### bank

Khách đã báo chuyển khoản, tài chính chờ đối chiếu sao kê.

- reference: `YXL3XW2R`
- booking_id: `401b1e58-5837-491d-a6da-5ce40be93e78`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/401b1e58-5837-491d-a6da-5ce40be93e78

### refund-requested

Hoàn tiền: requested; tài khoản nhận hoàn là dữ liệu giả.

- reference: `PCPUHKH3`
- booking_id: `a4374787-1942-4492-ba5f-d9656a6ab78f`
- refund_id: `1`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/a4374787-1942-4492-ba5f-d9656a6ab78f

### refund-approved

Hoàn tiền: approved; tài khoản nhận hoàn là dữ liệu giả.

- reference: `5ZWW7WJV`
- booking_id: `8b5bf5f7-d6da-49f6-8e39-6844e833d862`
- refund_id: `2`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/8b5bf5f7-d6da-49f6-8e39-6844e833d862

### refund-refunded

Hoàn tiền: refunded; tài khoản nhận hoàn là dữ liệu giả.

- reference: `PIWTDKW3`
- booking_id: `62de264d-0ac3-4f52-91b1-17b8d7b7b0b2`
- refund_id: `3`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/62de264d-0ac3-4f52-91b1-17b8d7b7b0b2

### refund-rejected

Hoàn tiền: rejected; tài khoản nhận hoàn là dữ liệu giả.

- reference: `QRVKSYJX`
- booking_id: `d5b34ca8-cdef-404d-9706-2ca0135ef160`
- refund_id: `4`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/d5b34ca8-cdef-404d-9706-2ca0135ef160

### exchange-pending

Đổi vé cùng HAN–SGN: pending.

- reference: `4QS02NED`
- booking_id: `9ea0d41a-f60e-4a77-9880-823171876a14`
- exchange_id: `420d4b50-704c-40e0-b3a4-7287104dd386`
- target_booking_id: `68c2288f-0f4f-4a2c-a22a-c6fbd8cb2b79`
- email: `demo.passenger.2@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/9ea0d41a-f60e-4a77-9880-823171876a14

### exchange-processing

Đổi vé cùng HAN–SGN: processing.

- reference: `ZK4VURQF`
- booking_id: `fffcebf0-6505-4dfa-9c6c-051cf3c4f004`
- exchange_id: `98ab2664-814a-4f66-b232-c5c11112e0f1`
- target_booking_id: `2eb04dae-c818-4796-9714-6f1686746934`
- email: `demo.passenger.2@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/fffcebf0-6505-4dfa-9c6c-051cf3c4f004

### exchange-completed

Đổi vé cùng HAN–SGN: completed.

- reference: `EFPOST6K`
- booking_id: `73e7b2e7-3455-4a45-afef-a057589ec7d4`
- exchange_id: `bbb89f2f-2552-4f23-9746-5a08a3eb27dd`
- target_booking_id: `fade37f6-5fd8-4db2-ba61-655cd24bfe8f`
- email: `demo.passenger.2@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/73e7b2e7-3455-4a45-afef-a057589ec7d4

### exchange-rejected

Đổi vé cùng HAN–SGN: rejected.

- reference: `OFPMZC3H`
- booking_id: `d9e186d7-5fea-4e3a-ad85-8760432d5f48`
- exchange_id: `f8de6d70-e34c-4ff5-b605-0f765968fd0d`
- target_booking_id: `fba1ff52-5a7d-4898-833e-3478a1f82ae5`
- email: `demo.passenger.2@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/d9e186d7-5fea-4e3a-ad85-8760432d5f48

### schedule-draft

Lịch DRAFT có tổ bay hợp lệ, chờ trình/duyệt.

- flight: `AS9910`
- flight_id: `533`

### schedule-pending

Lịch PENDING có tổ bay hợp lệ, chờ trình/duyệt.

- flight: `AS9911`
- flight_id: `534`

### change-aircraft

Phương án thay tàu chờ quản lý duyệt độc lập.

- flight: `AS9903`
- flight_id: `531`
- change_id: `3afcaf02-7597-45c6-9e2c-566eefaa8d3f`

### change-crew

Phương án thay tổ bay chờ quản lý duyệt độc lập.

- flight: `AS9902`
- flight_id: `530`
- change_id: `c26691a6-11c8-49e0-a6f1-8506d1379245`

### disruption-delay

Phương án chậm 45 phút chờ duyệt.

- flight: `AS9920`
- flight_id: `535`
- disruption_id: `1`

### disruption-cancel

Phương án hủy chuyến chờ duyệt.

- flight: `AS9921`
- flight_id: `536`
- disruption_id: `2`

### maintenance-open

Lệnh bảo dưỡng OPEN, ba kỹ thuật viên độc lập.

- aircraft: `VN-D301`
- work_order_id: `1`

### maintenance-completed

Lệnh bảo dưỡng COMPLETED, ba kỹ thuật viên độc lập.

- aircraft: `VN-D302`
- work_order_id: `2`

### maintenance-released

Lệnh bảo dưỡng RELEASED, ba kỹ thuật viên độc lập.

- aircraft: `VN-D303`
- work_order_id: `3`

### master-airports

Phiên bản danh mục airports chờ người thứ hai duyệt.

- change_id: `1`
- record_id: `VDO`

### master-routes

Phiên bản danh mục routes chờ người thứ hai duyệt.

- change_id: `2`
- record_id: `39`

### master-airport_resources

Phiên bản danh mục airport_resources chờ người thứ hai duyệt.

- change_id: `3`
- record_id: `50`

### master-aircraft

Phiên bản danh mục aircraft chờ người thứ hai duyệt.

- change_id: `4`
- record_id: `17`

### master-crew_members

Phiên bản danh mục crew_members chờ người thứ hai duyệt.

- change_id: `5`
- record_id: `181`

### ground-tasks

Nhiệm vụ mặt đất ở ba trạng thái; có việc quá hạn để kiểm tra cảnh báo.

- task_ids: `1, 2, 3, 4, 5, 6`

### support-open

Yêu cầu hỗ trợ OPEN có lịch sử hội thoại.

- case_id: `1`
- email: `demo.passenger@airline.test`

### support-in_progress

Yêu cầu hỗ trợ IN_PROGRESS có lịch sử hội thoại.

- case_id: `2`
- email: `demo.passenger.2@airline.test`

### support-resolved

Yêu cầu hỗ trợ RESOLVED có lịch sử hội thoại.

- case_id: `3`
- email: `demo.passenger.3@airline.test`

### iot

Thiết bị mô phỏng có dữ liệu bình thường, vượt ngưỡng và đến trễ.

- device_ids: `1, 2, 3, 4, 5, 6`

### reconciliation-closed

Kỳ lịch sử khớp số liệu và đã khóa bởi người thứ hai.

- run_id: `1`
- period: `2026-10-26`

### reconciliation-open

Kỳ hiện tại có giao dịch đang xử lý để thử phân loại sai lệch và khóa kỳ.

- run_id: `2`

### flight-milestones

Các chuyến đã đóng cửa, đang bay và đã đến để thử hoàn tất khai thác.

- GATE_CLOSED: `AS9930`
- DEPARTED: `AS9931`
- ARRIVED: `AS9932`

### flight-cancelled

Chuyến đã hủy, vé được chuyển sang quy trình hoàn tiền tự động.

- flight: `AS9940`
- reference: `HWRVYUOB`
- booking_id: `e08d7eb4-d7d6-461e-a30c-4ea2a0f79ba1`
- email: `demo.passenger.3@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/e08d7eb4-d7d6-461e-a30c-4ea2a0f79ba1

### flight-delayed

Chuyến đã chậm 60 phút, cần phân bổ cửa theo giờ mới.

- flight: `AS9941`
- flight_id: `541`

### booking-held

Đặt chỗ thử nghiệm: held.

- reference: `G5NHRF4T`
- booking_id: `14536963-fb41-4f31-aeb4-d79283f13099`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/14536963-fb41-4f31-aeb4-d79283f13099

### booking-expired

Đặt chỗ thử nghiệm: expired.

- reference: `IBCQQO1O`
- booking_id: `fb3ac46a-54d1-4c08-bd13-c208470e47f8`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/fb3ac46a-54d1-4c08-bd13-c208470e47f8

### booking-cancelled

Đặt chỗ thử nghiệm: cancelled.

- reference: `QLBX8Y7C`
- booking_id: `613ebce5-1e25-48ba-a471-85552db95d29`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/613ebce5-1e25-48ba-a471-85552db95d29

### booking-declined

Đặt chỗ thử nghiệm: declined.

- reference: `6AVIN1MS`
- booking_id: `6fad63d2-4ea4-4f11-b981-e78cb4c1cbb8`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/6fad63d2-4ea4-4f11-b981-e78cb4c1cbb8

### booking-momo-ready

Đặt chỗ thử nghiệm: momo-ready.

- reference: `Z5VBY40I`
- booking_id: `98ee66cb-0def-44ec-960e-0949d73b56a9`
- email: `demo.passenger@airline.test`
- Mở hành trình sau đăng nhập: http://localhost:8000/journeys/98ee66cb-0def-44ec-960e-0949d73b56a9

## Số lượng bản ghi

| Bảng | Số lượng |
|---|---:|
| airports | 22 |
| routes | 44 |
| airport_resources | 644 |
| aircraft | 30 |
| crew_members | 380 |
| crew_assignments | 5886 |
| flights | 981 |
| resource_allocations | 2912 |
| ground_tasks | 2910 |
| work_orders | 3 |
| disruptions | 4 |
| flight_changes | 2 |
| master_changes | 5 |
| devices | 6 |
| telemetry | 18 |
| users | 853 |
| inventory | 979 |
| fare_classes | 2937 |
| bookings | 34786 |
| tickets | 34777 |
| boarding_passes | 13174 |
| baggage | 2114 |
| baggage_events | 10565 |
| support_cases | 3 |
| support_messages | 6 |
| notifications | 27 |
| exchange_requests | 4 |
| payments | 34778 |
| refunds | 6 |
| ledger_entries | 34779 |
| reconciliation_runs | 2 |
| reconciliation_entries | 9490 |
| checkout_sessions | 34781 |
| checkout_intents | 34781 |

## Lưu ý thời gian

AS9901 khởi hành sau thời điểm tạo 55 phút: quét QR trong khoảng -60 đến -10 phút; check-in đóng trước 30 phút. AS9902 còn trong cửa sổ check-in 24 giờ. Giữ chỗ/đổi vé chờ duyệt hết hạn sau 10 phút theo nghiệp vụ thật; nếu đã hết hạn, tạo yêu cầu mới từ vé đã xác nhận. Các chuyến AS800–AS843 mở bán trong 14 ngày tới để tiếp tục thử. Chạy lại lệnh giữ nguyên kết quả thử nghiệm, không đặt lại trạng thái.
