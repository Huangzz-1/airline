<?php
// One-time cleanup of the untouched onboarding fixtures; refuses any user activity.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
if(!app()->environment('local') || config('database.connections.mysql.database')!=='airline')throw new RuntimeException('Cleanup is restricted to the local onboarding database');
foreach(['ops','mysql','finance'] as $node){
    if(config("database.connections.$node.database")!=='airline')throw new RuntimeException('Unexpected database');
    foreach(['audit_logs','outbox','inbox'] as $table)if(DB::connection($node)->table($table)->exists())throw new RuntimeException('Activity exists; cleanup refused');
}
foreach(['users','bookings','tickets','checkout_sessions','exchange_requests','fare_classes','support_cases','baggage'] as $table)if(DB::table($table)->exists())throw new RuntimeException('User data exists; cleanup refused');
foreach(['checkout_intents','payments','refunds','ledger_entries','reconciliation_runs'] as $table)if(DB::connection('finance')->table($table)->exists())throw new RuntimeException('Finance data exists; cleanup refused');
$ops=DB::connection('ops');
foreach(['master_changes','crew_assignments','flight_changes','change_reservations','ground_tasks','resource_allocations','disruptions','telemetry'] as $table)if($ops->table($table)->exists())throw new RuntimeException('Operational activity exists; cleanup refused');
$flights=$ops->table('flights')->orderBy('id')->get();
if($flights->count()!==7 || $ops->table('aircraft')->count()!==2 || $ops->table('crew_members')->count()!==2 || $ops->table('work_orders')->count()!==1 || $ops->table('devices')->count()!==1)throw new RuntimeException('Dataset differs from onboarding fixtures');
foreach($flights as $f)if($f->id<1 || $f->id>7 || $f->number!=='AS'.(100+$f->id) || $f->aircraft_id!==1 || $f->status!==($f->id===1?'BOARDING':'SCHEDULED') || $f->version!==1 || $f->actual_departure || $f->actual_arrival)throw new RuntimeException('Flight fixtures changed; cleanup refused');
if($ops->table('aircraft')->whereIn('registration',['VN-DEMO01','VN-DEMO-MRO'])->count()!==2 || $ops->table('crew_members')->whereIn('code',['DEMO-PILOT','DEMO-CABIN'])->count()!==2 || !$ops->table('devices')->where('id',100)->where('code','SIM-HAN-TEMP')->exists() || !$ops->table('work_orders')->where('id',100)->where('created_by',0)->where('status','OPEN')->exists())throw new RuntimeException('Fixture identities changed; cleanup refused');
DB::transaction(fn()=>DB::table('inventory')->whereIn('flight_id',$flights->pluck('id'))->delete());
$ops->transaction(function()use($ops,$flights){$ops->table('flights')->whereIn('id',$flights->pluck('id'))->delete();$ops->table('work_orders')->where('id',100)->delete();$ops->table('aircraft')->whereIn('id',[1,100])->delete();$ops->table('crew_members')->whereIn('id',[1001,1002])->delete();$ops->table('devices')->where('id',100)->delete();});
echo "Removed untouched sample flights, aircraft, crew, work order, device and inventory. Airport master records retained.\n";
