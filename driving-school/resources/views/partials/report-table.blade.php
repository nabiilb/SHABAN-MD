<div class="card">
    <div class="card-header">
        <div>
            <h3 class="text-base font-bold text-slate-900">{{ $title }}</h3>
            <p class="text-xs text-slate-400">{{ __('Generated :date', ['date' => now()->format('d/m/Y H:i')]) }}</p>
        </div>
        <div class="flex flex-wrap gap-4 text-sm">
            @foreach ($result['summary'] as $label => $value)
                <div class="text-right">
                    <p class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</p>
                    <p class="font-bold text-slate-800">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>@foreach ($result['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @forelse ($result['rows'] as $row)
                    <tr>
                        @foreach ((array) $row as $cell)
                            <td class="text-sm">{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <x-empty-state :colspan="max(count($result['columns']), 1)" :message="__('No data matches these filters.')" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
