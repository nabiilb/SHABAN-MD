<?php

namespace App\Services;

use App\Domain\Analytics;
use App\Domain\Dates;
use App\Domain\Permissions;
use App\Domain\Workflow;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\DentalCase;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\QualityCheck;
use App\Models\Technician;
use App\Models\User;
use App\Support\LabSettings;
use App\Support\Present;
use App\Support\Query;
use App\Support\Scope;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard, reports and global search. Rows are selected in MySQL within the
 * caller's scope; the aggregation is the shared analytics module (App\Domain\Analytics),
 * so the numbers match the web app exactly.
 */
class AnalyticsService
{
    private const SLA_COLUMNS = ['status', 'received_at', 'due_at', 'delivered_at', 'submitted_at', 'created_at'];

    public function dashboard(User $u, string $period): array
    {
        $now = Dates::nowMs();
        $tz = config('lab.timezone');
        $today = Dates::dayIn($now, $tz);
        $scoped = fn () => $u->hasPermission(Permissions::CASES_VIEW) ? CaseQuery::scoped($u) : DentalCase::whereRaw('1 = 0');
        $since = Dates::dayStartUtc(Analytics::dashboardWindowStart($period, $today));
        $finance = $u->hasPermission(Permissions::REPORTS_FINANCIAL);
        $periodStart = Analytics::periodStartDay($period, $today);
        $revenueStart = Analytics::revenueWindowMonth($today).'-01';
        $moneySince = Dates::dayStartUtc($periodStart < $revenueStart ? $periodStart : $revenueStart);

        $statusCounts = array_map('intval', $scoped()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all());
        $inLab = $scoped()->whereIn('status', Workflow::IN_LAB_STATUSES)->get(self::SLA_COLUMNS)->map(fn ($c) => Present::slaFields($c))->all();
        $recent = $scoped()->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('submitted_at', '>=', $since)->orWhere('received_at', '>=', $since)->orWhere('delivered_at', '>=', $since))
            ->get(self::SLA_COLUMNS)->map(fn ($c) => Present::slaFields($c))->all();

