<?php

namespace App\Services;

use App\Domain\Analytics;
use App\Domain\Dates;
use App\Domain\Num;
use App\Domain\Permissions;
use App\Domain\Workflow;
use App\Exceptions\ApiException;
use App\Models\Clinic;
use App\Models\DentalCase;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QualityCheck;
use App\Models\Technician;
use App\Models\User;
use App\Support\Activity;
use App\Support\LabSettings;
use App\Support\Numbers;
use App\Support\Present;
use App\Support\Query;
use App\Support\Scope;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Patients, doctors, clinics and technicians. Clinic-portal users only see their
 * own clinic's records; records with case history cannot be deleted (set them
 * inactive instead) so the audit trail stays intact.
 */
class DirectoryService
{
    /** Detail pages: visible cases of one patient/doctor/clinic, their totals and the newest ones. */
    private function relationView(User $u, string $column, string $id, int $recentLimit): array
    {
        $now = Dates::nowMs();
        $rows = CaseQuery::scoped($u)->where("cases.{$column}", $id)->with(Present::CASE_LIST_WITH)->withCount('attachments')->orderByDesc('created_at')->get();
        $items = $rows->map(fn ($c) => Present::caseListItem($c, $now))->all();
        $invoices = [];
        foreach ($rows as $r) {
            if ($r->invoice) {
                $invoices[$r->id] = Present::invoiceFigures($r->invoice, $now);
            }
        }

        return ['stats' => Analytics::relationStats($items, $invoices, $now, LabSettings::sla()), 'recentCases' => array_slice($items, 0, $recentLimit)];
    }

    /** @return array{total: array<string, int>, active: array<string, int>} */
    private function caseCounts(string $column, array $ids): array
    {
        $total = [];
        $active = [];
        if ($ids) {
            $open = Workflow::openStatuses();
            foreach (DentalCase::whereIn($column, $ids)->selectRaw("{$column} AS k, status, COUNT(*) AS n")->groupBy($column, 'status')->toBase()->get() as $r) {
                $total[$r->k] = ($total[$r->k] ?? 0) + $r->n;
                if (in_array($r->status, $open, true)) {
                    $active[$r->k] = ($active[$r->k] ?? 0) + $r->n;
                }
            }
        }

        return ['total' => $total, 'active' => $active];
    }

    private function outstandingByClinic(array $ids): array
    {
        return $ids ? DB::table('invoices')->whereIn('clinic_id', $ids)->groupBy('clinic_id')->selectRaw('clinic_id, SUM(GREATEST(total - amount_paid, 0)) AS o')->pluck('o', 'clinic_id')->map(fn ($v) => Present::money($v))->all() : [];
    }

    /** In-memory sort for small tables sorted by derived figures (nulls last, case-insensitive text). */
    private function sortBy(array $items, ?\Closure $get, string $dir): array
    {
        if (! $get) {
            return $items;
        }
        $m = $dir === 'asc' ? 1 : -1;
        usort($items, function ($a, $b) use ($get, $m) {
            $va = $get($a);
            $vb = $get($b);
            if ($va === $vb) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            if (is_string($va) && is_string($vb)) {
                return (strcasecmp($va, $vb) <=> 0) * $m;
            }

            return ($va < $vb ? -1 : 1) * $m;
        });

        return $items;
    }

    private function pageOf(array $items, array $page): array
    {
        $p = Query::paginate($page, count($items));

        return ['data' => array_slice($items, $p['offset'], $p['limit']), 'meta' => $p['meta']];
    }

    /* ------------------------------ Patients ------------------------------ */

