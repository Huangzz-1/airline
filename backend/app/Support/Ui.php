<?php

namespace App\Support;

use Carbon\Carbon;

class Ui
{
    public static function label(?string $value): string
    {
        return [
            'APPROVED' => 'Đã duyệt, chờ chi tiền', 'REPORTED' => 'Khách báo đã chuyển tiền', 'PROCESSING' => 'Đang xử lý', 'VNPAY' => 'VNPay', 'MOMO' => 'Ví MoMo', 'BANK' => 'Chuyển khoản',
            'HELD' => 'Chờ thanh toán', 'PAYMENT_PENDING' => 'Đang xử lý thanh toán', 'CONFIRMED' => 'Đã xuất vé', 'CHECKED_IN' => 'Đã làm thủ tục', 'BOARDED' => 'Đã lên tàu bay',
            'EXPIRED' => 'Hết hạn giữ chỗ', 'CANCELLED' => 'Đã hủy', 'REFUND_PENDING' => 'Đang xử lý hoàn tiền', 'REFUNDED' => 'Đã hoàn tiền', 'EXCHANGE_PENDING' => 'Đang đổi vé',
            'DRAFT' => 'Bản nháp', 'PENDING' => 'Chờ phê duyệt', 'SCHEDULED' => 'Theo lịch', 'DELAYED' => 'Chậm chuyến', 'BOARDING' => 'Đang đón khách', 'GATE_CLOSED' => 'Đã đóng cửa', 'DEPARTED' => 'Đã khởi hành', 'ARRIVED' => 'Đã hạ cánh', 'CHANGE_PENDING' => 'Đang đổi tàu',
            'OPEN' => 'Đang mở', 'CLOSED' => 'Đã đóng', 'AVAILABLE' => 'Sẵn sàng', 'MAINTENANCE' => 'Đang bảo dưỡng', 'COMPLETED' => 'Hoàn tất', 'RELEASED' => 'Đã giải phóng', 'IN_PROGRESS' => 'Đang xử lý', 'RESOLVED' => 'Đã giải quyết',
            'REJECTED' => 'Đã từ chối', 'FAILED' => 'Không thành công', 'PREPARING' => 'Đang kiểm tra', 'PREPARED' => 'Đã kiểm tra', 'COMMITTING' => 'Đang áp dụng', 'RECONFIGURING' => 'Đang cập nhật cấu hình', 'REQUESTED' => 'Chờ phê duyệt', 'REFUNDING' => 'Đang hoàn vé cũ',
            'CAPTURED' => 'Đã thanh toán', 'DECLINED' => 'Thanh toán bị từ chối', 'CAPTURE' => 'Thu tiền', 'REFUND' => 'Hoàn tiền', 'MATCHED' => 'Khớp', 'MISMATCH' => 'Sai lệch', 'MATCH' => 'Khớp',
            'TAGGED' => 'Đã gắn thẻ', 'ACCEPTED' => 'Đã tiếp nhận', 'SCREENED' => 'Đã soi chiếu', 'LOADED' => 'Đã xếp lên tàu', 'TRANSFERRED' => 'Đã chuyển tiếp', 'DELIVERED' => 'Đã giao trả', 'MISHANDLED' => 'Cần xử lý', 'OFFLOADED' => 'Đã dỡ khỏi tàu',
            'PILOT' => 'Phi công', 'CABIN' => 'Tiếp viên', 'AIRCRAFT' => 'Đổi tàu bay', 'CREW' => 'Thay tổ bay', 'DELAY' => 'Chậm chuyến', 'CANCEL' => 'Hủy chuyến',
            'ASSISTANCE' => 'Hỗ trợ đặc biệt', 'COMPLAINT' => 'Khiếu nại', 'BAGGAGE' => 'Hành lý', 'TERMINAL' => 'Nhà ga', 'GATE' => 'Cửa lên tàu', 'STAND' => 'Vị trí đỗ', 'COUNTER' => 'Quầy thủ tục', 'ECONOMY' => 'Phổ thông',
        ][$value] ?? ($value ?? '—');
    }

    public static function role(string $role): string
    {
        return ['passenger' => 'Khách hàng', 'airport_agent' => 'Nhân viên sân bay', 'operations' => 'Điều hành khai thác', 'ops_manager' => 'Quản lý khai thác', 'data_admin' => 'Quản lý danh mục', 'fleet_manager' => 'Quản lý đội tàu', 'maintenance' => 'Kỹ thuật bảo dưỡng', 'crew_scheduler' => 'Điều phối tổ bay', 'ground_agent' => 'Nhân viên mặt đất', 'finance' => 'Nhân viên tài chính', 'customer_service' => 'Chăm sóc khách hàng', 'analyst' => 'Chuyên viên phân tích', 'integration_admin' => 'Quản lý thiết bị và kết nối', 'auditor' => 'Kiểm toán viên', 'admin' => 'Quản trị viên'][$role] ?? $role;
    }

    public static function date($value, string $format = 'H:i d/m/Y'): string
    {
        return $value ? Carbon::parse($value)->tz('Asia/Ho_Chi_Minh')->format($format) : '—';
    }

    public static function money($value): string
    {
        return number_format((int) $value, 0, ',', '.').' ₫';
    }

    public static function flight(object $f): string
    {
        return $f->number.' · '.$f->origin.' → '.$f->destination.' · '.self::date($f->departure);
    }
}
