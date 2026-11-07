<?php

namespace App\Http\Middleware;

use App\Modules\Shared\Access;
use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        abort_unless($request->user() && Access::allows($request->user(), ...$roles), 403, 'Không đủ thẩm quyền.');

        return $next($request);
    }
}
