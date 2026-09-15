<?php

namespace App\Http\Requests;

use App\Models\FuelRecord;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An instructor recording a fill-up.
 *
 * Narrower than the admin's FuelRecordRequest in the one way that matters: the
 * vehicle must be one of theirs, checked against Vehicle::visibleTo() rather
 * than against the whole fleet, so a foreign id posted by hand fails validation
 * before anything reaches the service. The instructor is taken from the
 * authenticated user and is not accepted from the request at all.
 *
 * Everything else reuses the admin rules unchanged — same columns, same bounds,
 * same precision, same "no future date".
 */
class InstructorFuelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FuelRecord::class);
    }

    public function rules(): array
    {
        $admin = (new FuelRecordRequest)->rules();

        return [
            'vehicle_id' => [
                'required', 'integer',
                Rule::in($this->authorizedVehicleIds()),
            ],
            'supplier_id' => $admin['supplier_id'],
            'liters' => $admin['liters'],
            'price_per_liter' => $admin['price_per_liter'],
            'odometer' => $admin['odometer'],
            'fuel_date' => $admin['fuel_date'],
            'is_credit' => $admin['is_credit'],
            'payment_method' => $admin['payment_method'],
            'notes' => $admin['notes'],
            'submission_token' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_id.in' => __('You can only record fuel for a vehicle assigned to you.'),
        ];
    }

    /** @return array<int, int> */
    public function authorizedVehicleIds(): array
    {
        return Vehicle::query()->visibleTo($this->user())->pluck('id')->all();
    }

    /**
     * What the service is given. instructor_id comes from the signed-in user,
     * never from the browser.
     */
    public function fuelData(): array
    {
        return [
            ...$this->safe()->except('submission_token'),
            'instructor_id' => $this->user()->instructorId(),
            'submission_token' => $this->string('submission_token')->toString(),
        ];
    }
}
