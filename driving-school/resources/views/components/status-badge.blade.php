@props(['status'])

@php
    $tone = match ($status) {
        'active', 'present', 'completed', 'paid', 'available', 'excellent', 'good' => 'badge-green',
        'partially_paid', 'in_training', 'average', 'scheduled' => 'badge-blue',
        'suspended', 'excused', 'maintenance', 'outstanding', 'needs_improvement' => 'badge-amber',
        'cancelled', 'absent', 'overdue', 'poor' => 'badge-rose',
        default => 'badge-slate',
    };
@endphp

<span class="{{ $tone }}">{{ __(ucwords(str_replace('_', ' ', $status))) }}</span>