    public function patients(User $u, array $q, array $page): array
    {
        $query = Patient::query();
        Scope::whereClinic($query, $u, 'patients.clinic_id');
        if ($q['clinicId']) {
            $query->where('patients.clinic_id', $q['clinicId']);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $query->where(fn ($w) => $w->where('patients.name', 'like', $like)->orWhere('patients.code', 'like', $like)->orWhere('patients.phone', 'like', $like)->orWhere('patients.email', 'like', $like));
        }
        $total = (clone $query)->count();
        $p = Query::paginate($page, $total);
        $sort = $q['sort'] ?? 'createdAt';
        $dir = $q['dir'];
        $query->select('patients.*');
        match ($sort) {
            'name' => $query->orderBy('patients.name', $dir),
            'code' => $query->orderBy('patients.code', $dir),
            'clinic' => $query->leftJoin('clinics as sk', 'sk.id', '=', 'patients.clinic_id')->orderByRaw('sk.name IS NULL '.($dir === 'asc' ? 'ASC' : 'DESC'))->orderBy('sk.name', $dir)->orderBy('patients.name'),
            'caseCount' => $query->withCount('cases')->orderBy('cases_count', $dir)->orderBy('patients.name'),
            'lastCaseAt' => $query->addSelect(['last_case_at' => DentalCase::selectRaw('MAX(created_at)')->whereColumn('cases.patient_id', 'patients.id')])->orderByRaw('last_case_at IS NULL ASC')->orderBy('last_case_at', $dir)->orderBy('patients.id'),
            default => $query->orderBy('patients.created_at', $dir)->orderBy('patients.code', $dir),
        };
        $rows = $query->with('clinic:id,name')->withCount('cases')->offset($p['offset'])->limit($p['limit'])->get();
        $last = $rows->isEmpty() ? [] : DentalCase::whereIn('patient_id', $rows->pluck('id'))->groupBy('patient_id')->selectRaw('patient_id, MAX(created_at) AS m')->pluck('m', 'patient_id')->all();

        return ['data' => $rows->map(fn ($r) => [...Present::patient($r), 'clinicName' => $r->clinic?->name, 'caseCount' => $r->cases_count, 'lastCaseAt' => isset($last[$r->id]) ? Dates::iso(new \DateTimeImmutable($last[$r->id], new \DateTimeZone('UTC'))) : null])->all(), 'meta' => $p['meta']];
    }

    public function patient(User $u, string $id): array
    {
        $p = Patient::with('clinic:id,name')->withCount('cases')->find($id);
        $scope = Scope::clinic($u);
        if (! $p || ($scope !== null && $p->clinic_id !== $scope)) {
            throw ApiException::notFound();
        }
        $last = DentalCase::where('patient_id', $p->id)->max('created_at');

        return [...Present::patient($p), 'clinicName' => $p->clinic?->name, 'caseCount' => $p->cases_count, 'lastCaseAt' => $last ? Dates::iso(new \DateTimeImmutable($last, new \DateTimeZone('UTC'))) : null, ...$this->relationView($u, 'patient_id', $p->id, 50)];
    }

    private function validatePatient(array $b, ?string $selfId = null): void
    {
        $errors = [];
        $code = trim((string) ($b['code'] ?? ''));
        if ($code !== '' && Patient::where('code', $code)->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists()) {
            $errors['code'] = ['This patient reference is already in use.'];
        }
        if ($b['dateOfBirth'] && Dates::ms($b['dateOfBirth'].'T00:00:00Z') > Dates::nowMs()) {
            $errors['dateOfBirth'] = ['Date of birth cannot be in the future.'];
        }
        if ($b['clinicId'] && ! Clinic::whereKey($b['clinicId'])->exists()) {
            $errors['clinicId'] = ['Select a valid clinic.'];
        }
        ApiException::throwIf($errors);
    }

    private function patientFields(array $b): array
    {
        return ['name' => $b['name'], 'phone' => $b['phone'], 'email' => $b['email'], 'gender' => $b['gender'], 'date_of_birth' => $b['dateOfBirth'], 'clinic_id' => $b['clinicId'], 'notes' => $b['notes']];
    }

    public function createPatient(User $u, array $b): array
    {
        $this->validatePatient($b);
        $p = DB::transaction(function () use ($u, $b) {
            $code = trim((string) ($b['code'] ?? '')) ?: Numbers::patientCode(Sequences::next(Sequences::PATIENT));
            $p = Patient::create(['code' => $code, ...$this->patientFields($b)]);
            Activity::log($u, 'patient.create', "Added patient {$p->name}", 'patient', $p->id, $p->code);

            return $p;
        });

        return Present::patient($p->fresh());
    }

    public function updatePatient(User $u, string $id, array $b): array
    {
        $p = Patient::find($id) ?? throw ApiException::notFound();
        $this->validatePatient($b, $id);
        DB::transaction(function () use ($u, $p, $b) {
            $p->forceFill([...$this->patientFields($b), 'code' => trim((string) ($b['code'] ?? '')) ?: $p->code])->save();
            Activity::log($u, 'patient.update', "Updated patient {$p->name}", 'patient', $p->id, $p->code);
        });

        return Present::patient($p->fresh());
    }

