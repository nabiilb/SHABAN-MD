<?php

namespace Tests\Unit;

use App\Http\Requests;
use Tests\TestCase;

/**
 * Laravel request validation against the web app's Zod schemas: the same bodies
 * pass or fail with the same fields and messages, and valid bodies normalise the
 * same way (trim, lower-case e-mail, coerced numbers, defaults).
 */
class ValidationParityTest extends TestCase
{
    private const CLASSES = [
        'login' => Requests\LoginRequest::class,
        'forgotPassword' => Requests\ForgotPasswordRequest::class,
        'resetPassword' => Requests\ResetPasswordRequest::class,
        'createCase' => Requests\CreateCaseRequest::class,
        'updateCase' => Requests\UpdateCaseRequest::class,
        'caseNote' => Requests\CaseNoteRequest::class,
        'caseStatus' => Requests\CaseStatusRequest::class,
        'caseAssign' => Requests\CaseAssignRequest::class,
        'caseQc' => Requests\CaseQcRequest::class,
        'caseDelivery' => Requests\CaseDeliveryRequest::class,
        'clinic' => Requests\ClinicRequest::class,
        'doctor' => Requests\DoctorRequest::class,
        'patient' => Requests\PatientRequest::class,
        'technician' => Requests\TechnicianRequest::class,
        'recordPayment' => Requests\RecordPaymentRequest::class,
        'createInvoice' => Requests\CreateInvoiceRequest::class,
        'user' => Requests\UserRequest::class,
        'userStatus' => Requests\UserStatusRequest::class,
        'rolePermissions' => Requests\RolePermissionsRequest::class,
        'service' => Requests\ServiceRequest::class,
        'settings' => Requests\SettingsRequest::class,
    ];

    public function test_request_bodies_validate_like_the_web_app(): void
    {
        $fx = json_decode(file_get_contents(__DIR__.'/../Fixtures/shared-rules.json'), true);
        $this->assertCount(count(self::CLASSES), array_unique(array_column($fx['validation'], 'schema')));
        foreach ($fx['validation'] as $case) {
            $label = $case['schema'].' '.json_encode($case['body']);
            $result = self::CLASSES[$case['schema']]::check($case['body']);
            $this->assertSame($case['ok'], $result['ok'], $label.' → '.json_encode($result));
            if (! $case['ok']) {
                $expected = $case['errors'];
                $actual = $result['errors'];
                ksort($expected);
                ksort($actual);
                $this->assertSame(array_keys($expected), array_keys($actual), "fields of {$label}");
                foreach ($expected as $field => $messages) {
                    $this->assertSame($messages[0], $actual[$field][0], "first message of {$field} in {$label}");
                }

                continue;
            }
            foreach ($case['data'] as $key => $value) {
                $this->assertEquals($value, $result['data'][$key] ?? null, "{$key} of {$label}");
            }
            foreach ($result['data'] as $key => $value) {
                if (! array_key_exists($key, $case['data'])) {
                    $this->assertNull($value, "unexpected {$key} in {$label}");
                }
            }
        }
    }

    public function test_password_policy_reports_every_rule(): void
    {
        $fx = json_decode(file_get_contents(__DIR__.'/../Fixtures/shared-rules.json'), true);
        foreach ($fx['password'] as $p) {
            $r = Requests\ResetPasswordRequest::check(['token' => 't', 'email' => 'a@b.co', 'password' => $p['password'], 'passwordConfirmation' => $p['password']]);
            $this->assertSame($p['ok'] ? null : $p['errors']['_'], $r['errors']['password'] ?? null, json_encode($p));
        }
    }
}
