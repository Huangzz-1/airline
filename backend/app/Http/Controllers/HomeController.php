<?php

namespace App\Http\Controllers;

use App\Modules\Flights\FlightCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Request $r, FlightCatalog $catalog)
    {
        if ($r->user() && $r->user()->role !== 'passenger') {
            return redirect('/staff');
        }

        $routes = DB::connection('ops')->table('routes')->where('active', true)->whereColumn('origin', '<>', 'destination')->get(['origin', 'destination']);
        $stats = [
            'airports' => $routes->flatMap(fn ($route) => [$route->origin, $route->destination])->unique()->count(),
            'routes' => $routes->count(),
            'flights' => DB::connection('ops')->table('flights')->whereIn('status', ['SCHEDULED', 'BOARDING', 'DELAYED'])->where('departure', '>', now())->count(),
        ];
        $personal = ['trips' => 0, 'notifications' => 0];
        $upcoming = null;
        if ($r->user()) {
            $bookings = DB::table('bookings')->where('user_id', $r->user()->id)->where(fn ($q) => $q
                ->whereIn('status', ['PAYMENT_PENDING', 'CONFIRMED', 'CHECKED_IN', 'BOARDED'])
                ->orWhere(fn ($held) => $held->where('status', 'HELD')->where('expires_at', '>', now())))->get();
            $flights = DB::connection('ops')->table('flights')->whereIn('id', $bookings->pluck('flight_id'))
                ->whereIn('status', ['SCHEDULED', 'BOARDING', 'DELAYED'])->where('departure', '>', now())->orderBy('departure')->get();
            $active = $bookings->whereIn('flight_id', $flights->pluck('id'));
            $personal['trips'] = $active->map(fn ($b) => $b->reservation_id ?: $b->id)->unique()->count();
            $personal['notifications'] = DB::table('notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->count();
            if ($flights->isNotEmpty()) {
                $flight = $flights->first();
                $booking = $active->firstWhere('flight_id', $flight->id);
                $reservation = $booking->reservation_id ? DB::table('reservations')->where('user_id', $r->user()->id)->find($booking->reservation_id) : null;
                $upcoming = [
                    'flight' => $flight, 'booking' => $booking,
                    'reference' => $reservation?->reference ?? $booking->reference,
                    'url' => $reservation ? '/reservations/'.$reservation->id : '/journeys/'.$booking->id,
                    'round_trip' => $reservation?->trip_type === 'ROUND_TRIP',
                ];
            }
        }

        return view('home', ['stats' => $stats, 'personal' => $personal, 'upcoming' => $upcoming, 'airports' => $catalog->airports(), 'destinationNetwork' => $catalog->destinationNetwork()]);
    }
}
