<?php

namespace App\Http\Controllers;

use App\Modules\Boarding\BoardingService;
use App\Modules\Flights\FlightCatalog;
use App\Modules\Shared\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PassengerDeskController extends Controller
{
    public function index(Request $r)
    {
        Access::role($r->user(), 'airport_agent');
        $d = $r->validate(['q' => 'nullable|string|max:100']);
        $flights = DB::connection('ops')->table('flights')->where('origin', $r->user()->airport_code)->where('departure', '>', now()->subHours(12))->whereNotIn('status', ['DRAFT', 'PENDING'])->get()->keyBy('id');
        $q = DB::table('bookings as b')->join('users as u', 'u.id', '=', 'b.user_id')->whereIn('b.flight_id', $flights->keys())->select('b.*', 'u.name as passenger_name');
        $q->where(function ($q) use ($d) {
            if (empty($d['q'])) {
                $q->whereRaw('1=0');

                return;
            }
            $term = '%'.addcslashes($d['q'], '\\%_').'%';
            $q->where('b.reference', 'like', $term)->orWhere('u.name', 'like', $term)->orWhere('u.email', 'like', $term);
        });
        $bookings = $q->latest('b.created_at')->paginate(8)->withQueryString();
        foreach ($bookings as $b) {
            $b->flight = $flights[$b->flight_id];
            $stock = DB::table('inventory')->where('flight_id', $b->flight_id)->first();
            $b->capacity = $stock?->capacity ?? 0;
            $b->frozen = (bool) $stock?->frozen_change_id;
            $b->occupied = DB::table('bookings')->where('flight_id', $b->flight_id)->whereNotNull('seat')->pluck('seat')->all();
        }

        return view('passenger-desk', compact('bookings'));
    }

    public function checkin(Request $r, string $id, BoardingService $service, FlightCatalog $catalog)
    {
        Access::role($r->user(), 'airport_agent');
        $d = $r->validate(['seat' => 'required|string|max:4']);
        $b = DB::table('bookings')->find($id) ?? abort(404);
        $f = $catalog->get($b->flight_id);
        abort_unless($f->origin === $r->user()->airport_code, 404);
        $service->checkin($b->id, $b->user_id, $d['seat'], $r->user()->id);

        return back()->with('message', 'Đã làm thủ tục cho mã đặt chỗ '.$b->reference.'. Có thể in thẻ lên tàu.');
    }
}
