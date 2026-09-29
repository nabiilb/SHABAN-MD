<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Periodic clean-up of credentials that can no longer be used. */
final class Housekeeping
{
    /**
     * Sessions idle past their lifetime, or past their absolute end, more than a
     * week ago (Laravel's session garbage collection is random; this is not).
     *
     * @return array{sessions: int}
     */
    public static function purgeExpiredSessions(?int $nowSeconds = null): array
    {
        $now = $nowSeconds ?? time();
        $idle = max((int) config('session.lifetime'), (int) config('lab.auth.session_minutes')) * 60;
        $sessions = DB::table('sessions')->where('last_activity', '<', $now - $idle - 7 * 86400)->delete();

        return ['sessions' => $sessions];
    }
}
