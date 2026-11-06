<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Booking\BookingService;
use App\Modules\Booking\ReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Historical group records for read/payment/refund regression tests only. */
final class LegacyReservationFixture
{
    public static function create(User $user, array $input): object
    {
        $people = $input['passengers'];
        $input['passengers'] = [$people[0]];

        return DB::transaction(function () use ($user, $input, $people) {
            $reservation = app(ReservationService::class)->hold($user, $input);
            $segments = DB::table('reservation_segments')->where('reservation_id', $reservation->id)->orderBy('position')->get();
            foreach (array_slice($people, 1) as $offset => $person) {
                $personId = (string) Str::uuid();
                DB::table('reservation_passengers')->insert($person + ['id' => $personId, 'reservation_id' => $reservation->id, 'position' => $offset + 2]);
                foreach ($segments as $segment) {
                    $booking = app(BookingService::class)->hold($user->id, $segment->flight_id, (string) Str::uuid(), $segment->fare_class);
                    DB::table('bookings')->where('id', $booking->id)->update(['reservation_id' => $reservation->id, 'passenger_id' => $personId, 'reservation_segment_id' => $segment->id, 'reservation_initial' => true, 'expires_at' => $reservation->expires_at]);
                }
            }
            $bookings = DB::table('bookings as b')->join('reservation_passengers as p', 'p.id', '=', 'b.passenger_id')->join('reservation_segments as s', 's.id', '=', 'b.reservation_segment_id')
                ->where('b.reservation_id', $reservation->id)->orderBy('s.position')->orderBy('p.position')->select('b.*')->get();
            foreach ($bookings as $b) {
                DB::table('bookings')->where('id', $b->id)->update(['reference' => 'FIX'.Str::upper(Str::random(15))]);
            }
            foreach ($bookings as $i => $b) {
                DB::table('bookings')->where('id', $b->id)->update(['reference' => $reservation->reference.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)]);
            }
            DB::table('reservations')->where('id', $reservation->id)->update(['total_amount' => $bookings->sum('amount')]);

            return app(ReservationService::class)->owned($user, $reservation->id);
        });
    }
}
