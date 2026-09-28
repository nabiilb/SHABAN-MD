@props(['action', 'label' => null, 'confirm' => null])

<form method="POST" action="{{ $action }}" class="inline"
      onsubmit="return confirm('{{ $confirm ?? __('Are you sure? This cannot be undone.') }}')">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50">
        {{ $label ?? __('Delete') }}
    </button>
</form>