        return Analytics::buildDashboard([
            'now' => $now,
            'period' => $period,
            'statusCounts' => $statusCounts,
            'inLab' => $inLab,
            'recent' => $recent,
            'finance' => $finance ? [
                'invoices' => Invoice::where('issued_at', '>=', $moneySince)->get(['issued_at', 'total'])->map(fn ($i) => ['issuedAt' => Present::iso($i->issued_at), 'total' => Present::money($i->total)])->all(),
                'payments' => DB::table('payments')->where('paid_at', '>=', $moneySince)->get(['paid_at', 'amount'])->map(fn ($p) => ['paidAt' => Dates::iso(new \DateTimeImmutable($p->paid_at, new \DateTimeZone('UTC'))), 'amount' => Present::money($p->amount)])->all(),
                'outstanding' => Present::money(DB::table('invoices')->selectRaw('SUM(GREATEST(total - amount_paid, 0)) AS o')->value('o')),
            ] : null,
        ], LabSettings::sla(), $tz);
    }

    /** Visible, non-rejected cases in the report's range and filters, in the shared case shape. */
    private function reportInput(User $u, array $f): array
    {
        if ($error = Analytics::validateReportRange($f['from'], $f['to'])) {
            throw ApiException::validation(['from' => [$error]], $error);
        }
        $q = CaseQuery::scoped($u)->where('status', '!=', 'rejected');
        CaseQuery::receivedBetween($q, $f['from'], $f['to']);
        foreach (['technicianId' => 'technician_id', 'doctorId' => 'doctor_id', 'clinicId' => 'clinic_id', 'status' => 'status', 'caseType' => 'case_type'] as $key => $col) {
            if (! empty($f[$key])) {
                $q->where("cases.{$col}", $f[$key]);
            }
        }
        $now = Dates::nowMs();
        $rows = $q->with(['invoice', 'notes.author:id,name'])->get();
        $invoices = [];
        foreach ($rows as $r) {
            if ($r->invoice) {
                $invoices[$r->id] = Present::invoiceFigures($r->invoice, $now);
            }
        }

        return ['cases' => $rows->map(fn ($c) => Present::labCase($c, $now))->all(), 'invoices' => $invoices, 'now' => $now, 'filters' => $f];
    }

    public function caseReport(User $u, array $f): array
    {
        $i = $this->reportInput($u, $f);

        return Analytics::caseReport($f, $i['cases'], $i['now'], LabSettings::sla(), config('lab.timezone'));
    }

    public function productionReport(User $u, array $f): array
    {
        return Analytics::productionReport($this->reportInput($u, $f)['cases']);
    }

    public function technicianReport(User $u, array $f): array
    {
        $i = $this->reportInput($u, $f);
        $ids = array_column($i['cases'], 'id');
        $failures = $ids ? QualityCheck::where('result', 'failed')->whereIn('case_id', $ids)->groupBy('case_id')->selectRaw('case_id, COUNT(*) AS n')->pluck('n', 'case_id')->map(fn ($n) => (int) $n)->all() : [];
        $technicians = Technician::orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all();

        return Analytics::technicianReport($i['cases'], $technicians, $failures);
    }

    public function clinicReport(User $u, array $f): array
    {
        $clinics = Clinic::orderBy('name')->get(['id', 'name'])->map(fn ($k) => ['id' => $k->id, 'name' => $k->name])->all();

        return Analytics::clinicReport($this->reportInput($u, $f)['cases'], $clinics);
    }

    public function financialReport(User $u, array $f): array
    {
        $i = $this->reportInput($u, $f);

        return Analytics::financialReport($f, $i['cases'], $i['invoices'], config('lab.timezone'));
    }

    /** Cases, patients, doctors, clinics and invoices the caller may open; phone numbers match on digits. */
    public function search(User $u, string $raw): array
    {
        $term = trim($raw);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $digits = preg_replace('/\D/', '', $term);
        $byDigits = strlen($digits) >= 4;
        $like = Query::like($term);
        $clinicScope = Scope::clinic($u);
        $out = [];
        $phoneIds = function (string $table) use ($byDigits, $digits) {
            if (! $byDigits) {
                return [];
            }

            return DB::table($table)->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%'.$digits.'%'])->limit(25)->pluck('id')->all();
        };
        $withinClinic = function ($q, string $column) use ($clinicScope) {
            if ($clinicScope !== null) {
                $clinicScope === '' ? $q->whereRaw('1 = 0') : $q->where($column, $clinicScope);
            }
        };

        if ($u->hasPermission(Permissions::CASES_VIEW)) {
            $patientIds = $phoneIds('patients');
            CaseQuery::scoped($u)->where(fn ($q) => $q->where('case_number', 'like', $like)->orWhereHas('patient', fn ($p) => $p->where('name', 'like', $like)->orWhere('code', 'like', $like))->orWhereIn('patient_id', $patientIds))
                ->with(['patient:id,name', 'clinic:id,name'])->orderByDesc('created_at')->limit(6)->get()
                ->each(function ($c) use (&$out) {
                    $out[] = ['type' => 'case', 'id' => $c->id, 'title' => $c->case_number, 'subtitle' => "{$c->patient->name} · {$c->restoration_type} · {$c->clinic->name}", 'href' => "/cases/{$c->id}"];
                });
        }
        if ($u->hasPermission(Permissions::PATIENTS_VIEW)) {
            $ids = $phoneIds('patients');
            $q = Patient::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('email', 'like', $like)->orWhereIn('id', $ids));
            $withinClinic($q, 'clinic_id');
            $q->orderBy('name')->limit(5)->get()->each(function ($p) use (&$out) {
                $out[] = ['type' => 'patient', 'id' => $p->id, 'title' => $p->name, 'subtitle' => $p->code.($p->phone ? " · {$p->phone}" : ''), 'href' => "/patients/{$p->id}"];
            });
        }
        if ($u->hasPermission(Permissions::DOCTORS_VIEW)) {
            $ids = $phoneIds('doctors');
            Doctor::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhereIn('id', $ids))->with('clinic:id,name')->orderBy('name')->limit(5)->get()
                ->each(function ($d) use (&$out) {
                    $out[] = ['type' => 'doctor', 'id' => $d->id, 'title' => $d->name, 'subtitle' => "{$d->clinic->name} · {$d->phone}", 'href' => "/doctors/{$d->id}"];
                });
        }
        if ($u->hasPermission(Permissions::CLINICS_VIEW)) {
            $ids = $phoneIds('clinics');
            Clinic::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhereIn('id', $ids))->orderBy('name')->limit(5)->get()
                ->each(function ($k) use (&$out) {
                    $out[] = ['type' => 'clinic', 'id' => $k->id, 'title' => $k->name, 'subtitle' => "{$k->contact_person} · {$k->phone}", 'href' => "/clinics/{$k->id}"];
                });
        }
        if ($u->hasPermission(Permissions::INVOICES_VIEW)) {
            $q = Invoice::where('invoice_number', 'like', $like);
            $withinClinic($q, 'clinic_id');
            $q->with(['dentalCase:id,case_number', 'clinic:id,name'])->orderByDesc('issued_at')->limit(5)->get()->each(function ($i) use (&$out) {
                $out[] = ['type' => 'invoice', 'id' => $i->id, 'title' => $i->invoice_number, 'subtitle' => "{$i->dentalCase->case_number} · {$i->clinic->name}", 'href' => "/invoices/{$i->id}"];
            });
        }

        return $out;
    }
}
