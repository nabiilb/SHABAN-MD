<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1e293b; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #64748b; font-size: 9px; margin-bottom: 12px; }
        .summary { margin-bottom: 12px; }
        .summary span { display: inline-block; margin-right: 18px; }
        .summary strong { color: #0f172a; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; padding: 6px; font-size: 9px;
             text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #cbd5e1; }
        td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">
        {{ config('app.name') }} — {{ __('Generated :date', ['date' => now()->format('d/m/Y H:i')]) }}
        @if (! empty($filters))
            · {{ collect($filters)->map(fn ($v, $k) => "$k: $v")->implode(' · ') }}
        @endif
    </p>

    <p class="summary">
        @foreach ($result['summary'] as $label => $value)
            <span>{{ $label }}: <strong>{{ $value }}</strong></span>
        @endforeach
    </p>

    <table>
        <thead><tr>@foreach ($result['columns'] as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($result['rows'] as $row)
                <tr>@foreach ((array) $row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ max(count($result['columns']), 1) }}">{{ __('No data matches these filters.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
