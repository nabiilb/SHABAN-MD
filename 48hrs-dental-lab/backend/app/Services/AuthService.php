<?php

namespace App\Services;

use App\Domain\ApiErrors;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Activity;
use App\Support\AuthSession;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Sign-in, sessions and password reset on Laravel's session guard and password
 * broker. Wrong passwords count per account (failed_logins / locked_until in the
 * database); after LOGIN_MAX_ATTEMPTS the account is locked for LOGIN_LOCK_MINUTES.
 */
class AuthService
{
    private const INVALID_LOGIN = 'Email or password not recognised.';

    private const INVALID_RESET = 'This reset link is invalid or has expired.';

    public function login(Request $request, string $email, string $password): array
    {
        $user = User::where('email', strtolower(trim($email)))->first();
        if (! $user) {
            // Same work as a real check, so the response time does not reveal which e-mails exist.
            Hash::check($password, self::dummyHash());
            throw ApiException::unauthorized(self::INVALID_LOGIN);
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw ApiException::tooManyRequests();
        }
        if (! Hash::check($password, $user->password)) {
            $failed = $user->failed_logins + 1;
            $lock = $failed >= config('lab.auth.max_attempts');
            $user->forceFill(['failed_logins' => $lock ? 0 : $failed, 'locked_until' => $lock ? now()->addMinutes(config('lab.auth.lock_minutes')) : $user->locked_until])->save();
            throw $lock ? ApiException::tooManyRequests() : ApiException::unauthorized(self::INVALID_LOGIN);
        }
        // Only reveal "disabled" after the correct password, so it cannot be used to probe accounts.
        if (! $user->active) {
            throw new ApiException(403, 'This account has been disabled by the Super Admin.');
        }
        if (Hash::needsRehash($user->password)) {
            $user->password = $password;
        }
        DB::transaction(function () use ($user) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
            Activity::log($user, 'auth.login', 'Signed in', 'auth');
        });
        $expires = AuthSession::start($request, $user);

        return AuthSession::body($user, $expires);
    }

    /** Always answers the same way, so it cannot be used to discover accounts. */
    public function forgotPassword(string $email): array
    {
        $result = ['message' => 'If an account exists for that email, a reset link has been sent.'];
        $user = User::where('email', strtolower(trim($email)))->first();
        if (! $user?->active) {
            return $result;
        }
        /** @var PasswordBroker $broker */
        $broker = Password::broker();
        $token = $broker->createToken($user);
        $user->sendPasswordResetNotification($token);
        // Development convenience only: with the log mailer there is no inbox to open.
        if (! app()->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
            $result['devResetUrl'] = '/reset-password?token='.rawurlencode($token).'&email='.rawurlencode($user->email);
        }

        return $result;
    }

    public function resetPassword(string $token, string $email, string $password): array
    {
        $status = Password::broker()->reset(
            ['email' => strtolower(trim($email)), 'password' => $password, 'token' => $token],
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $user->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null])->save();
                    // Every existing sign-in ends: whoever knew the old password is signed out.
                    AuthSession::endAllFor($user->id);
                    Activity::log($user, 'auth.password_reset', 'Reset password', 'auth');
                });
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::unprocessable(self::INVALID_RESET);
        }

        return ['message' => 'Your password has been reset. You can sign in now.'];
    }

    public function me(Request $request): array
    {
        return AuthSession::body($request->user(), AuthSession::expiresAt($request));
    }

    /** Sessions are cookie-based: "refresh" confirms the session is still alive and returns it (401 when not). */
    public function refresh(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->active || AuthSession::expired($request)) {
            if ($user) {
                AuthSession::end($request, keepExpiredMarker: true);
            }
            throw ApiException::unauthorized(ApiErrors::SESSION_EXPIRED);
        }

        return $this->me($request);
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('dummy-password-for-timing-1');
    }
}
