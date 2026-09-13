<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __invoke(Request $request): View
    {
        $student = $request->user()->student;

        return view('student.profile', [
            'student' => $student->load('currentInstructor'),
            'assignments' => $student->assignments()->with('instructor')->orderByDesc('assigned_from')->get(),
            'payments' => $student->payments()->latest('payment_date')->get(),
        ]);
    }
}
