<?php

namespace App\Modules\Shared;

use App\Models\User;
use App\Notifications\ServiceMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Events
{
    public static function emit(string $node, string $type, array $payload): string
    {
        $id = (string) Str::uuid();
        DB::connection($node)->table('outbox')->insert(['id' => $id, 'type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public static function notify(int $user, string $title, string $body): void
    {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'user_id' => $user, 'title' => $title, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        $recipient = User::find($user);
        if ($recipient && $recipient->hasVerifiedEmail()) {
            $recipient->notify(new ServiceMessage($title, $body));
        }
    }
}
