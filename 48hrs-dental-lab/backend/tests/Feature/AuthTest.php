<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function cookie(\Illuminate\Testing\TestResponse $res, string $name): ?Cookie
    {
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return $c;
            }
        }

        return null;
    }

    public function test_login_sets_an_http_only_session_cookie_and_returns_the_session_never_a_token(): void
    {
        $c = $this->client();
        $res = $c->post('/auth/login', ['email' => self::USERS['admin'], 'password' => self::PASSWORD]);
        $res->assertOk()->assertJsonPath('user.email', self::USERS['admin'])->assertJsonPath('user.role', 'admin');
        $this->assertArrayNotHasKey('token', $res->json());
        $this->assertContains('cases.view', $res->json('permissions'));
        $this->assertSame(self::NOW + 480 * 60_000, strtotime($res->json('expiresAt')) * 1000);

        $session = $this->cookie($res, config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', strtolower($session->getSameSite()));
        // The CSRF cookie is readable by the web app (it echoes it in X-XSRF-TOKEN), nothing else is.
        $this->assertFalse($this->cookie($res, 'XSRF-TOKEN')->isHttpOnly());
        $this->assertSame(self::USERS['admin'], $c->get('/auth/me')->json('user.email'));
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $c = $this->client();
        $c->get('/auth/csrf')->assertNoContent();
        $before = $c->cookies[config('session.cookie')] ?? null;
        $c->post('/auth/login', ['email' => self::USERS['admin'], 'password' => self::PASSWORD])->assertOk();
        $this->assertNotSame($before, $c->cookies[config('session.cookie')]);
    }

    public function test_bad_credentials_get_401_with_the_same_message_for_unknown_emails(): void
    {
        $wrong = $this->client()->post('/auth/login', ['email' => self::USERS['admin'], 'password' => 'not-the-password1']);
        $unknown = $this->client()->post('/auth/login', ['email' => 'nobody@48hrs.lab', 'password' => 'whatever12']);
        $wrong->assertStatus(401);
        $unknown->assertStatus(401);
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_login_body_is_validated(): void
    {
        $res = $this->client()->post('/auth/login', ['email' => 'not-an-email']);
        $res->assertStatus(422)->assertJsonPath('message', 'Some fields need attention.');
        $this->assertEqualsCanonicalizing(['email', 'password'], array_keys($res->json('errors')));
    }

    public function test_disabled_accounts_are_refused_only_after_the_correct_password(): void
    {
        $this->client()->post('/auth/login', ['email' => self::USERS['disabledClient'], 'password' => 'wrong-pass1'])->assertStatus(401);
        $res = $this->client()->post('/auth/login', ['email' => self::USERS['disabledClient'], 'password' => self::PASSWORD]);
        $res->assertStatus(403);
        $this->assertMatchesRegularExpression('/disabled/', $res->json('message'));
    }

    public function test_the_account_locks_after_repeated_failures_stored_in_the_database(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->client()->post('/auth/login', ['email' => self::USERS['delivery'], 'password' => "bad-{$i}-pass"])->assertStatus(401);
        }
        $this->client()->post('/auth/login', ['email' => self::USERS['delivery'], 'password' => 'bad-5-pass'])->assertStatus(429);
        $this->client()->post('/auth/login', ['email' => self::USERS['delivery'], 'password' => self::PASSWORD])->assertStatus(429);
        $u = User::where('email', self::USERS['delivery'])->first();
        $this->assertTrue($u->locked_until->isFuture());

        // The lock ends by itself after LOGIN_LOCK_MINUTES.
        $this->advanceMinutes(16);
        $this->client()->post('/auth/login', ['email' => self::USERS['delivery'], 'password' => self::PASSWORD])->assertOk();
    }

    public function test_passwords_are_stored_hashed(): void
    {
        $hash = User::where('email', self::USERS['admin'])->value('password');
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertStringNotContainsString(self::PASSWORD, $hash);
        $this->assertTrue(Hash::check(self::PASSWORD, $hash));
    }

    public function test_protected_routes_need_a_session(): void
    {
        $anon = $this->client();
        foreach (['/auth/me', '/cases', '/dashboard', '/users', '/notifications'] as $path) {
            $res = $anon->get($path);
            $this->assertSame(401, $res->status(), $path);
            $this->assertNotEmpty($res->json('message'));
        }
        // A forged session cookie is just an unknown session.
        $forged = $this->client();
        $forged->cookies[config('session.cookie')] = 'eyJpdiI6ImJhZCJ9';
        $forged->get('/cases')->assertStatus(401);
    }

    public function test_cross_origin_writes_are_refused(): void
    {
        $c = $this->as('admin');
        $c->request('POST', '/notifications/read-all', [], headers: ['Origin' => 'https://evil.example'])->assertStatus(403)->assertExactJson(['message' => 'Access restricted.']);
        $c->post('/notifications/read-all')->assertNoContent();
    }

    public function test_unknown_api_routes_answer_404_with_the_standard_body(): void
    {
        $this->as('admin')->get('/does-not-exist')->assertStatus(404)->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_the_session_end_is_absolute_and_refresh_never_extends_it(): void
    {
        $c = $this->as('reception');
        $session = $c->get('/auth/me')->json();

        $this->advanceMinutes(200);
        $refreshed = $c->post('/auth/refresh');
        $refreshed->assertOk();
        $this->assertSame($session['expiresAt'], $refreshed->json('expiresAt'));
        $c->get('/cases')->assertOk();

        $this->advanceMinutes(281); // 481 minutes after sign-in: past the 8-hour session
        $late = $c->get('/cases');
        $late->assertStatus(401);
        $this->assertMatchesRegularExpression('/expired/i', $late->json('message'));
        $c->post('/auth/refresh')->assertStatus(401);
    }

    public function test_logout_ends_the_session_and_a_copied_cookie_is_dead(): void
    {
        $c = $this->as('qc');
        $copy = $c->cookies;
        $userId = User::where('email', self::USERS['qc'])->value('id');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $userId)->count());

        $c->post('/auth/logout')->assertNoContent();
        $c->get('/auth/me')->assertStatus(401);
        $stolen = $this->client();
        $stolen->cookies = $copy;
        $stolen->get('/auth/me')->assertStatus(401);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $userId)->count());
    }

    public function test_disabling_a_user_ends_their_session_immediately(): void
    {
        $admin = $this->as('superAdmin');
        $victim = $this->as('technician2');
        $victim->get('/auth/me')->assertOk();
        $id = User::where('email', self::USERS['technician2'])->value('id');
        $admin->patch("/users/{$id}/status", ['active' => false])->assertOk();
        $victim->get('/auth/me')->assertStatus(401);
        $victim->post('/auth/refresh')->assertStatus(401);
    }

    public function test_permission_changes_apply_to_live_sessions_on_the_next_request(): void
    {
        $admin = $this->as('superAdmin');
        $reception = $this->as('reception');
        $reception->get('/patients')->assertOk();
        $role = collect($admin->get('/roles')->json())->firstWhere('key', 'reception');
        $without = array_values(array_diff($role['permissions'], ['patients.view']));
        $admin->put('/roles/reception', ['permissions' => $without])->assertOk();
        $reception->get('/patients/pat_1024')->assertStatus(403)->assertExactJson(['message' => 'Access restricted.']);
        $this->assertNotContains('patients.view', $reception->get('/auth/me')->json('permissions'));
    }

    public function test_forgot_then_reset_old_password_fails_new_works_other_sessions_end(): void
    {
        $other = $this->as('manager');
        $unknown = $this->client()->post('/auth/forgot-password', ['email' => 'nobody@48hrs.lab']);
        $unknown->assertOk();
        $this->assertNull($unknown->json('devResetUrl'));

        $res = $this->client()->post('/auth/forgot-password', ['email' => self::USERS['manager']]);
        $res->assertOk();
        $this->assertSame($unknown->json('message'), $res->json('message'));
        parse_str(parse_url($res->json('devResetUrl'), PHP_URL_QUERY), $q);
        $token = $q['token'];
        $stored = DB::table('password_reset_tokens')->where('email', self::USERS['manager'])->value('token');
        $this->assertNotSame($token, $stored); // only a hash is stored

        $mismatch = $this->client()->post('/auth/reset-password', ['token' => $token, 'email' => self::USERS['manager'], 'password' => 'NewPass123', 'passwordConfirmation' => 'Different1']);
        $mismatch->assertStatus(422);
        $this->assertNotEmpty($mismatch->json('errors.passwordConfirmation'));
        $this->client()->post('/auth/reset-password', ['token' => $token, 'email' => self::USERS['manager'], 'password' => 'short', 'passwordConfirmation' => 'short'])->assertStatus(422);

        $this->client()->post('/auth/reset-password', ['token' => $token, 'email' => self::USERS['manager'], 'password' => 'NewPass123', 'passwordConfirmation' => 'NewPass123'])->assertOk();
        $other->get('/auth/me')->assertStatus(401);
        $this->client()->post('/auth/login', ['email' => self::USERS['manager'], 'password' => self::PASSWORD])->assertStatus(401);
        $this->client()->post('/auth/login', ['email' => self::USERS['manager'], 'password' => 'NewPass123'])->assertOk();
        $this->client()->post('/auth/reset-password', ['token' => $token, 'email' => self::USERS['manager'], 'password' => 'Another123', 'passwordConfirmation' => 'Another123'])->assertStatus(422);
    }

    public function test_reset_link_emails_are_queued_to_the_user(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->client()->post('/auth/forgot-password', ['email' => self::USERS['manager']])->assertOk();
        $user = User::where('email', self::USERS['manager'])->first();
        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\ResetPasswordNotification::class, function ($n) {
            return $n instanceof \Illuminate\Contracts\Queue\ShouldQueue;
        });
    }

    public function test_reset_tokens_expire(): void
    {
        $res = $this->client()->post('/auth/forgot-password', ['email' => self::USERS['manager']]);
        parse_str(parse_url($res->json('devResetUrl'), PHP_URL_QUERY), $q);
        $this->advanceMinutes((int) config('auth.passwords.users.expire') + 1);
        $this->client()->post('/auth/reset-password', ['token' => $q['token'], 'email' => self::USERS['manager'], 'password' => 'NewPass123', 'passwordConfirmation' => 'NewPass123'])
            ->assertStatus(422)->assertJsonPath('message', 'This reset link is invalid or has expired.');
    }
}
