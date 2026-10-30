<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Shared\Access;
use Illuminate\Support\Facades\DB;

class WorkspaceForms
{
    public function prepare(array $forms, User $user): array
    {
        if (! $forms) {
            return [];
        }
        $ops = DB::connection('ops');
        $flights = $ops->table('flights')->where('departure', '>', now()->subDays(2));
        if (in_array($user->role, Access::SCOPED)) {
            $flights->where(fn ($q) => $q->where('origin', $user->airport_code)->when($user->role === 'airport_agent', fn ($q) => $q->orWhere('destination', $user->airport_code)));
        }
        $flightRows = $flights->orderBy('departure')->limit(500)->get();
        $flightLabels = $flightRows->mapWithKeys(fn ($f) => [$f->id => Ui::flight($f).' · '.Ui::label($f->status)])->all();
        $bookingQuery = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')->select('b.*', 'u.name as passenger_name');
        if ($user->role === 'passenger') {
            $bookingQuery->where('b.user_id', $user->id);
        } elseif ($user->role === 'airport_agent') {
            $bookingQuery->whereIn('b.flight_id', $flightRows->pluck('id'));
        }
        $bookingLabels = in_array($user->role, ['passenger', 'airport_agent']) ? $bookingQuery->latest('b.created_at')->limit(500)->get()->mapWithKeys(fn ($b) => [$b->id => $b->reference.' · '.$b->passenger_name])->all() : [];
        foreach ($forms as &$form) {
            $action = $form['action'];
            $entity = collect($form['fields'])->firstWhere('name', 'entity')['options']['value'] ?? null;
            foreach ($form['fields'] as &$field) {
                $name = $field['name'];
                $options = null;
                if ($name === 'flight_id') {
                    $allowed = match ($action) {
                        'submit' => ['DRAFT'],'publish' => ['PENDING'],'assign_crew' => ['DRAFT', 'PENDING'],'resource_propose' => ['SCHEDULED', 'DELAYED', 'BOARDING'],'fare','fare_class' => ['SCHEDULED', 'DELAYED', 'BOARDING'],default => null
                    };
                    $options = $allowed ? $flightRows->whereIn('status', $allowed)->mapWithKeys(fn ($f) => [$f->id => $flightLabels[$f->id]])->all() : $flightLabels;
                    if (str_contains($field['label'], 'ID')) {
                        $field['label'] = 'Chuyến';
                    }
                }
                if ($name === 'booking_id') {
                    $options = $bookingLabels;
                    $field['label'] = $action === 'support' ? 'Hành trình liên quan (không bắt buộc)' : 'Hành khách / mã đặt chỗ';
                }
                if ($name === 'crew_ids') {
                    $field['type'] = 'multi-select';
                    $field['label'] = 'Tổ bay thay thế';
                    $field['options'] = $ops->table('crew_members')->where('available', true)->orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code.' · '.$c->name.' · '.Ui::label($c->position).' · '.$c->qualification])->all();

                    continue;
                }
                if ($name === 'flight_ids') {
                    $field['type'] = 'multi-select';
                    $field['label'] = 'Chọn các chuyến';
                    $field['options'] = $flightRows->where('status', $action === 'submit_batch' ? 'DRAFT' : 'PENDING')->mapWithKeys(fn ($f) => [$f->id => $flightLabels[$f->id]])->all();

                    continue;
                }
                if ($name === 'work_order_id') {
                    $options = $ops->table('work_orders')->where('status', $action === 'complete_work' ? 'OPEN' : 'COMPLETED')->orderBy('due_at')->get()->mapWithKeys(fn ($w) => [$w->id => $w->title.' · '.Ui::date($w->due_at)])->all();
                    $field['label'] = 'Lệnh bảo dưỡng';
                }
                if ($name === 'task_id') {
                    $options = $ops->table('ground_tasks')->where('assigned_to', $user->id)->where('status', '!=', 'COMPLETED')->get()->mapWithKeys(fn ($t) => [$t->id => $t->title.' · '.($flightLabels[$t->flight_id] ?? '')])->all();
                    $field['label'] = 'Nhiệm vụ';
                }
                if ($name === 'allocation_id') {
                    $options = $ops->table('resource_allocations as a')->join('airport_resources as r', 'r.id', '=', 'a.resource_id')->whereIn('a.flight_id', $flightRows->pluck('id'))->select('a.*', 'r.code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->code.' · '.($flightLabels[$a->flight_id] ?? '').' · '.Ui::date($a->starts_at)])->all();
                    $field['label'] = 'Phân bổ hiện tại';
                }
                if ($name === 'disruption_id') {
                    $options = $ops->table('disruptions')->whereIn('flight_id', $flightRows->pluck('id'))->where('status', 'PENDING')->get()->mapWithKeys(fn ($p) => [$p->id => Ui::label($p->type).' · '.($flightLabels[$p->flight_id] ?? '').' · '.$p->reason])->all();
                    $field['label'] = 'Phương án chờ duyệt';
                }
                if ($name === 'case_id') {
                    $options = DB::table('support_cases')->where('status', '!=', 'RESOLVED')->get()->mapWithKeys(fn ($c) => [$c->id => $c->subject])->all();
                    $field['label'] = 'Yêu cầu hỗ trợ';
                }
                if ($name === 'refund_id') {
                    $refunds = DB::connection('finance')->table('refunds')->where('status', 'REQUESTED')->get();
                    $refs = DB::table('bookings')->whereIn('id', $refunds->pluck('booking_id'))->pluck('reference', 'id');
                    $options = $refunds->mapWithKeys(fn ($p) => [$p->id => ($refs[$p->booking_id] ?? 'Đặt chỗ').' · '.Ui::money($p->amount).' · '.$p->reason])->all();
                    $field['label'] = 'Yêu cầu hoàn tiền';
                }
                if ($name === 'exchange_id') {
                    $pending = DB::table('exchange_requests')->where('status', 'PENDING')->get();
                    $refs = DB::table('bookings')->whereIn('id', $pending->pluck('source_booking_id'))->pluck('reference', 'id');
                    $options = $pending->mapWithKeys(fn ($p) => [$p->id => ($refs[$p->source_booking_id] ?? 'Đặt chỗ').' · '.$p->reason])->all();
                    $field['label'] = 'Yêu cầu đổi vé';
                }
                if ($name === 'run_id') {
                    $options = DB::connection('finance')->table('reconciliation_runs')->where('status', 'OPEN')->get()->mapWithKeys(fn ($p) => [$p->id => Ui::date($p->period_start, 'd/m/Y').' – '.Ui::date($p->period_end, 'd/m/Y')])->all();
                    $field['label'] = 'Kỳ đối soát';
                }
                if ($name === 'entry_id') {
                    $entries = DB::connection('finance')->table('reconciliation_entries')->where('result', 'MISMATCH')->whereNull('resolution')->get();
                    $refs = DB::table('bookings')->whereIn('id', $entries->pluck('booking_id'))->pluck('reference', 'id');
                    $options = $entries->mapWithKeys(fn ($p) => [$p->id => ($refs[$p->booking_id] ?? 'Giao dịch').' · Cần đối chiếu'])->all();
                    $field['label'] = 'Sai lệch cần xử lý';
                }
                if ($name === 'record_id' && $entity) {
                    $records = $ops->table($entity)->limit(500)->get();
                    $options = $records->mapWithKeys(fn ($x) => [$x->id ?? $x->code => match ($entity) {
                        'airports' => $x->code.' · '.$x->name,'routes' => $x->origin.' → '.$x->destination,'airport_resources' => $x->airport_code.' · '.$x->code,'aircraft' => $x->registration,default => $x->code.' · '.$x->name
                    }])->all();
                    $field['label'] = 'Chọn hồ sơ cần sửa';
                }
                if ($name === 'change_id' && $action === 'master_approve') {
                    $entities = match ($user->role) {
                        'data_admin' => ['airports', 'routes', 'airport_resources'],'fleet_manager' => ['aircraft'],'crew_scheduler' => ['crew_members'],default => []
                    };
                    $options = $ops->table('master_changes')->whereIn('entity', $entities)->where('status', 'PENDING')->where('created_by', '!=', $user->id)->get()->mapWithKeys(fn ($p) => [$p->id => $p->reason])->all();
                    $field['label'] = 'Đề nghị chờ duyệt';
                }
                if ($name === 'scenario') {
                    $field['type'] = 'hidden';
                    $field['options'] = ['value' => 'approve'];

                    continue;
                }
                if ($options !== null) {
                    $field['type'] = $action === 'support' && $name === 'booking_id' ? 'select-optional' : 'select';
                    $field['options'] = $options;
                }
            }
            unset($field);
        }
        unset($form);

        return array_values(array_filter($forms, fn ($f) => $f['action'] !== 'read_notice'));
    }
}
