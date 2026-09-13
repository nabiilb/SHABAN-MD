<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(false);
        Model::unguard(false);

        /*
         * InnoDB before MySQL 5.7 caps an index entry at 767 bytes, and a
         * utf8mb4 VARCHAR(255) is 1020 — so an indexed or unique string column
         * of the default length cannot be created there at all. 191 * 4 fits,
         * and is long enough for every string this application indexes.
         */
        Schema::defaultStringLength(191);

        /*
         * Coarse gates used by the Blade navigation and route groups. Fine
         * grained record checks stay in the policies.
         */
        Gate::define('access-admin', fn ($user) => $user->isAdmin());
        Gate::define('access-instructor', fn ($user) => $user->isInstructor());
        Gate::define('access-student', fn ($user) => $user->isStudent());
        Gate::define('view-company-finance', fn ($user) => $user->isAdmin());
        Gate::define('view-audit-logs', fn ($user) => $user->isAdmin());
        Gate::define('manage-settings', fn ($user) => $user->isAdmin());
        Gate::define('manage-users', fn ($user) => $user->isAdmin());

        View::composer('*', function ($view) {
            $view->with('appSettings', [
                'school_name' => Setting::get('school_name', config('app.name')),
                'currency' => Setting::get('currency', 'USD'),
                'currency_symbol' => Setting::get('currency_symbol', '$'),
            ]);
        });
    }
}
