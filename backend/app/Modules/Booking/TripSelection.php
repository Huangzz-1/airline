<?php

namespace App\Modules\Booking;

use App\Modules\Flights\FlightCatalog;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class TripSelection
{
    public function encode(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function decode(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            abort(422, 'Lựa chọn chuyến bay không hợp lệ. Vui lòng tìm lại.');
        }
        abort_unless(is_array($data), 422, 'Lựa chọn chuyến bay không hợp lệ.');
        $data = Validator::make($data, [
            'trip_type' => 'required|in:ONE_WAY,ROUND_TRIP', 'passenger_count' => 'required|integer|in:1',
            'outbound' => 'required|array|min:1|max:3', 'inbound' => 'present|array|max:3',
            'outbound.*.flight_id' => 'required|integer', 'outbound.*.fare_class' => 'required|string|regex:/^[A-Z0-9_]{1,20}$/',
            'inbound.*.flight_id' => 'required|integer', 'inbound.*.fare_class' => 'required|string|regex:/^[A-Z0-9_]{1,20}$/',
            'issued_at' => 'required|integer', 'quoted_total' => 'required|integer|min:0',
            'return_date' => 'nullable|date_format:Y-m-d',
        ])->validate();
        abort_unless($data['issued_at'] <= now()->timestamp + 60 && $data['issued_at'] >= now()->subMinutes(30)->timestamp, 409, 'Lựa chọn đã quá 30 phút. Vui lòng tìm lại chuyến bay.');

        return $data;
    }

    public function quote(array $data, bool $complete = true): array
    {
        $all = array_merge($data['outbound'], $data['inbound']);
        $flights = array_map(fn ($s) => app(FlightCatalog::class)->get((int) $s['flight_id']), $all);
        TripItinerary::validate($complete ? $data['trip_type'] : 'ONE_WAY', $flights, count($data['outbound']));
        $directions = ['outbound' => [], 'inbound' => []];
        $total = 0;
        foreach ($all as $i => $segment) {
            $flight = $flights[$i];
            $options = app(FlightCatalog::class)->options(['origin' => $flight->origin, 'destination' => $flight->destination, 'date' => Carbon::parse($flight->departure)->tz('Asia/Ho_Chi_Minh')->toDateString()]);
            $option = collect($options)->first(fn ($o) => $o->flight->id === $flight->id && $o->fare->code === $segment['fare_class']);
            abort_unless($option && $option->remaining >= $data['passenger_count'], 409, 'Chuyến hoặc hạng vé không còn đủ chỗ. Vui lòng tìm lại.');
            $total += (int) $option->fare->price * $data['passenger_count'];
            $directions[$i < count($data['outbound']) ? 'outbound' : 'inbound'][] = (object) ['flight' => $flight, 'fare' => $option->fare];
        }

        return ['directions' => $directions, 'total' => $total, 'data' => $data];
    }
}
