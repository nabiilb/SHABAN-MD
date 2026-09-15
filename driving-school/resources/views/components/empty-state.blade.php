@props(['message' => null, 'colspan' => 1])

<tr>
    <td colspan="{{ $colspan }}" class="px-5 py-10 text-center text-sm text-slate-400">
        {{ $message ?? __('No records found.') }}
    </td>
</tr>
