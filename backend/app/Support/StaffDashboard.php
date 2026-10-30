<?php

namespace App\Support;

use App\Http\Controllers\WorkspaceController;
use App\Models\User;
use App\Modules\Shared\Access;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StaffDashboard
{
    public function data(User $u): array
    {
        $tiles = [];
        $descriptions = ['catalog' => 'Quản lý sân bay, đường bay và danh mục phục vụ khai thác.', 'scheduling' => 'Lập lịch, phê duyệt và theo dõi trạng thái chuyến bay.', 'fleet' => 'Theo dõi cấu hình, sức chứa và tình trạng đội tàu.', 'maintenance' => 'Quản lý lệnh bảo dưỡng, kết quả và giải phóng kỹ thuật.', 'recovery' => 'Đề nghị và phê duyệt thay tàu bay hoặc tổ bay.', 'crew' => 'Quản lý năng định, giấy phép và phân công tổ bay.', 'baggage' => 'Tiếp nhận, bàn giao và tra cứu hành lý.', 'ground' => 'Giao việc và theo dõi tiến độ phục vụ mặt đất.', 'customer' => 'Tiếp nhận và phản hồi yêu cầu của khách hàng.', 'finance' => 'Xử lý đổi, hoàn vé và khóa kỳ đối soát.', 'analytics' => 'Theo dõi hiệu quả khai thác và các yếu tố chậm chuyến.', 'iot' => 'Theo dõi thiết bị, số liệu và cảnh báo.', 'fare' => 'Thiết lập giá vé, hạng giá và số chỗ được bán.', 'resources' => 'Điều phối cửa, quầy và vị trí đỗ.', 'disruptions' => 'Xử lý chuyến chậm hoặc hủy và tác động đến khách hàng.', 'audit' => 'Tra cứu lịch sử thao tác của người dùng.', 'integrations' => 'Theo dõi dữ liệu đang chờ xử lý và kết nối dịch vụ.'];
        foreach (WorkspaceController::sections() as $key => [$label,$roles]) {
            if ($key !== 'notifications' && in_array($u->role, $roles)) {
                $tiles[] = ['url' => '/workspace/'.$key, 'title' => $label, 'description' => $descriptions[$key] ?? ''];
            }
        }
        $ops = DB::connection('ops');
        $day = Carbon::now('Asia/Ho_Chi_Minh')->startOfDay()->utc();
        $flights = $ops->table('flights')->where('departure', '>=', $day)->where('departure', '<', $day->copy()->addDay());
        if (in_array($u->role, Access::SCOPED)) {
            $flights->where('origin', $u->airport_code);
        }
        $kpis = match ($u->role) {
            'operations','ops_manager' => ['Chuyến trong ngày' => (clone $flights)->count(), 'Lịch chờ duyệt' => $ops->table('flights')->where('origin', $u->airport_code)->where('status', 'PENDING')->count(), 'Phương án chờ duyệt' => $ops->table('flight_changes')->whereIn('flight_id', $ops->table('flights')->where('origin', $u->airport_code)->pluck('id'))->where('status', 'PENDING')->count()],
            'customer_service' => ['Chờ tiếp nhận' => DB::table('support_cases')->where('status', 'OPEN')->count(), 'Đang xử lý' => DB::table('support_cases')->where('status', 'IN_PROGRESS')->count(), 'Đã giải quyết' => DB::table('support_cases')->where('status', 'RESOLVED')->count()],
            'finance' => ['Yêu cầu hoàn chờ duyệt' => DB::connection('finance')->table('refunds')->where('status', 'REQUESTED')->count(), 'Yêu cầu đổi chờ duyệt' => DB::table('exchange_requests')->where('status', 'PENDING')->count(), 'Kỳ chưa khóa' => DB::connection('finance')->table('reconciliation_runs')->where('status', 'OPEN')->count()],
            'ground_agent' => ['Việc chờ xử lý' => $ops->table('ground_tasks')->where('assigned_to', $u->id)->where('status', 'PENDING')->count(), 'Đang thực hiện' => $ops->table('ground_tasks')->where('assigned_to', $u->id)->where('status', 'IN_PROGRESS')->count(), 'Quá hạn' => $ops->table('ground_tasks')->where('assigned_to', $u->id)->where('status', '!=', 'COMPLETED')->where('due_at', '<', now())->count()],
            'fleet_manager','maintenance' => ['Tàu sẵn sàng' => $ops->table('aircraft')->where('status', 'AVAILABLE')->count(), 'Lệnh chưa hoàn tất' => $ops->table('work_orders')->where('status', 'OPEN')->count(), 'Chờ giải phóng' => $ops->table('work_orders')->where('status', 'COMPLETED')->count()],
            'crew_scheduler' => ['Nhân sự sẵn sàng' => $ops->table('crew_members')->where('available', true)->count(), 'Giấy phép sắp hết hạn' => $ops->table('crew_members')->where('license_expires', '<=', now()->addDays(30))->count(), 'Lịch cần phân công' => $ops->table('flights')->where('status', 'DRAFT')->count()],
            default => ['Thông báo chưa đọc' => DB::table('notifications')->where('user_id', $u->id)->whereNull('read_at')->count()]
        };

        return compact('tiles', 'kpis');
    }
}
