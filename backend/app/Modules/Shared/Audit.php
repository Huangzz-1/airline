<?php

namespace App\Modules\Shared;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function record(string $action, string $id, ?string $before, ?string $after, ?int $actor = null, string $node = 'mysql', ?string $correlation = null): void
    {
        DB::connection($node)->table('audit_logs')->insert([
            'actor_id' => $actor, 'action' => $action, 'object_id' => $id, 'before_state' => $before, 'after_state' => $after,
            'correlation_id' => $correlation ?? (string) Str::uuid(), 'created_at' => now(),
        ]);
    }
}
