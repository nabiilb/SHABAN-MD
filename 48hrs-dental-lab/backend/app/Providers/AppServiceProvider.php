<?php

namespace App\Providers;

use App\Domain\ApiErrors;
use App\Domain\Permissions;
use App\Models\CaseAttachment;
use App\Models\DentalCase;
use App\Models\User;
use App\Policies\CaseAttachmentPolicy;
use App\Policies\DentalCasePolicy;
use App\Support\ProductionConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Production refuses to run with unsafe settings (debug on, http URLs, insecure cookies, placeholders…):
        // no request is served and no command runs, except the few needed to fix and check the configuration.
        if ($this->app->isProduction() && ! $this->isSetupCommand() && ($problems = ProductionConfig::problems(ProductionConfig::current()))) {
            throw new RuntimeException("Invalid production configuration:\n  - ".implode("\n  - ", $problems));
        }

        // Every permission key is a gate: $user->can('payments.record'). Roles are editable, so this reads the database.
        $keys = array_flip(Permissions::allKeys());
        Gate::before(function (User $user, string $ability) use ($keys) {
            return isset($keys[$ability]) ? $user->hasPermission($ability) : null;
        });
        Gate::policy(DentalCase::class, DentalCasePolicy::class);
        Gate::policy(CaseAttachment::class, CaseAttachmentPolicy::class);

        // The limiter passes Retry-After / X-RateLimit-* headers; keep them on the JSON body.
        $tooMany = fn (Request $request, array $headers) => new JsonResponse(['message' => ApiErrors::RATE_LIMITED], 429, $headers);
        // Per client IP (behind a proxy, set TRUSTED_PROXIES so this is the real client).
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(config('lab.auth.api_rate_limit'))->by('api|'.$r->ip())->response($tooMany));
        RateLimiter::for('login', fn (Request $r) => Limit::perMinutes(15, config('lab.auth.rate_limit'))->by('login|'.$r->ip())->response($tooMany));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perHour(config('lab.auth.reset_rate_limit'))->by('reset|'.$r->ip())->response($tooMany));
    }

    /** Artisan commands that must work on a not-yet-valid production configuration. */
    private function isSetupCommand(): bool
    {
        return $this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? '', ['key:generate', 'lab:check-config', 'config:clear', 'optimize:clear', 'cache:clear', 'down', 'up', 'list', 'about'], true);
    }
}
