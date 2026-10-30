# ERD theo node

Các đường nối giữa node là tham chiếu logic qua backend/event, không FK xuyên MySQL server.

```mermaid
erDiagram
 DB1_AIRPORTS ||--o{ DB1_ROUTES : origin_destination
 DB1_AIRPORTS ||--o{ DB1_RESOURCES : owns
 DB1_ROUTES ||--o{ DB1_FLIGHTS : schedules
 DB1_AIRCRAFT ||--o{ DB1_FLIGHTS : operates
 DB1_AIRCRAFT ||--o{ DB1_WORK_ORDERS : maintenance
 DB1_FLIGHTS ||--o{ DB1_CREW_ASSIGNMENTS : staffing
 DB1_CREW_MEMBERS ||--o{ DB1_CREW_ASSIGNMENTS : qualification
 DB1_FLIGHTS ||--o{ DB1_ALLOCATIONS : resource_slots
 DB1_RESOURCES ||--o{ DB1_ALLOCATIONS : reserves
 DB1_FLIGHTS ||--o{ DB1_GROUND_TASKS : readiness
 DB1_FLIGHTS ||--o{ DB1_DISRUPTIONS : approval
 DB1_FLIGHTS ||--o{ DB1_FLIGHT_CHANGES : approved_revision
 DB1_FLIGHT_CHANGES ||--o{ DB1_CHANGE_RESERVATIONS : holds_during_swap
 DB1_AIRCRAFT ||--o{ DB1_CHANGE_RESERVATIONS : protects_aircraft
 DB1_DEVICES ||--o{ DB1_TELEMETRY : events
 DB1_FLIGHTS ||--|| DB2_INVENTORY : projection_no_cross_FK
 DB2_INVENTORY ||--o{ DB2_FARE_CLASSES : quotas
 DB2_USERS ||--o{ DB2_BOOKINGS : owns
 DB2_USERS ||--o| DB2_EMAIL_VERIFICATIONS : hashed_otp
 DB2_INVENTORY ||--o{ DB2_BOOKINGS : stock_lock
 DB2_BOOKINGS ||--o| DB2_TICKETS : issues
 DB2_BOOKINGS ||--o| DB2_CHECKOUT_SESSIONS : payment_request
 DB2_CHECKOUT_SESSIONS ||--o| DB3_CHECKOUT_INTENTS : logical_no_cross_FK
 DB3_CHECKOUT_INTENTS ||--o| DB3_PAYMENTS : verified_settlement
 DB2_BOOKINGS ||--o| DB2_BOARDING_PASSES : checkin
 DB2_BOOKINGS ||--o{ DB2_BAGGAGE : tags
 DB2_BAGGAGE ||--o{ DB2_BAGGAGE_EVENTS : custody
 DB2_BOOKINGS ||--o{ DB2_EXCHANGES : source_target
 DB1_FLIGHT_CHANGES ||--o| DB2_AIRCRAFT_CHANGE_RECEIPTS : saga_no_cross_FK
 DB2_AIRCRAFT_CHANGE_RECEIPTS ||--o{ DB2_SEAT_REASSIGNMENTS : reissue_mapping
 DB2_BOOKINGS ||--o{ DB2_SEAT_REASSIGNMENTS : seat_history
 DB2_USERS ||--o{ DB2_SUPPORT_CASES : requests
 DB2_SUPPORT_CASES ||--o{ DB2_SUPPORT_MESSAGES : history
 DB2_USERS ||--o{ DB2_NOTIFICATIONS : inbox
 DB2_BOOKINGS ||--o| DB3_PAYMENTS : saga_no_cross_FK
 DB3_PAYMENTS ||--o| DB3_REFUNDS : approval
 DB3_PAYMENTS ||--o{ DB3_LEDGER : capture_refund
 DB3_RECONCILIATION_RUNS ||--o{ DB3_RECONCILIATION_ENTRIES : immutable_when_closed
```

Mỗi node có outbox/inbox/audit_logs. DB1 có master_changes và flight_changes lưu snapshot/version approval; crew_assignments.active giữ phân công cũ, còn snapshot phương án lưu lịch sử trước/sau. DB2 có booking_changes cho idempotency đổi giữ chỗ; inventory.frozen_change_id/manual_closed kiểm soát đóng băng và đóng bán thủ công khi đổi tàu. Các bảng users/session/cache/jobs scaffold ở DB2, chỉ file-session/cache được chọn trong dev. Migration là nguồn chính xác về kiểu/ràng buộc; không vẽ các bảng chưa tồn tại như đã triển khai.
