<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', Rule::in(array_keys(config('app.supported_locales')))],
        ]);

        $user->update($data);
        $request->session()->put('locale', $user->locale);

        AuditLogger::log('user.profile_updated', $user, "{$user->name} updated their profile");

        return back()->with('status', __('Profile updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        AuditLogger::log('user.password_changed', $user, "{$user->name} changed their password");

        return back()->with('status', __('Password updated.'));
    }

    public function switchLocale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('app.supported_locales')), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back();
    }
}
