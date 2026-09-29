<?php

namespace App\Support;

use App\Domain\Dates;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** The signed-in session: absolute end, the AuthSession body, ending sessions. */
final class AuthSession
{
    public const EXPIRES_KEY = 'lab.session_expires_at';

    public static function start(Request $request, User $user): int
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $expires = Dates::nowMs() + config('lab.auth.session_minutes') * 60_000;
        $request->session()->put(self::EXPIRES_KEY, $expires);

        return $expires;
    }

    public static function expiresAt(Request $request): ?int
    {
        $v = $request->session()->get(self::EXPIRES_KEY);

        return is_int($v) ? $v : null;
    }

    public static function expired(Request $request): bool
    {
        $at = self::expiresAt($request);

        return $at === null || $at <= Dates::nowMs();
    }

    public static function end(Request $request, bool $keepExpiredMarker = false): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($keepExpiredMarker) {
            // Lets the next 401 say "session expired" rather than "please sign in".
            $request->session()->put(self::EXPIRES_KEY, 0);
        }
    }

    /** Ends every sign-in of a user (password reset, disabled account), optionally keeping the current one. */
    public static function endAllFor(string $userId, ?string $exceptSessionId = null): void
    {
        DB::table('sessions')->where('user_id', $userId)->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))->delete();
    }

    /** The AuthSession body: { expiresAt, user, permissions }. */
    public static function body(User $user, int $expiresAt): array
    {
        return ['expiresAt' => Dates::iso($expiresAt), 'user' => Present::user($user), 'permissions' => $user->permissions()];
    }
}
