<?php

namespace App\Support;

final class BusinessUi
{
    public static function status(string $value): string
    {
        return ['READY' => 'Khớp, chờ xác nhận', 'SETTLED' => 'Đã xác nhận thu', 'UNMATCHED' => 'Chưa tìm thấy mã đặt chỗ', 'AMBIGUOUS' => 'Có nhiều yêu cầu cùng mã', 'UNDERPAID' => 'Thiếu tiền', 'OVERPAID' => 'Thừa tiền', 'DUPLICATE_PAYMENT' => 'Đã nhận một khoản khác', 'DUPLICATE_TRANSACTION' => 'Mã giao dịch đã dùng', 'APPLIED' => 'Đã cập nhật', 'AUTO' => 'Theo lịch', 'OPEN' => 'Đang mở', 'CLOSED' => 'Đã khóa', 'LOW' => 'Thấp', 'NORMAL' => 'Thông thường', 'HIGH' => 'Cao', 'URGENT' => 'Khẩn cấp'][$value] ?? Ui::label($value);
    }
}
