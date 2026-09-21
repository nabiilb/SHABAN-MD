@props(['name', 'label' => null, 'required' => false])

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $name }}" class="label">
            {{ $label }}
            @if ($required)<span class="text-rose-500">*</span>@endif
        </label>
    @endif
    {{ $slot }}
    @error($name)
        <p class="field-error">{{ $message }}</p>
    @enderror
</div>
