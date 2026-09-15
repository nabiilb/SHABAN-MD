<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $q->where(fn ($sub) => $sub->where('description', 'like', $term)->orWhere('user_name', 'like', $term));
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.audit-logs.index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $this->authorize('view', $auditLog);

        return view('admin.audit-logs.show', ['log' => $auditLog->load('user')]);
    }
}
