<?php

namespace App\Modules\Shared;

use App\Models\User;

final class Access
{
    const ROLES = ['passenger', 'airport_agent', 'finance', 'admin', 'data_admin', 'operations', 'ops_manager', 'fleet_manager', 'maintenance', 'crew_scheduler', 'ground_agent', 'customer_service', 'analyst', 'integration_admin', 'auditor'];

    const SCOPED = ['airport_agent', 'operations', 'ops_manager', 'ground_agent', 'integration_admin'];

    public static function allows(User $u, string ...$roles): bool
    {
        return in_array($u->role, $roles, true);
    }

    public static function isAdmin(User $u): bool
    {
        return $u->role === 'admin';
    }

    public static function role(User $u, string ...$roles): void
    {
        abort_unless(self::allows($u, ...$roles), 403, 'Không đủ thẩm quyền.');
    }

    public static function airport(User $u, string $code): void
    {
        if (in_array($u->role, self::SCOPED, true)) {
            abort_unless($u->airport_code === $code, 403, 'Ngoài sân bay được phân công.');
        }
    }
}
