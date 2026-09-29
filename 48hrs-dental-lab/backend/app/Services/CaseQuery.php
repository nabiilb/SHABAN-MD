<?php

namespace App\Services;

use App\Domain\Dates;
use App\Domain\Workflow;
use App\Models\DentalCase;
use App\Models\User;
use App\Support\Query;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Case list filters and sorts, all in MySQL (indexes on status, priority, due_at,
 * clinic, doctor, technician, patient, created_at).
 */
final class CaseQuery
{
    public const SORTS = ['caseNumber', 'patient', 'doctor', 'clinic', 'caseType', 'priority', 'receivedAt', 'dueAt', 'status', 'technician', 'total', 'paymentStatus', 'createdAt'];

    public const SLA_FILTERS = ['on_track', 'at_risk', 'overdue', 'due_today'];

    public static function scoped(User $u): Builder
    {
        $q = DentalCase::query();
        Scope::cases($q, $u);

        return $q;
    }

    /** By id or case number, within the caller's scope. */
    public static function find(User $u, string $idOrNumber): Builder
    {
        return self::scoped($u)->where(fn ($q) => $q->where('cases.id', $idOrNumber)->orWhere('cases.case_number', $idOrNumber));
    }

    /** Day range on "received" (falls back to created for cases not yet received). */
    public static function receivedBetween(Builder $q, ?string $from, ?string $to): void
    {
        if (! $from && ! $to) {
            return;
        }
        $range = function ($q, string $col) use ($from, $to) {
            if ($from) {
                $q->where($col, '>=', Dates::dayStartUtc($from));
            }
            if ($to) {
                $q->where($col, '<', Dates::dayEndUtc($to));
            }
        };
        $q->where(fn ($q) => $q->where(fn ($q) => $range($q, 'cases.received_at'))->orWhere(fn ($q) => $q->whereNull('cases.received_at')->where(fn ($q) => $range($q, 'cases.created_at'))));
    }

    /** SQL form of the SLA list filters (same thresholds as Sla::info). */
    public static function sla(Builder $q, string $filter, array $sla, int $now): void
    {
        $at = Dates::fromMs($now);
        $riskEdge = Dates::fromMs($now + (int) ($sla['atRiskHours'] * Dates::HOUR_MS));
        $q->whereIn('cases.status', Workflow::IN_LAB_STATUSES);
        match ($filter) {
            'overdue' => $q->where('cases.due_at', '<=', $at),
            'at_risk' => $q->where('cases.due_at', '>', $at)->where('cases.due_at', '<=', $riskEdge),
            'on_track' => $q->where('cases.due_at', '>', $riskEdge),
            'due_today' => $q->where('cases.due_at', '>', $at)->where('cases.due_at', '<', Dates::dayEndUtc(Dates::dayIn($now))),
        };
    }

    /** Invoice-status filter on invoices.amount_paid / total / due_date. */
    public static function paymentStatus(Builder $q, string $status, int $now): void
    {
        $at = Dates::fromMs($now);
        match ($status) {
            'paid' => $q->whereHas('invoice', fn ($i) => $i->whereColumn('amount_paid', '>=', 'total')),
            'overdue' => $q->whereHas('invoice', fn ($i) => $i->whereColumn('amount_paid', '<', 'total')->where('due_date', '<', $at)),
            'partial' => $q->whereHas('invoice', fn ($i) => $i->where('amount_paid', '>', 0)->whereColumn('amount_paid', '<', 'total')->where('due_date', '>=', $at)),
            'unpaid' => $q->where(fn ($q) => $q->doesntHave('invoice')->orWhereHas('invoice', fn ($i) => $i->where('amount_paid', 0)->where('total', '>', 0)->where('due_date', '>=', $at))),
        };
    }

