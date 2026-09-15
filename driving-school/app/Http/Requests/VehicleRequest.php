<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle
            ? $this->user()->can('update', $vehicle)
            : $this->user()->can('create', Vehicle::class);
    }

    public function rules(): array
    {
        $id = $this->route('vehicle')?->id;

        return [
            'plate_number' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'plate_number')->ignore($id)->whereNull('deleted_at')],
            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:60'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'status' => ['required', Rule::in(Vehicle::STATUSES)],
            'instructor_id' => ['nullable', 'integer', Rule::exists('instructors', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
