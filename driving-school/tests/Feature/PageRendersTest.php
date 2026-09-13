<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\CompanyDebt;
use App\Models\CompanyExpense;
use App\Models\ExpenseCategory;
use App\Models\FuelRecord;
use App\Models\Instructor;
use App\Models\InstructorLoan;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\StudentPayment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DrivingSchoolSeeder;
use Database\Seeders\FinanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Walks every GET route in the application against the seeded database, so a
 * broken Blade template or a missing view variable fails the build.
 */
class PageRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->seed(DrivingSchoolSeeder::class);
        $this->seed(FinanceSeeder::class);
    }

    /** Resolves a concrete id for each route parameter name. */
    private function parameterValues(string $prefix = 'admin.'): array
    {
        return [
            'student' => Student::first()->id,
            'instructor' => Instructor::first()->id,
            'vehicle' => Vehicle::first()->id,
            'attendance' => Attendance::first()->id,
            'lesson' => Lesson::first()->id,
            'supplier' => Supplier::first()->id,
            'debt' => CompanyDebt::first()->id,
            'expense' => CompanyExpense::whereNull('debt_payment_id')->first()->id,
            'category' => ExpenseCategory::first()->id,
            'loan' => InstructorLoan::first()->id,
            'payment' => StudentPayment::first()->id,
            'user' => User::first()->id,
            'auditLog' => AuditLog::first()->id,
            'fuel' => FuelRecord::first()?->id,
            // Instructors may only run the four "my" reports.
            'report' => $prefix === 'instructor.' ? 'my-students' : 'students',
            'locale' => 'so',
        ];
    }

    private function routesFor(string $prefix): array
    {
        $params = $this->parameterValues($prefix);
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = $route->getName();

            if (! $name || ! str_starts_with($name, $prefix)) {
                continue;
            }

            $bindings = [];
            $skip = false;

            foreach ($route->parameterNames() as $parameter) {
                if (! array_key_exists($parameter, $params) || $params[$parameter] === null) {
                    $skip = true;
                    break;
                }

                $bindings[$parameter] = $params[$parameter];
            }

            if ($skip) {
                continue;
            }

            $urls[$name] = route($name, $bindings);
        }

        return $urls;
    }

    private function assertAllRender(User $user, string $prefix): void
    {
        $urls = $this->routesFor($prefix);

        $this->assertNotEmpty($urls, "No routes found for prefix {$prefix}");

        foreach ($urls as $name => $url) {
            $response = $this->actingAs($user)->get($url);

            $this->assertContains(
                $response->status(),
                [200, 302],
                "Route [{$name}] at {$url} returned {$response->status()}.",
            );
        }
    }

    public function test_every_admin_page_renders(): void
    {
        $this->assertAllRender(User::where('email', 'admin@example.com')->firstOrFail(), 'admin.');
    }

    public function test_every_instructor_page_renders(): void
    {
        // Xasan owns students, a vehicle and a loan, so no page is empty.
        $this->assertAllRender(User::where('email', 'xasan@example.com')->firstOrFail(), 'instructor.');
    }

    public function test_every_student_page_renders(): void
    {
        $this->assertAllRender(User::where('email', 'student@example.com')->firstOrFail(), 'student.');
    }

    public function test_the_login_page_renders_in_both_languages(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');

        $this->withSession(['locale' => 'so'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Gal');
    }

    public function test_the_interface_switches_to_somali(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)->get(route('locale.switch', 'so'));

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Wadarta Dakhliga')   // Total Income
            ->assertSee('Ardayda');           // Students
    }

    public function test_the_instructor_page_renders_in_somali(): void
    {
        $xasan = User::where('email', 'xasan@example.com')->firstOrFail();

        $this->actingAs($xasan)->get(route('locale.switch', 'so'));

        $this->actingAs($xasan)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Shaxdayda')       // My Dashboard
            ->assertSee('Ardaydayda');     // My Students
    }
}