    public function deletePatient(User $u, string $id): void
    {
        $p = Patient::withCount('cases')->find($id) ?? throw ApiException::notFound();
        if ($p->cases_count) {
            throw ApiException::unprocessable('This patient has cases and cannot be deleted.');
        }
        DB::transaction(function () use ($u, $p) {
            $p->delete();
            Activity::log($u, 'patient.delete', "Deleted patient {$p->name}", 'patient', null, $p->code);
        });
    }

    /* ------------------------------- Doctors ------------------------------ */

    public function doctors(User $u, array $q, array $page): array
    {
        $query = Doctor::with('clinic:id,name');
        Scope::whereClinic($query, $u, 'doctors.clinic_id');
        if ($q['status']) {
            $query->where('status', $q['status']);
        }
        if ($q['clinicId']) {
            $query->where('clinic_id', $q['clinicId']);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('specialty', 'like', $like));
        }
        $rows = $query->get();
        $counts = $this->caseCounts('doctor_id', $rows->pluck('id')->all());
        $items = $rows->map(fn ($d) => [...Present::doctor($d), 'clinicName' => $d->clinic->name, 'caseCount' => $counts['total'][$d->id] ?? 0, 'activeCases' => $counts['active'][$d->id] ?? 0])->all();
        $keys = ['name' => fn ($d) => $d['name'], 'clinic' => fn ($d) => $d['clinicName'], 'caseCount' => fn ($d) => $d['caseCount'], 'activeCases' => fn ($d) => $d['activeCases'], 'status' => fn ($d) => $d['status']];

