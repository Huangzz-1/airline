<?php

namespace App\Http\Controllers;

use App\Modules\Boarding\SeatMap;
use App\Modules\Operations\MasterDataService;
use App\Modules\Shared\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CabinController extends Controller
{
    public function show(Request $r)
    {
        Access::role($r->user(), 'fleet_manager');
        $d = $r->validate(['aircraft_id' => 'nullable|integer|min:1', 'layout_preset' => 'nullable|in:LEGACY,AUTO,NARROW,WIDE,REGIONAL']);
        $planes = DB::connection('ops')->table('aircraft')->orderBy('registration')->get();
        $plane = isset($d['aircraft_id']) ? ($planes->firstWhere('id', (int) $d['aircraft_id']) ?? abort(404)) : $planes->first();
        $layout = $plane?->seat_layout;
        if ($plane && isset($d['layout_preset'])) {
            $layout = SeatMap::preset($d['layout_preset'], $plane->type, $plane->capacity);
        }
        $rows = $plane ? SeatMap::rows($plane->capacity, $layout) : [];

        return view('fleet-cabin', ['planes' => $planes, 'plane' => $plane, 'seatRows' => $rows, 'presets' => SeatMap::presets(), 'preview' => isset($d['layout_preset'])]);
    }

    public function propose(Request $r, MasterDataService $service)
    {
        Access::role($r->user(), 'fleet_manager');
        $d = $r->validate(['aircraft_id' => 'required|integer|min:1', 'layout_preset' => 'required|in:LEGACY,AUTO,NARROW,WIDE,REGIONAL', 'reason' => 'required|string|between:5,255']);
        $plane = DB::connection('ops')->table('aircraft')->find($d['aircraft_id']) ?? abort(404);
        $change = $service->propose($r->user(), ['entity' => 'aircraft', 'record_id' => (string) $plane->id, 'reason' => $d['reason'], 'changes' => ['type' => $plane->type, 'capacity' => $plane->capacity, 'layout_preset' => $d['layout_preset']]]);

        return $r->expectsJson() ? response()->json($change, 201) : redirect('/workspace/fleet')->with('message', 'Đã gửi sơ đồ cabin mới để người quản lý đội tàu khác phê duyệt.');
    }
}
