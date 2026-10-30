<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$tables=[
 'ops'=>['airports','routes','airport_resources','aircraft','flights','crew_members','crew_assignments','work_orders','ground_tasks','resource_allocations','disruptions','devices','telemetry','master_changes','flight_changes','change_reservations','outbox','inbox','audit_logs'],
 'mysql'=>['migrations','users','email_verifications','inventory','fare_classes','bookings','tickets','boarding_passes','booking_changes','exchange_requests','baggage','baggage_events','support_cases','support_messages','notifications','aircraft_change_receipts','seat_reassignments','checkout_sessions','outbox','inbox','audit_logs'],
 'finance'=>['checkout_intents','payments','refunds','ledger_entries','reconciliation_runs','reconciliation_entries','outbox','inbox','audit_logs'],
];
$result=[];foreach($tables as $node=>$names)foreach($names as $table)$result[$node][$table]=DB::connection($node)->table($table)->count();
$flightIds=DB::connection('ops')->table('flights')->pluck('id')->all();
if(DB::table('inventory')->whereNotIn('flight_id',$flightIds)->exists())throw new RuntimeException('Orphan inventory projection');
echo json_encode(['counts'=>$result,'projection_references_valid'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
