<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('manage-settings');

        return view('admin.settings.edit', [
            'settings' => Setting::orderBy('group')->get()->groupBy('group'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-settings');

        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:120'],
            'school_phone' => ['nullable', 'string', 'max:30'],
            'school_email' => ['nullable', 'email', 'max:150'],
            'school_address' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'default_training_days' => ['required', 'integer', 'min:1', 'max:365'],
            'near_completion_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'default_locale' => ['required', Rule::in(array_keys(config('app.supported_locales')))],
            'allow_duplicate_attendance' => ['nullable', 'boolean'],
        ]);

        $data['allow_duplicate_attendance'] = $request->boolean('allow_duplicate_attendance') ? '1' : '0';

        foreach ($data as $key => $value) {
            Setting::put($key, $value ?? '');
        }

        AuditLogger::log('settings.updated', null, 'System settings updated', null, $data);

        return back()->with('status', __('Settings saved.'));
    }
}
