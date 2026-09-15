@props(['status'])

@php
    $tone = match ($status) {
        'active', 'present', 'completed', 'paid', 'available', 'excellent', 'good', 'approved' => 'badge-green',
        'partially_paid', 'in_training', 'average', 'scheduled' => 'badge-blue',
        'suspended', 'excused', 'maintenance', 'outstanding', 'needs_improvement', 'pending' => 'badge-amber',
        'cancelled', 'absent', 'overdue', 'poor', 'rejected' => 'badge-rose',
        default => 'badge-slate',
    };
@endphp

<span class="{{ $tone }}">{{ __(ucwords(str_replace('_', ' ', $status))) }}</span>
