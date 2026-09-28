<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Models\StudentPayment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student
            ? $this->user()->can('update', $student)
            : $this->user()->can('create', Student::class);
    }

    /** The payment fields, which only a registration accepts. */
    private const PAYMENT_FIELDS = ['amount_paid', 'payment_method', 'payment_reference'];

    public function rules(): array
    {
        $id = $this->route('student')?->id;

        return [
            'full_name' => ['required', 'string', 'max:150'],
            // The school identifies a student by their phone number — the
            // register is kept by it and the import matches on it — so two
            // active students may not share one, however each was written.
            // Backed by a unique index; this rule is only the polite refusal.
            'phone' => [
                'required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/',
                function (string $attribute, mixed $value, Closure $fail) use ($id) {
                    if ($existing = Student::activeWithPhone($value, $id)) {
                        $fail(__('An active student with this phone number is already registered: :name (:number).', [
                            'name' => $existing->full_name,
                            'number' => $existing->student_number,
                        ]));
                    }
                },
            ],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('students', 'email')->ignore($id)->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'license_type' => ['nullable', 'string', 'max:60'],
            'start_date' => ['required', 'date'],
            'required_training_days' => ['required', 'integer', 'min:1', 'max:365'],
            'current_instructor_id' => ['nullable', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'status' => ['required', Rule::in(Student::STATUSES)],

            // Only on an edit: a student being registered has not trained yet,
            // so the whole course is what is left and there is nothing to put
            // right. Checked against the Required Training Days submitted on
            // this form, not the one on file, because an admin may be changing
            // both at once and the new course length is what the balance has
            // to fit inside.
            ...$this->isRegistration() ? [] : [
                'remaining_training_days' => [
                    // 'sometimes' so that only the form which offers the field
                    // has to carry it: the edit screen always sends it, and a
                    // caller that never showed it is not made to invent one.
                    // Sent empty it still fails, which is what a cleared box
                    // should do.
                    'sometimes', 'required', 'integer', 'min:0',
                    'max:'.max((int) $this->input('required_training_days'), 0),
                ],
            ],
            'total_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],

            // Money taken at the counter when the student registers. Only on
            // the way in: editing a student never records a payment, so these
            // are not accepted at all on an update and an Amount Paid posted
            // there is ignored rather than silently banked twice.
            ...$this->isRegistration() ? [
                'amount_paid' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
                'payment_method' => ['nullable', Rule::in(StudentPayment::METHODS)],
                'payment_reference' => ['nullable', 'string', 'max:100'],
            ] : [],
        ];
    }

    /** True when this request registers a new student rather than editing one. */
    public function isRegistration(): bool
    {
        return $this->route('student') === null;
    }

    /** The student's own columns, with the payment fields taken out. */
    public function studentData(): array
    {
        // Remaining days is not a column and never was: it is calculated, and
        // correcting it means moving the opening balance it is calculated
        // from. The controller hands it to StudentProgressService instead.
        return array_diff_key(
            $this->validated(),
            array_flip([...self::PAYMENT_FIELDS, 'remaining_training_days']),
        );
    }

    /** The figure the admin typed into Remaining Training Days, if any. */
    public function correctedRemainingDays(): ?int
    {
        return $this->isRegistration() || ! $this->has('remaining_training_days')
            ? null
            : (int) $this->validated('remaining_training_days');
    }

    /**
     * What the counter took, in the shape StudentPaymentService wants — or null
     * when this is an edit, or when Amount Paid was blank or zero.
     */
    public function registrationPayment(): ?array
    {
        if (! $this->isRegistration()) {
            return null;
        }

        $amount = round((float) $this->input('amount_paid', 0), 2);

        if ($amount <= 0) {
            return null;
        }

        return [
            'amount' => $amount,
            'payment_date' => $this->input('start_date') ?: today()->toDateString(),
            'payment_method' => $this->input('payment_method') ?: 'cash',
            'reference' => $this->input('payment_reference') ?: null,
        ];
    }

    public function attributes(): array
    {
        return [
            'current_instructor_id' => __('instructor'),
            'required_training_days' => __('required training days'),
            'amount_paid' => __('amount paid'),
        ];
    }
}