    public static function search(Builder $q, string $term): void
    {
        $like = Query::like($term);
        $q->where(fn ($q) => $q->where('cases.case_number', 'like', $like)
            ->orWhere('cases.restoration_type', 'like', $like)
            ->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->orWhereHas('doctor', fn ($d) => $d->where('name', 'like', $like))
            ->orWhereHas('clinic', fn ($k) => $k->where('name', 'like', $like))
            ->orWhereHas('technician', fn ($t) => $t->where('name', 'like', $like)));
    }

    public static function filter(Builder $q, array $f, array $sla, int $now): void
    {
        if ($f['status']) {
            $q->whereIn('cases.status', $f['status']);
        }
        if ($f['priority']) {
            $q->whereIn('cases.priority', $f['priority']);
        }
        foreach (['technicianId' => 'technician_id', 'doctorId' => 'doctor_id', 'clinicId' => 'clinic_id', 'patientId' => 'patient_id', 'caseType' => 'case_type'] as $key => $col) {
            if ($f[$key]) {
                $q->where("cases.{$col}", $f[$key]);
            }
        }
        if ($f['openOnly']) {
            $q->whereIn('cases.status', Workflow::openStatuses());
        }
        if ($f['dueFrom']) {
            $q->where('cases.due_at', '>=', Dates::dayStartUtc($f['dueFrom']));
        }
        if ($f['dueTo']) {
            $q->where('cases.due_at', '<', Dates::dayEndUtc($f['dueTo']));
        }
        self::receivedBetween($q, $f['from'], $f['to']);
        if ($f['sla']) {
            self::sla($q, $f['sla'], $sla, $now);
        }
        if ($f['paymentStatus']) {
            self::paymentStatus($q, $f['paymentStatus'], $now);
        }
        if ($f['search']) {
            self::search($q, $f['search']);
        }
    }

    /**
     * Sort order. Nullable columns keep PostgreSQL's placement (NULLS LAST ascending,
     * FIRST descending) unless the Node API asked for nulls last explicitly.
     */
    public static function sort(Builder $q, string $sort, string $dir): void
    {
        $q->select('cases.*');
        $pgNulls = fn (string $col) => $q->orderByRaw("{$col} IS NULL ".($dir === 'asc' ? 'ASC' : 'DESC'))->orderBy($col, $dir);
        $nullsLast = fn (string $col) => $q->orderByRaw("{$col} IS NULL ASC")->orderBy($col, $dir);
        $join = function (string $table, string $alias, string $fk) use ($q) {
            $q->leftJoin("{$table} as {$alias}", "{$alias}.id", '=', "cases.{$fk}");
        };
        switch ($sort) {
            case 'caseNumber':
                $q->orderBy('cases.case_number', $dir);

                return;
            case 'patient':
                $join('patients', 'sp', 'patient_id');
                $q->orderBy('sp.name', $dir);
                break;
            case 'doctor':
                $join('doctors', 'sd', 'doctor_id');
                $q->orderBy('sd.name', $dir);
                break;
            case 'clinic':
                $join('clinics', 'sk', 'clinic_id');
                $q->orderBy('sk.name', $dir);
                break;
            case 'caseType':
                $q->orderBy('cases.restoration_type', $dir);
                break;
            case 'priority':
                $q->orderBy('cases.priority', $dir);
                break;
            case 'receivedAt':
                $nullsLast('cases.received_at');
                $q->orderBy('cases.created_at', $dir);

                return;
            case 'dueAt':
                $nullsLast('cases.due_at');
                break;
            case 'status':
                $q->orderBy('cases.status', $dir);
                break;
            case 'technician':
                $join('technicians', 'st', 'technician_id');
                $pgNulls('st.name');
                break;
            case 'total':
                $q->orderBy('cases.total', $dir);
                break;
            case 'paymentStatus':
                $q->leftJoin('invoices as si', 'si.case_id', '=', 'cases.id');
                $pgNulls('si.amount_paid');
                break;
            case 'createdAt':
                $q->orderBy('cases.created_at', $dir);

                return;
        }
        $q->orderBy('cases.case_number', $dir);
    }
}
