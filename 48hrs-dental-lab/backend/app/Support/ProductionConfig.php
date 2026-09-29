<?php

namespace App\Support;

use DateTimeZone;

/**
 * Deployment settings that must never reach production. Checked when the app
 * boots in production (it refuses to serve requests) and by `php artisan lab:check-config`.
 */
final class ProductionConfig
{
    /** @return list<string> problems, empty when the configuration is acceptable */
    public static function problems(array $c): array
    {
        $p = [];
        $placeholder = fn ($v) => is_string($v) && stripos($v, 'CHANGE_ME') !== false;
        $localhost = fn ($v) => is_string($v) && preg_match('/localhost|127\.0\.0\.1|\[::1\]/i', $v);

        try {
            new DateTimeZone((string) $c['lab.timezone']);
        } catch (\Throwable) {
            $p[] = "LAB_TIMEZONE: unknown time zone \"{$c['lab.timezone']}\"";
        }
        foreach (['app.key' => 'APP_KEY', 'database.connections.mysql.password' => 'DB_PASSWORD', 'mail.mailers.smtp.password' => 'MAIL_PASSWORD', 'mail.mailers.smtp.username' => 'MAIL_USERNAME'] as $key => $env) {
            if ($placeholder($c[$key] ?? null)) {
                $p[] = "{$env}: replace the template placeholder with the real value";
            }
        }
        if (($c['session.same_site'] ?? null) === 'none' && ! ($c['session.secure'] ?? false)) {
            $p[] = 'SESSION_SAME_SITE: "none" requires SESSION_SECURE_COOKIE=true';
        }
        if (($c['app.env'] ?? null) !== 'production') {
            return $p;
        }

        if (empty($c['app.key'])) {
            $p[] = 'APP_KEY: generate one with php artisan key:generate';
        }
        if (($c['database.connections.mysql.password'] ?? '') === '') {
            $p[] = 'DB_PASSWORD: set the database password';
        }
        if (! empty($c['app.debug'])) {
            $p[] = 'APP_DEBUG: must be false in production (it would expose stack traces)';
        }
        foreach (['app.url' => 'APP_URL', 'lab.frontend_url' => 'FRONTEND_URL'] as $key => $env) {
            $v = (string) ($c[$key] ?? '');
            if (! str_starts_with($v, 'https://') || $localhost($v)) {
                $p[] = "{$env}: set it to the public https:// address";
            }
        }
        if (! ($c['session.secure'] ?? false)) {
            $p[] = 'SESSION_SECURE_COOKIE: cookies must be Secure in production (true)';
        }
        if (! in_array($c['session.driver'] ?? null, ['database', 'redis', 'file'], true)) {
            $p[] = 'SESSION_DRIVER: use database (revocable sessions)';
        }
        if (in_array($c['mail.default'] ?? null, ['log', 'array'], true)) {
            $p[] = 'MAIL_MAILER: configure SMTP so reset links are e-mailed, not logged';
        }
        if ($localhost($c['mail.from.address'] ?? '') || $placeholder($c['mail.from.address'] ?? '')) {
            $p[] = 'MAIL_FROM_ADDRESS: set a real sender address';
        }
        foreach ($c['lab.allowed_origins'] ?? [] as $origin) {
            if (! str_starts_with($origin, 'https://')) {
                $p[] = "CORS_ALLOWED_ORIGINS: \"{$origin}\" must be https://";
            }
        }
        if (in_array($c['queue.default'] ?? null, ['sync', 'null'], true)) {
            $p[] = 'QUEUE_CONNECTION: use database so mail is sent by the queue worker';
        }

        return $p;
    }

    public static function current(): array
    {
        return collect(['app.env', 'app.key', 'app.debug', 'app.url', 'lab.frontend_url', 'lab.timezone', 'lab.allowed_origins', 'session.secure', 'session.same_site', 'session.driver',
            'mail.default', 'mail.from.address', 'mail.mailers.smtp.password', 'mail.mailers.smtp.username', 'database.connections.mysql.password', 'queue.default'])
            ->mapWithKeys(fn ($k) => [$k => config($k)])->all();
    }
}