        return $this->pageOf($this->sortBy($items, $keys[$q['sort'] ?? 'name'] ?? $keys['name'], $q['dir']), $page);
    }

    public function doctor(User $u, string $id): array
    {
        $d = Doctor::with('clinic')->find($id) ?? throw ApiException::notFound();
        $counts = $this->caseCounts('doctor_id', [$d->id]);

        return [...Present::doctor($d), 'clinicName' => $d->clinic->name, 'caseCount' => $counts['total'][$d->id] ?? 0, 'activeCases' => $counts['active'][$d->id] ?? 0, 'clinic' => Present::clinic($d->clinic), ...$this->relationView($u, 'doctor_id', $d->id, 8)];
    }

    private function doctorFields(array $b): array
    {
        if (! Clinic::whereKey($b['clinicId'])->exists()) {
            throw ApiException::validation(['clinicId' => ['Select a valid clinic.']]);
        }

        return ['name' => $b['name'], 'clinic_id' => $b['clinicId'], 'phone' => $b['phone'], 'email' => $b['email'], 'specialty' => $b['specialty'], 'status' => $b['status']];
    }

    public function createDoctor(User $u, array $b): array
    {
        $fields = $this->doctorFields($b);
        $d = DB::transaction(function () use ($u, $fields) {
            $d = Doctor::create($fields);
            Activity::log($u, 'doctor.create', "Added {$d->name}", 'doctor', $d->id, $d->name);

            return $d;
        });

        return Present::doctor($d->fresh());
    }

    public function updateDoctor(User $u, string $id, array $b): array
    {
        $d = Doctor::find($id) ?? throw ApiException::notFound();
        $fields = $this->doctorFields($b);
        DB::transaction(function () use ($u, $d, $fields) {
            $d->forceFill($fields)->save();
            Activity::log($u, 'doctor.update', "Updated {$d->name}", 'doctor', $d->id, $d->name);
        });

        return Present::doctor($d->fresh());
    }

    public function deleteDoctor(User $u, string $id): void
    {
        $d = Doctor::withCount('cases')->find($id) ?? throw ApiException::notFound();
        if ($d->cases_count) {
            throw ApiException::unprocessable('This doctor has cases. Set them to inactive instead.');
        }
        DB::transaction(function () use ($u, $d) {
            $d->delete();
            Activity::log($u, 'doctor.delete', "Deleted {$d->name}", 'doctor', null, $d->name);
        });
    }

    /* ------------------------------- Clinics ------------------------------ */

    private function clinicItems($rows): array
    {
        $ids = $rows->pluck('id')->all();
        $counts = $this->caseCounts('clinic_id', $ids);
        $outstanding = $this->outstandingByClinic($ids);

        return $rows->map(fn ($k) => [...Present::clinic($k), 'doctorCount' => $k->doctors_count, 'caseCount' => $counts['total'][$k->id] ?? 0, 'activeCases' => $counts['active'][$k->id] ?? 0, 'outstanding' => $outstanding[$k->id] ?? 0])->all();
    }

    public function clinics(User $u, array $q, array $page): array
    {
        $query = Clinic::withCount('doctors');
        Scope::whereClinic($query, $u, 'clinics.id');
        if ($q['status']) {
            $query->where('status', $q['status']);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('address', 'like', $like));
        }
        $items = $this->clinicItems($query->get());
        $keys = ['name' => fn ($k) => $k['name'], 'caseCount' => fn ($k) => $k['caseCount'], 'activeCases' => fn ($k) => $k['activeCases'], 'outstanding' => fn ($k) => $k['outstanding'], 'status' => fn ($k) => $k['status']];

        return $this->pageOf($this->sortBy($items, $keys[$q['sort'] ?? 'name'] ?? $keys['name'], $q['dir']), $page);
    }

    public function clinic(User $u, string $id): array
    {
        $k = Clinic::withCount('doctors')->with(['doctors' => fn ($q) => $q->orderBy('name')])->find($id) ?? throw ApiException::notFound();
        [$item] = $this->clinicItems(collect([$k]));

        return [...$item, 'doctors' => $k->doctors->map(fn ($d) => Present::doctor($d))->all(), ...$this->relationView($u, 'clinic_id', $k->id, 8)];
    }

    private function clinicFields(array $b, ?string $selfId = null): array
    {
        if (Clinic::whereRaw('LOWER(name) = ?', [mb_strtolower($b['name'])])->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists()) {
            throw ApiException::validation(['name' => ['A clinic with this name already exists.']]);
        }

        return ['name' => $b['name'], 'contact_person' => $b['contactPerson'], 'phone' => $b['phone'], 'email' => $b['email'], 'address' => $b['address'], 'status' => $b['status'], 'notes' => $b['notes']];
    }

    public function createClinic(User $u, array $b): array
    {
        $fields = $this->clinicFields($b);
        $k = DB::transaction(function () use ($u, $fields) {
            $k = Clinic::create($fields);
            Activity::log($u, 'clinic.create', "Added clinic {$k->name}", 'clinic', $k->id, $k->name);

            return $k;
        });

        return Present::clinic($k->fresh());
    }

    public function updateClinic(User $u, string $id, array $b): array
    {
        $k = Clinic::find($id) ?? throw ApiException::notFound();
        $fields = $this->clinicFields($b, $id);
        DB::transaction(function () use ($u, $k, $fields) {
            $k->forceFill($fields)->save();
            Activity::log($u, 'clinic.update', "Updated clinic {$k->name}", 'clinic', $k->id, $k->name);
        });

        return Present::clinic($k->fresh());
    }

    public function deleteClinic(User $u, string $id): void
    {
        $k = Clinic::withCount(['cases', 'doctors'])->find($id) ?? throw ApiException::notFound();
        if ($k->cases_count || $k->doctors_count) {
            throw ApiException::unprocessable('This clinic has doctors or cases. Set it to inactive instead.');
        }
        DB::transaction(function () use ($u, $k) {
            $k->delete();
            Activity::log($u, 'clinic.delete', "Deleted clinic {$k->name}", 'clinic', null, $k->name);
        });
    }

    /* ----------------------------- Technicians ---------------------------- */

    public function technicians(array $q, array $page): array
    {
        $now = Dates::nowMs();
        $query = Technician::query();
        if ($q['active'] !== null) {
            $query->where('active', $q['active']);
        }
        if ($q['search']) {
            $like = Query::like($q['search']);
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('specialty', 'like', $like));
        }
        $rows = $query->get();
        $sla = LabSettings::sla();
        $cases = DentalCase::whereIn('technician_id', $rows->pluck('id'))->get(['technician_id', 'status', 'received_at', 'due_at', 'delivered_at', 'submitted_at', 'created_at'])->groupBy('technician_id');
        $items = $rows->map(fn ($t) => [...Present::technician($t), ...Analytics::technicianWorkload(($cases[$t->id] ?? collect())->map(fn ($c) => Present::slaFields($c))->all(), $now, $sla, config('lab.timezone'))])->all();
        $keys = ['name' => fn ($t) => $t['name'], 'activeCases' => fn ($t) => $t['activeCases'], 'completedCases' => fn ($t) => $t['completedCases'], 'overdue' => fn ($t) => $t['overdue'], 'onTimeRate' => fn ($t) => $t['onTimeRate']];

        return $this->pageOf($this->sortBy($items, $keys[$q['sort'] ?? 'name'] ?? $keys['name'], $q['dir']), $page);
    }

    /** Technicians may always open their own profile; anyone else needs technicians.view. */
    public function technician(User $u, string $id): array
    {
        if ($u->technicianId() !== $id && ! $u->hasPermission(Permissions::TECHNICIANS_VIEW)) {
            throw ApiException::forbidden();
        }
        $t = Technician::find($id) ?? throw ApiException::notFound();
        $now = Dates::nowMs();
        $items = DentalCase::where('technician_id', $id)->with(Present::CASE_LIST_WITH)->withCount('attachments')->get()->map(fn ($c) => Present::caseListItem($c, $now))->all();
        $active = array_values(array_filter($items, fn ($c) => in_array($c['status'], Analytics::technicianActiveStatuses(), true)));
        usort($active, fn ($a, $b) => strcmp($a['dueAt'] ?? '', $b['dueAt'] ?? ''));
        $finished = array_values(array_filter($items, fn ($c) => in_array($c['status'], Analytics::technicianFinishedStatuses(), true)));
        usort($finished, fn ($a, $b) => strcmp($b['readyAt'] ?? '', $a['readyAt'] ?? ''));

        return [
            ...Present::technician($t),
            ...Analytics::technicianWorkload($items, $now, LabSettings::sla(), config('lab.timezone')),
            'qcPending' => count(array_filter($items, fn ($c) => $c['status'] === 'quality_control')),
            'qcFailures' => QualityCheck::where('result', 'failed')->whereHas('dentalCase', fn ($q) => $q->where('technician_id', $id))->count(),
            'avgProductionHours' => Num::average(array_values(array_filter(array_map(fn ($c) => Analytics::hoursBetween($c['productionStartedAt'], $c['productionCompletedAt']), $items), fn ($x) => $x !== null))),
            'activeCaseList' => $active,
            'recentCompleted' => array_slice($finished, 0, 10),
        ];
    }

    private function assertUniqueTechnicianEmail(string $email, ?string $selfId = null): void
    {
        if (Technician::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists()) {
            throw ApiException::validation(['email' => ['Another technician uses this email.']]);
        }
    }

    public function createTechnician(User $u, array $b): array
    {
        $this->assertUniqueTechnicianEmail($b['email']);
        $t = DB::transaction(function () use ($u, $b) {
            // A technician login with the same e-mail is linked automatically.
            $linked = User::where('email', $b['email'])->where('role_key', 'technician')->whereDoesntHave('technician')->first();
            $t = Technician::create(['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'specialty' => $b['specialty'], 'active' => $b['active'], 'user_id' => $linked?->id]);
            Activity::log($u, 'technician.create', "Added technician {$t->name}", 'technician', $t->id, $t->name);

            return $t;
        });

        return Present::technician($t->fresh());
    }

    public function updateTechnician(User $u, string $id, array $b): array
    {
        $t = Technician::find($id) ?? throw ApiException::notFound();
        $this->assertUniqueTechnicianEmail($b['email'], $id);
        if (! $b['active'] && DentalCase::where('technician_id', $id)->whereIn('status', Workflow::PRODUCTION_STATUSES)->exists()) {
            throw ApiException::unprocessable("{$t->name} still has cases in production. Reassign them before deactivating.");
        }
        DB::transaction(function () use ($u, $t, $b) {
            $t->forceFill(['name' => $b['name'], 'email' => $b['email'], 'phone' => $b['phone'], 'specialty' => $b['specialty'], 'active' => $b['active']])->save();
            Activity::log($u, 'technician.update', "Updated technician {$t->name}", 'technician', $t->id, $t->name);
        });

        return Present::technician($t->fresh());
    }

    public function deleteTechnician(User $u, string $id): void
    {
        $t = Technician::withCount(['cases', 'assignments'])->find($id) ?? throw ApiException::notFound();
        if ($t->cases_count || $t->assignments_count) {
            throw ApiException::unprocessable("{$t->name} has case history and cannot be deleted. Set the technician to inactive instead.");
        }
        DB::transaction(function () use ($u, $t) {
            $t->delete();
            Activity::log($u, 'technician.delete', "Deleted technician {$t->name}", 'technician', null, $t->name);
        });
    }
}
