<?php

namespace App\Modules\Customer;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportDesk
{
    public function assign(User $u, int $id, array $data): object
    {
        Access::role($u, 'customer_service');
        $d = Validator::make($data, ['assigned_to' => 'required|integer', 'priority' => 'required|in:LOW,NORMAL,HIGH,URGENT', 'due_at' => 'required|date', 'reason' => 'required|string|between:5,255'])->validate();
        $d['due_at'] = Carbon::parse($d['due_at'], 'Asia/Ho_Chi_Minh')->utc();
        abort_unless($d['due_at']->isFuture(), 422, 'Hạn phản hồi phải sau thời điểm hiện tại theo giờ Việt Nam.');
        abort_unless(User::where('id', $d['assigned_to'])->where('role', 'customer_service')->whereNull('disabled_at')->exists(), 422, 'Chọn nhân viên hỗ trợ đang hoạt động.');

        return DB::transaction(function () use ($u, $id, $d) {
            $case = DB::table('support_cases')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_if($case->status === 'RESOLVED', 409, 'Hồ sơ đã đóng.');
            DB::table('support_cases')->where('id', $id)->update(['assigned_to' => $d['assigned_to'], 'priority' => $d['priority'], 'due_at' => $d['due_at'], 'escalated_at' => null, 'updated_at' => now()]);
            $priority = match ($d['priority']) {
                'LOW' => 'thấp', 'HIGH' => 'cao', 'URGENT' => 'khẩn cấp', default => 'bình thường'
            };
            DB::table('support_messages')->insert(['case_id' => $id, 'sender_id' => $u->id, 'sender_role' => 'customer_service', 'body' => 'Phân công xử lý; mức ưu tiên '.$priority.'. Lý do: '.$d['reason'], 'created_at' => now()]);
            Events::notify((int) $d['assigned_to'], 'Hồ sơ hỗ trợ được giao #'.$id, 'Vui lòng kiểm tra thời hạn và phản hồi hành khách.');
            Audit::record('support.assigned', (string) $id, (string) $case->assigned_to, (string) $d['assigned_to'], $u->id);

            return DB::table('support_cases')->find($id);
        });
    }

    public function escalateOverdue(?int $only = null): int
    {
        $query = DB::table('support_cases')->where('status', '!=', 'RESOLVED')->where('due_at', '<', now())->whereNull('escalated_at');
        if ($only !== null) {
            $query->where('id', $only);
        }
        $count = 0;
        foreach ($query->orderBy('id')->limit(50)->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id) {
                $case = DB::table('support_cases')->where('id', $id)->lockForUpdate()->first();
                if ($case->status === 'RESOLVED' || $case->escalated_at || ! $case->due_at || now()->lte($case->due_at)) {
                    return 0;
                }
                DB::table('support_cases')->where('id', $id)->update(['escalated_at' => now(), 'priority' => 'URGENT', 'updated_at' => now()]);
                foreach (User::where('role', 'customer_service')->whereNull('disabled_at')->pluck('id') as $recipient) {
                    Events::notify($recipient, 'Hỗ trợ quá hạn #'.$id, 'Hồ sơ cần được ưu tiên xử lý.');
                }
                Audit::record('support.escalated', (string) $id, $case->priority, 'URGENT');

                return 1;
            });
        }

        return $count;
    }
}
