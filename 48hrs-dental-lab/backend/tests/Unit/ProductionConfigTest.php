<?php

namespace Tests\Unit;

use App\Support\ProductionConfig;
use PHPUnit\Framework\TestCase;

class ProductionConfigTest extends TestCase
{
    private const GOOD = [
        'app.env' => 'production', 'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'app.debug' => false,
        'app.url' => 'https://sooryoscan.com/48hrs_lab', 'lab.frontend_url' => 'https://sooryoscan.com/48hrs_lab', 'lab.timezone' => 'Africa/Mogadishu',
        'lab.allowed_origins' => [], 'session.secure' => true, 'session.same_site' => 'lax', 'session.driver' => 'database',
        'mail.default' => 'smtp', 'mail.from.address' => 'no-reply@sooryoscan.com', 'mail.mailers.smtp.password' => 'real-secret', 'mail.mailers.smtp.username' => 'no-reply@sooryoscan.com',
        'database.connections.mysql.password' => 'real-db-secret', 'queue.default' => 'database',
    ];

    private function fails(array $over, string $field): void
    {
        $problems = implode("\n", ProductionConfig::problems([...self::GOOD, ...$over]));
        $this->assertMatchesRegularExpression("/^{$field}:/m", $problems, json_encode($over));
    }

    public function test_a_complete_production_configuration_is_accepted(): void
    {
        $this->assertSame([], ProductionConfig::problems(self::GOOD));
    }

    public function test_unsafe_production_settings_are_refused(): void
    {
        $this->fails(['app.key' => ''], 'APP_KEY');
        $this->fails(['app.debug' => true], 'APP_DEBUG');
        $this->fails(['app.url' => 'http://sooryoscan.com'], 'APP_URL');
        $this->fails(['app.url' => 'https://localhost'], 'APP_URL');
        $this->fails(['lab.frontend_url' => 'http://localhost:5173'], 'FRONTEND_URL');
        $this->fails(['session.secure' => false], 'SESSION_SECURE_COOKIE');
        $this->fails(['session.driver' => 'cookie'], 'SESSION_DRIVER');
        $this->fails(['mail.default' => 'log'], 'MAIL_MAILER');
        $this->fails(['mail.from.address' => 'hello@example.com.CHANGE_ME'], 'MAIL_FROM_ADDRESS');
        $this->fails(['lab.allowed_origins' => ['http://sooryoscan.com']], 'CORS_ALLOWED_ORIGINS');
        $this->fails(['queue.default' => 'sync'], 'QUEUE_CONNECTION');
        $this->fails(['lab.timezone' => 'Mars/Olympus'], 'LAB_TIMEZONE');
        $this->fails(['database.connections.mysql.password' => 'CHANGE_ME'], 'DB_PASSWORD');
        $this->fails(['database.connections.mysql.password' => ''], 'DB_PASSWORD');
        $this->fails(['mail.mailers.smtp.password' => 'CHANGE_ME_smtp'], 'MAIL_PASSWORD');
        $this->fails(['app.key' => 'CHANGE_ME'], 'APP_KEY');
        // Placeholders are refused in every environment, not only production.
        $this->fails(['app.env' => 'local', 'database.connections.mysql.password' => 'CHANGE_ME'], 'DB_PASSWORD');
        $this->fails(['app.env' => 'local', 'session.secure' => false, 'session.same_site' => 'none'], 'SESSION_SAME_SITE');
    }
}
