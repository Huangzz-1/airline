# First-party REST API v1

Cookie session sau login `/login`, `Accept: application/json`; mutation phải gửi CSRF `_token`/`X-CSRF-TOKEN` và cookie phiên. Chưa có bearer token cho hệ thống ngoài. API dùng UTC/ISO8601; datetime-local của form được hiểu theo giờ Việt Nam và đổi UTC. Tiền là VND nguyên.

## Booking

| Method | Endpoint | Payload / quyền |
|---|---|---|
| GET | /api/v1/flights | Public; origin,destination,date (ngày UTC+7),page |
| GET/POST | /api/v1/bookings | Verified passenger; POST flight_id,idempotency_key,fare_class (default ECONOMY) |
| GET | /api/v1/bookings/{uuid} | Chỉ chủ |
| POST | /api/v1/bookings/{uuid}/pay | method=VNPAY/MOMO/BANK; chỉ phương thức đã cấu hình. scenario=approve/decline chỉ dành simulator khi được bật ngoài production, mặc định tắt |
| POST | /api/v1/bookings/{uuid}/cancel | Chỉ HELD; nếu target exchange thì rút đề nghị |
| POST | /api/v1/bookings/{uuid}/checkin | seat (1A…); chỉ chủ |
| POST | /api/v1/boarding | airport_agent; flight_id,token64, sân bay khởi hành được gán |
| GET | /api/v1/baggage/{id} | Chủ booking hoặc staff ở điểm đi/đến |
| GET | /api/v1/support-cases/{id} | Chủ hoặc customer_service; messages có lịch sử đầy đủ |

## Resource creation

POST `/api/v1/{resource}` gọi cùng validators/service như UI:

| Resource | Vai trò | Field chính |
|---|---|---|
| airports | data_admin | code,name,city,timezone |
| routes | data_admin | origin,destination,duration_minutes,distance_km |
| aircraft | fleet_manager | registration,type,capacity |
| crew | crew_scheduler | code,name,position PILOT/CABIN,qualification,license_expires |
| schedules | operations | number,route_id,aircraft_id,departure,arrival,sale_price,gate |
| maintenance-orders | maintenance | aircraft_id,title,due_at |
| resource-allocations | operations | flight_id,resource_id,starts_at,ends_at,reason |
| ground-tasks | operations | flight_id,title,assigned_to,due_at,mandatory |
| disruptions | operations | flight_id,type DELAY/CANCEL,delay_minutes,reason |
| baggage-tags | airport_agent | booking_id,weight,request_id |
| baggage-events | airport_agent | event_id,tag,flight_id,status,location,custody |
| support-cases | passenger | booking_id nullable,type,subject,message |
| devices | integration_admin | code,airport_code,threshold |
| telemetry | integration_admin | event_id,device_id,sequence,value,event_time |
| fare-classes | data_admin | flight_id,code,name,quota,price,active |
| flight-changes | operations / crew_scheduler | flight_id,kind AIRCRAFT/CREW,reason; AIRCRAFT: target_aircraft_id; CREW: crew_ids chuỗi ID phân cách dấu phẩy |

## Workflow actions

POST `/api/v1/actions/{action}` (hoặc `/workspace/actions/{action}` cho form). Input/validation chuẩn ở service, field ID cần kiểu int/UUID tương ứng:

- `submit`, `publish`: flight_id; người duyệt độc lập. `submit_batch`,`publish_batch`: flight_ids chuỗi ID cách dấu phẩy, tối đa 31.
- `season`: các field schedule, thay departure/arrival bằng from_date,to_date,weekdays (1–7),local_departure (H:i),duration_minutes. Mỗi đợt ≤31 ngày.
- `assign_crew`: flight_id,crew_member_id; `flight_state`: flight_id,status,reason.
- `complete_work`: work_order_id,evidence; `release_work`: work_order_id.
- `unallocate`: allocation_id,reason; `ground_state`: task_id,status,evidence.
- `approve_disruption`: disruption_id.
- `resource_propose`: các field flight-changes ở trên; snapshot/version nguồn, chưa áp dụng ngay. AIRCRAFT chỉ cùng loại trước BOARDING; CREW thay cả danh sách, tối đa 20 ID.
- `resource_decide`: change_id UUID,approve boolean; ops_manager đúng sân bay, khác người lập. Reject kết thúc đề nghị; approve đổi tàu trả phương án PREPARING, worker tiếp tục Saga. Không coi response duyệt là tồn chỗ đã mở lại.
- `change_flight`: booking_id,flight_id,request_id — HELD còn TTL, cùng tuyến, không tăng TTL.
- `change_seat`: booking_id,seat — CHECKED_IN, chưa cutoff. Mã QR cũ không còn hiệu lực.
- `offload`: booking_id,reason — airport_agent, trước departure; revoke QR, ghi offload bags.
- `refund_request`: booking_id,reason — chủ; `refund_decide`: refund_id,approve boolean — finance.
- `exchange_request`: request_id,booking_id,flight_id,reason — chủ; `exchange_decide`: exchange_id,approve,scenario approve/decline — finance. Mua mới toàn bộ/hoàn cũ toàn bộ; source chỉ refund sau target capture, target decline không làm mất vé cũ.
- `reconcile`: start,end ngày UTC (≤93 ngày); `resolve`: entry_id,note (ghi ngoại lệ, không tự sửa tiền); `close_period`: run_id, người khác creator. CSV `/workspace/reports/{id}/export` chỉ kỳ CLOSED.
- `master_propose`: entity,record_id,changes object,reason; `master_approve`: change_id. Whitelist entity/role trong MasterDataService; snapshot stale bị từ chối. UI dùng field có nhãn, không yêu cầu nhập JSON.
- `support_reply`: case_id,status,response; `support_followup`: case_id,message chỉ chủ khi chưa resolved; `read_notice`: notification_id chỉ người nhận.
- `forecast`: flight_id, trả simulated/model/factors/risk/time/explanation; không tự áp dụng phương án.
- `fare`: flight_id,price,capacity,status,reason; không giảm dưới số đang giữ/bán hoặc ghế đã gán.

