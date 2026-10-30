<?php

namespace App\Modules\Flights;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightCatalog
{
    public function get(int $id): object
    {
        return DB::connection('ops')->table('flights')->find($id) ?? abort(404);
    }

    public function airports()
    {
        return DB::connection('ops')->table('airports')->orderBy('code')->get();
    }

    public function search(array $filters)
    {
        $query = DB::connection('ops')->table('flights')->whereIn('status', ['SCHEDULED', 'BOARDING', 'DELAYED'])->where('departure', '>', now());
        foreach (['origin', 'destination'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['date'])) {
            $start = Carbon::parse($filters['date'], 'Asia/Ho_Chi_Minh')->utc();
            $query->where('departure', '>=', $start)->where('departure', '<', $start->copy()->addDay());
        }
        $flights = $query->orderBy('departure')->paginate(8)->withQueryString();
        foreach ($flights as $f) {
            $stock = DB::table('inventory')->where('flight_id', $f->id)->first();
            $used = DB::table('bookings')->where('flight_id', $f->id)->where(function ($q) {
                $q->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED', 'REFUND_PENDING', 'EXCHANGE_PENDING'])->orWhere(fn ($x) => $x->where('status', 'HELD')->where('expires_at', '>', now()));
            })->count();
            $f->price = $stock?->price;
            $f->fares = DB::table('fare_classes')->where('flight_id', $f->id)->where('active', true)->get();
            $f->remaining = ($stock?->status === 'OPEN') ? max(0, ($stock?->capacity ?? 0) - $used) : 0;
        }

        return $flights;
    }
}
