<?php

namespace App\Modules\Customer;

use App\Models\User;
use App\Modules\Shared\Access;
use App\Modules\Shared\Audit;
use App\Modules\Shared\Events;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerService
{
    public function create(User $u, array $data): object
    {
        Access::role($u, 'passenger');
        $d = Validator::make($data, ['booking_id' => 'nullable|uuid', 'type' => 'required|in:ASSISTANCE,COMPLAINT,BAGGAGE', 'subject' => 'required|string|between:5,150', 'message' => 'required|string|between:10,4000'])->validate();
        if (! empty($d['booking_id'])) {
            abort_unless(DB::table('bookings')->where('id', $d['booking_id'])->where('user_id', $u->id)->exists(), 404);
        }

        return DB::transaction(function () use ($u, $d) {
            $id = DB::table('support_cases')->insertGetId($d + ['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('support_messages')->insert(['case_id' => $id, 'sender_id' => $u->id, 'sender_role' => 'passenger', 'body' => $d['message'], 'created_at' => now()]);
            Audit::record('support.created', (string) $id, null, 'OPEN', $u->id);

            return (object) ['id' => $id];
        });
    }

    public function respond(User $u, int $id, array $data): object
    {
        Access::role($u, 'customer_service');
        $d = Validator::make($data, ['status' => 'required|in:IN_PROGRESS,RESOLVED', 'response' => 'required|string|between:10,4000'])->validate();

        return DB::transaction(function () use ($u, $id, $d) {
            $case = DB::table('support_cases')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_if($case->status === 'RESOLVED', 409, 'Hồ sơ đã đóng.');
            DB::table('support_cases')->where('id', $id)->update($d + ['handled_by' => $u->id, 'updated_at' => now()]);
            DB::table('support_messages')->insert(['case_id' => $id, 'sender_id' => $u->id, 'sender_role' => 'customer_service', 'body' => $d['response'], 'created_at' => now()]);
            Events::notify($case->user_id, 'Phản hồi hỗ trợ #'.$id, $d['response']);
            Audit::record('support.responded', (string) $id, $case->status, $d['status'], $u->id);

            return (object) ['id' => $id];
        });
    }

    public function conversation(User $u, int $id): object
    {
        $case = DB::table('support_cases')->find($id) ?? abort(404);
        if ($u->role === 'passenger') {
            abort_unless($case->user_id === $u->id, 404);
        } else {
            Access::role($u, 'customer_service');
        }
        $case->messages = DB::table('support_messages')->where('case_id', $id)->orderBy('id')->get(['sender_role', 'body', 'created_at']);
        Audit::record('support.viewed', (string) $id, null, 'READ', $u->id);

        return $case;
    }

    public function followup(User $u, int $id, string $message): object
    {
        Access::role($u, 'passenger');
        Validator::make(['message' => $message], ['message' => 'required|string|between:10,4000'])->validate();

        return DB::transaction(function () use ($u, $id, $message) {
            $case = DB::table('support_cases')->where('id', $id)->where('user_id', $u->id)->lockForUpdate()->first() ?? abort(404);
            abort_if($case->status === 'RESOLVED', 409, 'Hồ sơ đã đóng; hãy tạo yêu cầu mới.');
            DB::table('support_messages')->insert(['case_id' => $id, 'sender_id' => $u->id, 'sender_role' => 'passenger', 'body' => $message, 'created_at' => now()]);
            DB::table('support_cases')->where('id', $id)->update(['updated_at' => now()]);
            if ($case->handled_by) {
                Events::notify($case->handled_by, 'Phản hồi mới trong hỗ trợ #'.$id, 'Hành khách đã bổ sung nội dung.');
            }Audit::record('support.followup', (string) $id, $case->status, $case->status, $u->id);

            return (object) ['id' => $id];
        });
    }
}
