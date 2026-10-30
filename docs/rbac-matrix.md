# RBAC — 15 vai trò, từ chối mặc định

Role được kiểm tra tại service và HTTP; ownership trước truy cập. Public signup luôn passenger; body role/airport không được mass assign. Admin chỉ cấp quyền cho người khác, không wildcard nghiệp vụ.

| Vai trò | Phạm vi thực tế |
|---|---|
| passenger | Chỉ profile, booking, đổi/hoàn, QR, baggage trace, support conversation và notifications của mình; cần verified trước nghiệp vụ |
| airport_agent | Tra cứu khách theo mã đặt chỗ/tên/email, làm thủ tục và in chứng từ cho chuyến khởi hành đúng airport_code; boarding/tag/scan/no-show; trace bag ở điểm đi hoặc điểm đến được phân công |
| operations | Lịch/trạng thái/task/resource/disruption và đề nghị đổi tàu thuộc sân bay khởi hành được gán; không tự phê duyệt |
| ops_manager | Publish/disruption/thay tàu và tổ bay của sân bay được gán; creator khác approver |
| data_admin | Airport/route/resource master, import/export, phiên bản sửa; fare/quota DB2 |
| fleet_manager | Aircraft master/version, xem usage; sửa cấu hình bị chặn nếu có lịch công bố đang hiệu lực |
| maintenance | Tạo/hoàn tất work order, chỉ người khác creator/completer mới được release |
| crew_scheduler | Hồ sơ/license/khả dụng/version, assignment trước publish và đề nghị thay cả tổ bay sau công bố; cần ops_manager duyệt độc lập, không tự đổi profile có phân công đã công bố |
| ground_agent | Chỉ task assigned_to mình ở sân bay được gán; evidence bắt buộc khi hoàn tất |
| finance | Xác nhận chuyển khoản theo sao kê; duyệt đổi/hoàn, xem tài khoản nhận hoàn; người xác nhận chi khác người duyệt; payment/ledger/reconciliation, người tạo kỳ không tự khóa kỳ |
| customer_service | Đọc và phản hồi support, giữ toàn bộ lịch sử; không sửa booking/hoàn tiền trực tiếp |
| analyst | KPI/forecast tổng hợp; không có quyền sửa nguồn hoặc xem contact passenger |
| integration_admin | Device/telemetry thuộc airport_code và giám sát outbox; không điều khiển thiết bị thật |
| auditor | Chỉ đọc audit của ba node, notifications của chính mình |
| admin | Tạo nhân viên với mật khẩu tạm buộc đổi lần đầu; users/role/scope, khóa/mở người khác, trạng thái cấu hình dịch vụ, audit/monitoring; không tự có quyền tài chính/điều hành |

Các role có airport bắt buộc: airport_agent, operations, ops_manager, ground_agent, integration_admin. Airport phải tồn tại khi cấp quyền. Test kiểm tra passenger/admin không vượt quyền, airport sai, object của người khác trả 404, kỹ thuật/ops/finance không tự phê duyệt việc của mình.

Audit chỉ append qua application, giữ actor/action/object/before-after/correlation/UTC tại node sở hữu; admin/auditor xem cả ba node. Chưa là kho audit bất biến bên ngoài hoặc mô hình SoD xác minh danh tính nhân sự thật.