GET `/api/v1/workspace/{section}` trả dữ liệu/paging theo RBAC, GET `/workspace/{section}` là UI: catalog,scheduling,fleet,maintenance,crew,baggage,ground,customer,finance,analytics,iot,fare,resources,disruptions,recovery,notifications,audit,integrations. Tham số q lọc theo ID trên các bảng có ID, page phân trang. Không có permission wildcard cho admin.

GET `/api/v1/flight-changes/{uuid}` hoặc UI `/workspace/recovery/{uuid}`: operations/ops_manager/crew_scheduler theo scope; trạng thái, snapshot trước/sau, số booking đang ảnh hưởng, lý do thất bại, mapping ghế theo booking UUID. PENDING→PREPARING→COMMITTING→COMPLETED, hoặc REJECTED/FAILED. Trong pha đổi tàu, mutation tồn chỗ trả 409; retry sau hoàn tất. Payment callback chờ trong outbox, không thu lại lần hai.

CSV master: POST `/workspace/master/import` multipart file csv/txt UTF-8 ≤1 MB, ≤200 dòng, chỉ sân bay, vào hàng chờ nguyên tử; GET `/workspace/master/export/{entity}` theo vai trò. Import không tự kích hoạt dữ liệu.

## Errors và idempotency

Chứng từ GET `/journeys/{uuid}/ticket`, `/journeys/{uuid}/boarding-pass`: chủ booking hoặc airport_agent đúng sân bay đi; không in vé chưa trả/hoàn, QR bị thu hồi/hết hạn hoặc tồn đang frozen. GET `/staff/passengers?q=...` và POST `/staff/passengers/{uuid}/checkin` (seat) chỉ agent đúng sân bay, audit ghi nhân viên thực hiện.

POST `/journeys/{uuid}/transfer-report` (reference) chỉ chủ; chưa làm capture. POST `/staff/payments/confirm-transfer` (intent_id,approve,reference) chỉ finance, reference phải lấy từ sao kê khi đồng ý. POST `/journeys/{uuid}/refund-destination` (bank_name,account_name,account_number) chỉ chủ đang REFUND_PENDING; POST `/staff/payments/confirm-refund` (refund_id,reference) chỉ finance khác approver, sau khi đã thực hiện chi ngân hàng.

GET `/payments/vnpay/ipn`, POST `/payments/momo/ipn` nhận thông báo có chữ ký nhà cung cấp; riêng MoMo IPN được miễn CSRF, không miễn kiểm tra chữ ký. IPN không cần session khách hàng. GET `/payments/{vnpay|momo}/return` chỉ điều hướng về booking thuộc chủ, không capture. IPN commit DB3 rồi outbox tiếp tục cập nhật DB2. Xem deployment.md cho cấu hình merchant.

POST `/admin/users` tạo nhân viên (name,email,password,password_confirmation,role,airport_code), bắt đổi mật khẩu; POST `/admin/users/{id}/access` (active boolean) khóa/mở, không tự khóa; GET `/admin/connections` chỉ admin xem trạng thái cấu hình, không trả credential.

200/201 thành công, 202 payment pending; 401 chưa login; 403 thiếu quyền/verify/scope; 404 không tồn tại hoặc không thuộc chủ; 409 state/TTL/conflict/stale/duplicate payload; 419 CSRF; 422 validation; 429 rate limit. Validation JSON có message/errors; không tắt assertion để đổi lỗi thành thành công.

Hold key scoped user, cùng flight+fare trả cùng booking, payload khác trả conflict. Các event dùng UUID và inbox, payment/refund/ticket unique booking. Baggage replay payload khớp không thêm scan, IoT ID/sequence khác payload conflict. FlightChanged source version cũ không đè mới. Refund/paid-exchange pending giữ tồn cho đến kết quả; không expire tài chính chưa rõ.

## Dịch vụ local

POST `/email/verify-otp` nhận code 6 số của tài khoản đang đăng nhập; TTL 10 phút, 5 lần thử. POST `/email/verification-notification` gửi lại sau ít nhất 60 giây. Email OTP, reset và nghiệp vụ đi qua worker mail/SMTP Mailpit, không có endpoint trả mã xác minh.

GET/POST `/payments/mock/{intent_uuid}` chỉ verified passenger sở hữu intent, chỉ hoạt động PAYMENT_MODE=mock ngoài production. POST result=approve/decline; số tiền/merchant/chữ ký do server lấy từ intent và .env, không lấy số tiền từ client. Kết quả chạy qua kiểm tra chữ ký và outbox như bộ kết nối. Khoản thu lưu mode=mock. Khi PAYMENT_MODE=gateway, sử dụng các kết nối VNPay/MoMo đã cấu hình; ngân hàng thật dùng BANK_TRANSFER_MODE=live.
