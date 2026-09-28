<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstructorRequest;
use App\Models\Instructor;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DocumentNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class InstructorController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Instructor::class);

        $instructors = Instructor::query()
            ->withCount(['students', 'vehicles'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q
                    ->where('full_name', 'like', $term)
                    ->orWhere('instructor_number', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.instructors.index', ['instructors' => $instructors]);
    }

    public function create(): View
    {
        $this->authorize('create', Instructor::class);

        return view('admin.instructors.form', [
            'instructor' => new Instructor(['status' => 'active', 'joining_date' => now()]),
        ]);
    }

    public function store(InstructorRequest $request): RedirectResponse
    {
        $this->authorize('create', Instructor::class);

        $instructor = DB::transaction(function () use ($request) {
            $data = $request->safe()->except(['create_account', 'account_email', 'account_password']);
            $data['instructor_number'] = DocumentNumber::next(Instructor::class, 'instructor_number', 'INS');

            $instructor = Instructor::create($data);

            if ($request->boolean('create_account')) {
                $user = User::create([
                    'role_id' => Role::where('name', Role::INSTRUCTOR)->value('id'),
                    'name' => $instructor->full_name,
                    'email' => $request->input('account_email'),
                    'phone' => $instructor->phone,
                    'password' => Hash::make($request->input('account_password')),
                    'is_active' => true,
                ]);

                $instructor->forceFill(['user_id' => $user->id])->save();
            }

            AuditLogger::created($instructor, "Instructor {$instructor->full_name} added");

            return $instructor;
        });

        return redirect()
            ->route('admin.instructors.show', $instructor)
            ->with('status', __('Instructor added.'));
    }

    public function show(Instructor $instructor): View
    {
        $this->authorize('view', $instructor);

        return view('admin.instructors.show', [
            'instructor' => $instructor->load(['user', 'vehicles']),
            'students' => $instructor->students()->withProgress()->orderBy('full_name')->get(),
            'loans' => $instructor->loans()->with('payments')->latest('loan_date')->get(),
            'recentAttendance' => $instructor->attendance()->with('student')->latest('attendance_date')->limit(10)->get(),
            'recentLessons' => $instructor->lessons()->with(['student', 'lessonTopic'])->latest('lesson_date')->limit(10)->get(),
        ]);
    }

    public function edit(Instructor $instructor): View
    {
        $this->authorize('update', $instructor);

        return view('admin.instructors.form', ['instructor' => $instructor->load('user')]);
    }

    public function update(InstructorRequest $request, Instructor $instructor): RedirectResponse
    {
        $this->authorize('update', $instructor);

        DB::transaction(function () use ($request, $instructor) {
            $original = $instructor->getOriginal();
            $instructor->update($request->safe()->except(['create_account', 'account_email', 'account_password']));

            if ($instructor->user) {
                $instructor->user->update(array_filter([
                    'name' => $instructor->full_name,
                    'email' => $request->input('account_email'),
                    'password' => $request->filled('account_password')
                        ? Hash::make($request->input('account_password'))
                        : null,
                ]));
            } elseif ($request->boolean('create_account')) {
                $user = User::create([
                    'role_id' => Role::where('name', Role::INSTRUCTOR)->value('id'),
                    'name' => $instructor->full_name,
                    'email' => $request->input('account_email'),
                    'password' => Hash::make($request->input('account_password')),
                    'is_active' => true,
                ]);
                $instructor->forceFill(['user_id' => $user->id])->save();
            }

            AuditLogger::updated($instructor, "Instructor {$instructor->full_name} updated", $original);
        });

        return redirect()->route('admin.instructors.show', $instructor)->with('status', __('Instructor updated.'));
    }

    public function destroy(Instructor $instructor): RedirectResponse
    {
        $this->authorize('delete', $instructor);

        if ($instructor->students()->exists()) {
            return back()->withErrors([
                'instructor' => __('Transfer this instructor\'s students before removing them.'),
            ]);
        }

        $name = $instructor->full_name;
        $instructor->update(['status' => 'inactive']);
        $instructor->delete();
        $instructor->user?->update(['is_active' => false]);

        AuditLogger::log('instructor.deleted', $instructor, "Instructor {$name} deactivated");

        return redirect()->route('admin.instructors.index')->with('status', __('Instructor removed.'));
    }
}
