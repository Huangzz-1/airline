<?php

namespace App\Http\Controllers;

use App\Modules\Boarding\BoardingService;
use App\Modules\Flights\FlightCatalog;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TravelDocumentController extends Controller
{
    public function show(Request $r, string $id, string $kind = 'ticket')
    {
        $b = DB::table('bookings')->find($id) ?? abort(404);
        $f = app(FlightCatalog::class)->get($b->flight_id);
        if ($r->user()->role === 'passenger') {
            abort_unless($b->user_id === $r->user()->id, 404);
        } else {
            abort_unless($r->user()->role === 'airport_agent' && $r->user()->airport_code === $f->origin, 404);
        }
        abort_unless(in_array($b->status, ['CONFIRMED', 'CHECKED_IN', 'BOARDED']), 409, 'Vé không còn hiệu lực hoặc chưa được thanh toán.');
        $ticket = DB::table('tickets')->where('booking_id', $id)->first() ?? abort(404);
        $pass = null;
        $qr = null;
        if ($kind === 'boarding-pass') {
            abort_unless(in_array($b->status, ['CHECKED_IN', 'BOARDED']), 409, 'Vui lòng làm thủ tục trước khi in thẻ lên tàu.');
            $stock = DB::table('inventory')->where('flight_id', $f->id)->first();
            abort_if($stock?->frozen_change_id, 409, 'Chuyến đang cập nhật tàu bay. Vui lòng lấy thẻ mới sau khi hoàn tất.');
            $pass = app(BoardingService::class)->pass($id, $b->user_id);
            abort_if($pass->revoked_at || now()->gte($pass->expires_at), 409, 'Thẻ lên tàu đã hết hạn hoặc bị thu hồi.');
            $qr = (new Writer(new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd)))->writeString($pass->token);
        }
        $passenger = DB::table('users')->find($b->user_id);

        $mode = DB::table('checkout_sessions')->where('booking_id', $b->id)->value('mode') ?? 'simulator';

        return view('travel-document', compact('b', 'f', 'ticket', 'pass', 'qr', 'passenger', 'kind', 'mode'));
    }
}
