<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The parts of the responsive work a test can actually hold.
 *
 * A test cannot see a layout, so this does not pretend to. What it can do is
 * pin the contract the CSS depends on — the markup hooks, the shared classes,
 * the drawer's behaviour attributes — so that a later edit cannot quietly drop
 * the thing that makes a page readable on a phone and leave the suite green.
 *
 * The viewports themselves (320 to 1366) were checked by hand; this is what
 * stops them regressing.
 */
class ResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacherUser;

    private Instructor $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        Carbon::setTestNow(Carbon::parse('2026-09-19 09:00:00'));

        $this->admin = $this->makeUser(Role::ADMIN, ['name' => 'Cabdiraxmaan Cali']);
        $this->teacherUser = $this->makeUser(Role::INSTRUCTOR, ['name' => 'Abdullahi']);
        $this->instructor = $this->makeInstructor('Abdullahi', $this->teacherUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function student(string $name, string $startDate = '2026-09-10'): Student
    {
        return $this->makeStudent($name, $this->instructor, ['start_date' => $startDate]);
    }

    /* ================================================================
     | The layout: drawer, header, page
     | ================================================================ */

    /** The drawer opens, closes four ways, and locks the page behind it. */
    public function test_the_navigation_drawer_is_usable_on_a_phone(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // Opened by the hamburger, which is hidden once there is room for the
        // sidebar proper.
        $this->assertStringContainsString('@click="open()"', $html);
        $this->assertStringContainsString('lg:hidden', $html);

        // Closed by the backdrop, by Escape, by the close button, and by
        // tapping any link inside it.
        $this->assertStringContainsString('@click="close()"', $html);
        $this->assertStringContainsString('@keydown.escape.window="close()"', $html);
        $this->assertStringContainsString("if (\$event.target.closest('a')) close()", $html);
        $this->assertStringContainsString(__('Close menu'), $html);

        // The page behind it does not scroll.
        $this->assertStringContainsString("document.body.classList.add('drawer-open')", $html);
        $this->assertStringContainsString("document.body.classList.remove('drawer-open')", $html);

        // One sidebar, not a second copy of the navigation for mobile.
        $this->assertSame(1, substr_count($html, 'id="sidebar-nav"') + substr_count($html, '<aside'));
    }

    /** The viewport is declared, and nothing may exceed it. */
    public function test_the_page_declares_a_mobile_viewport(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString('width=device-width, initial-scale=1', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
    }

    /** A long user name is capped rather than pushing the header off-screen. */
    public function test_the_header_cannot_be_widened_by_a_long_name(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();

        $this->assertStringContainsString('max-w-32 truncate', $html, 'The user name is capped.');
        $this->assertStringContainsString('lg:block', $html, 'And hidden altogether on a phone.');
    }

    /* ================================================================
     | The shared CSS contract
     | ================================================================ */

    /** The stylesheet carries the patterns every page leans on. */
    public function test_the_stylesheet_defines_the_shared_responsive_patterns(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach ([
            '[x-cloak]',        // or every dialog flashes on load
            '.drawer-open',     // scroll lock
            'overflow-x: hidden', // no page wider than the phone
            '.table-cards',     // table on desktop, cards on a phone
            '.filter-bar',
            '.filter-actions',
            '.modal-panel',
            '.modal-body',
            'max-sm:min-h-11',  // thumb-sized buttons
        ] as $pattern) {
            $this->assertStringContainsString($pattern, $css, "app.css should define {$pattern}.");
        }
    }

    /* ================================================================
     | Tables that become cards
     | ================================================================ */

    /**
     * Every column a desktop shows is on the phone too, labelled — nothing is
     * dropped to make the row fit.
     */
    public function test_the_no_attendance_table_becomes_labelled_cards(): void
    {
        $student = $this->student('Ahmed Ali');
        Attendance::create([
            'student_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'attendance_date' => '2026-09-11',
            'status' => 'present',
            'recorded_by' => $this->admin->id,
        ]);

        $page = $this->actingAs($this->admin)->get(route('admin.students.no-attendance'))->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString('table-cards', $html);

        foreach ([__('Phone'), __('Instructor'), __('Start Date'), __('Last Attendance')] as $column) {
            $this->assertStringContainsString('data-label="'.$column.'"', $html, "{$column} must survive on a phone.");
        }

        // The actions are buttons, not bare links, so they are tappable.
        $this->assertStringContainsString(__('Show Missing Dates'), $html);
        $this->assertStringContainsString(__('View Student'), $html);
        $this->assertStringContainsString('cell-actions', $html);
        $this->assertStringContainsString('cell-title', $html);
    }

    public function test_the_students_and_unpaid_tables_become_cards(): void
    {
        $student = $this->student('Ahmed Ali');
        $student->update(['total_fee' => 100]);

        foreach ([route('admin.students.index'), route('admin.students.unpaid')] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('table-cards', $html, "{$url} should stack on a phone.");
            $this->assertStringContainsString('data-label="'.__('Phone').'"', $html);
            $this->assertStringContainsString('cell-actions', $html);
        }
    }

    /** Filters stack and fill the width on a phone. */
    public function test_the_filters_stack_on_a_phone(): void
    {
        foreach ([
            route('admin.students.no-attendance'),
            route('admin.students.index'),
            route('admin.students.unpaid'),
        ] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('filter-bar', $html, "{$url} should use the shared filter grid.");
            $this->assertStringContainsString('filter-actions', $html);
        }
    }

    /* ================================================================
     | The Training Console
     | ================================================================ */

    public function test_the_training_console_dialog_fits_a_phone(): void
    {
        $html = $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->assertOk()->getContent();

        // The dialog is capped to the viewport and scrolls inside itself, so
        // its buttons stay reachable.
        $this->assertStringContainsString('modal-panel', $html);
        $this->assertStringContainsString('modal-body', $html);

        // The two figures keep their own labels — this is the bug that made a
        // row read "Remaining: 300%".
        $this->assertStringContainsString('student.remaining_label', $html);
        $this->assertStringContainsString('Progress: ${student.progress}%', $html);
    }

    /** Queue rows stack, and their buttons fill the width on a phone. */
    public function test_the_queue_rows_stack_on_a_phone(): void
    {
        $html = $this->actingAs($this->teacherUser)->get(route('instructor.training.index'))->getContent();

        $this->assertStringContainsString('flex flex-col gap-3 px-4 py-3 sm:flex-row', $html);
        $this->assertStringContainsString('max-sm:[&>*]:flex-1', $html);
    }

    /* ================================================================
     | Every page still renders
     | ================================================================ */

    /** The responsive work changed no page's content, only its shape. */
    public function test_every_admin_page_still_renders(): void
    {
        $student = $this->student('Ahmed Ali');
        $student->update(['total_fee' => 100]);

        foreach ([
            route('admin.dashboard'),
            route('admin.students.index'),
            route('admin.students.show', $student),
            route('admin.students.create'),
            route('admin.students.edit', $student),
            route('admin.students.unpaid'),
            route('admin.students.no-attendance'),
            route('admin.attendance.index'),
            route('admin.training.index'),
            route('admin.expenses.index'),
            route('admin.student-payments.index'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        foreach ([
            route('instructor.dashboard'),
            route('instructor.training.index'),
            route('instructor.students.index'),
        ] as $url) {
            $this->actingAs($this->teacherUser)->get($url)->assertOk();
        }
    }

    /** The dashboard's cards are links with a real touch target. */
    public function test_the_clickable_dashboard_cards_remain_clickable(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        foreach ([
            route('admin.students.no-attendance'),
            route('admin.students.unpaid'),
            route('admin.students.index', ['status' => 'completed']),
            route('admin.students.index', ['status' => 'active']),
        ] as $target) {
            $this->assertStringContainsString($target, $html, "The card linking to {$target} must stay a link.");
        }

        $this->assertStringContainsString('min-h-20', $html, 'And be big enough to tap.');
    }
}
