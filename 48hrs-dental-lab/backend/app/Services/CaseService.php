<?php

namespace App\Services;

use App\Domain\Billing;
use App\Domain\Dates;
use App\Domain\LabMessages;
use App\Domain\Permissions;
use App\Domain\Sla;
use App\Domain\Workflow;
use App\Exceptions\ApiException;
use App\Models\CaseNote;
use App\Models\CaseStatusHistory;
use App\Models\Clinic;
use App\Models\DentalCase;
use App\Models\Doctor;
use App\Models\LabService;
use App\Models\Patient;
use App\Models\User;
use App\Support\Activity;
use App\Support\CaseFiles;
use App\Support\LabSettings;
use App\Support\Numbers;
use App\Support\Present;
use App\Support\Query;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaseService
{
    /** What a caller may see of a case: files and money depend on their permissions. */
    public static function present(User $u, string $id, ?int $now = null): array
    {
        $c = DentalCase::with(Present::CASE_DETAIL_WITH)->findOrFail($id);

        return Present::caseDetail($c, $now ?? Dates::nowMs(), $u->hasPermission(Permissions::FILES_VIEW), $u->hasPermission([Permissions::INVOICES_VIEW, Permissions::PAYMENTS_VIEW], 'any'));
    }

    public static function recordHistory(User $u, string $caseId, ?string $from, string $to, ?string $note): void
    {
        CaseStatusHistory::create(['case_id' => $caseId, 'from_status' => $from, 'to_status' => $to, 'user_id' => $u->id, 'user_role' => $u->role_key, 'note' => ($note = trim((string) $note)) !== '' ? $note : null]);
    }

    public function list(User $u, array $filters, array $page, string $sort, string $dir): array
    {
        $now = Dates::nowMs();
        $q = CaseQuery::scoped($u);
        CaseQuery::filter($q, $filters, LabSettings::sla(), $now);
        $total = (clone $q)->count();
        $p = Query::paginate($page, $total);
        CaseQuery::sort($q, $sort, $dir);
        $rows = $q->with(Present::CASE_LIST_WITH)->withCount('attachments')->offset($p['offset'])->limit($p['limit'])->get();

        return ['data' => $rows->map(fn ($c) => Present::caseListItem($c, $now))->all(), 'meta' => $p['meta']];
    }

    /** Sidebar badges: counts over the cases this user may see, plus unread notifications. */
    public function counts(User $u): array
    {
        $byStatus = CaseQuery::scoped($u)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
        $n = fn (string ...$s) => array_sum(array_map(fn ($st) => (int) ($byStatus[$st] ?? 0), $s));
        $overdue = CaseQuery::scoped($u)->whereIn('status', Workflow::IN_LAB_STATUSES)->whereNotNull('due_at')->where('due_at', '<=', now())->count();

        return [
            'awaitingAcceptance' => $n('submitted'),
            'pendingAssignment' => $n('received', 'review'),
            'inProduction' => $n('assigned', 'in_production', 'rework'),
            'pendingQc' => $n('quality_control'),
            'readyForDelivery' => $n('ready', 'out_for_delivery'),
            'overdue' => $overdue,
            'unreadNotifications' => DB::table('notifications')->where('user_id', $u->id)->whereNull('read_at')->count(),
        ];
    }

    public function get(User $u, string $idOrNumber): array
    {
        $id = CaseQuery::find($u, $idOrNumber)->value('cases.id') ?? throw ApiException::notFound();

        return self::present($u, $id);
    }

    public function create(User $u, array $b): array
    {
        $now = Dates::nowMs();
        $isStaff = $u->hasPermission(Permissions::CASES_CREATE);
        $clinicId = $b['clinicId'];
        if (! $isStaff) {
            // Clinic-portal users can only submit for their own clinic.
            if (! $u->clinic_id) {
                throw ApiException::forbidden();
            }
            $clinicId = $u->clinic_id;
        }
        $clinic = $clinicId ? Clinic::find($clinicId) : null;
        $doctor = Doctor::find($b['doctorId']);
        $service = LabService::find($b['serviceId']);
        $patient = $b['patientId'] ? Patient::find($b['patientId']) : null;
        $settings = LabSettings::get();

        $errors = [];
        if (! $clinic) {
            $errors['clinicId'] = [$clinicId ? 'Select a valid clinic.' : 'Clinic is required.'];
        } elseif ($clinic->status !== 'active') {
            $errors['clinicId'] = ['This clinic is inactive.'];
        }
        if (! $doctor) {
            $errors['doctorId'] = ['Select a valid doctor.'];
        } elseif ($doctor->clinic_id !== $clinicId) {
            $errors['doctorId'] = ['This doctor does not belong to the selected clinic.'];
        } elseif ($doctor->status !== 'active') {
            $errors['doctorId'] = ['This doctor is inactive.'];
        }
        if (! $service?->active) {
            $errors['serviceId'] = ['Select an active service.'];
        }
        $teeth = array_values(array_unique($b['teeth']));
        sort($teeth);
        if ($service?->unit_mode === 'tooth' && ! $teeth) {
            $errors['teeth'] = ['Select at least one tooth on the chart.'];
        }
        if ($service?->unit_mode === 'denture' && ! $b['dentureType']) {
            $errors['dentureType'] = ['Select the denture type.'];
        }
        if (! $b['patientId'] && ! $b['newPatient']) {
            $errors['patientId'] = ['Select a patient or enter a new patient name.'];
        }
        if ($b['patientId'] && (! $patient || (! $isStaff && $patient->clinic_id !== $clinicId))) {
            $errors['patientId'] = ['Select a valid patient.'];
        }
        $dueOverride = $isStaff && $b['dueAt'] ? Dates::ms($b['dueAt']) : null;
        if ($dueOverride !== null && $dueOverride <= $now) {
            $errors['dueAt'] = ['The due date must be in the future.'];
        }
        $newCode = trim((string) ($b['newPatient']['code'] ?? ''));
        if (! $patient && $newCode !== '' && Patient::where('code', $newCode)->exists()) {
            $errors['newPatient.code'] = ['This patient reference is already in use.'];
        }
        ApiException::throwIf($errors);

        $price = Billing::priceCase($service->unit_mode, $service->unit_price, $teeth, $b['dentureType'], $b['priority'] === 'urgent', $settings['emergencyFeePerUnit']);
        $receiveNow = $isStaff && $b['receiveNow'];
        $at = Dates::fromMs($now);

        $id = DB::transaction(function () use ($u, $b, $patient, $clinicId, $clinic, $doctor, $service, $teeth, $price, $receiveNow, $settings, $dueOverride, $now, $at, $newCode) {
            $patientId = $patient?->id;
            if (! $patientId && $b['newPatient']) {
                $code = $newCode !== '' ? $newCode : Numbers::patientCode(Sequences::next(Sequences::PATIENT));
                $patientId = Patient::create(['code' => $code, 'name' => $b['newPatient']['name'], 'phone' => $b['newPatient']['phone'] ?? '', 'email' => '', 'clinic_id' => $clinicId, 'notes' => ''])->id;
            }
            $status = $receiveNow ? 'received' : 'submitted';
            $c = DentalCase::create([
                'case_number' => Numbers::caseNumber(Dates::labYear($now), Sequences::next(Sequences::CASE)),
                'patient_id' => $patientId,
                'doctor_id' => $doctor->id,
                'clinic_id' => $doctor->clinic_id,
                'service_id' => $service->id,
                'case_type' => $service->case_type,
                'restoration_type' => $service->name,
                'material' => $b['material'] !== '' ? $b['material'] : $service->default_material,
                'shade' => $b['shade'],
                'teeth' => $service->unit_mode === 'tooth' ? $teeth : [],
                'denture_type' => $service->unit_mode === 'denture' ? $b['dentureType'] : null,
                'units' => $price['units'],
                'unit_price' => $price['unitPrice'],
                'emergency_fee' => $price['emergencyFee'],
                'total' => $price['total'],
                'priority' => $b['priority'],
                'status' => $status,
                'instructions' => $b['instructions'],
                'submitted_at' => $receiveNow ? null : $at,
                // The 48-hour clock starts on the server, now.
                'received_at' => $receiveNow ? $at : null,
                'due_at' => $receiveNow ? Dates::fromMs($dueOverride ?? Sla::computeDueAt($now, $settings['slaHours'])) : null,
                'created_by_id' => $u->id,
            ]);
            self::recordHistory($u, $c->id, null, $status, $receiveNow ? 'Registered at reception — 48-hour clock started' : 'Submitted through the clinic portal');
            if ($receiveNow) {
                InvoiceIssuer::issue($c, $settings, $now);
            }
            Activity::log($u, 'case.create', "Created case {$c->case_number}", 'case', $c->id, $c->case_number);
            if ($receiveNow) {
                Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['assigners']), LabMessages::caseReceived($c->case_number), $c->id, $u->id);
            } else {
                Notifier::send(Notifier::recipients(LabMessages::RECIPIENTS['intakeAndAdmin']), LabMessages::caseSubmitted($c->case_number, $clinic->name), $c->id, $u->id);
            }

            return $c->id;
        });

        return self::present($u, $id);
    }

    public function update(User $u, string $idOrNumber, array $b): array
    {
        $now = Dates::nowMs();
        /** @var DentalCase $c */
        $c = CaseQuery::find($u, $idOrNumber)->with(['service', 'invoice'])->first() ?? throw ApiException::notFound();
        if (! Workflow::isOpen($c->status)) {
            throw ApiException::unprocessable('Closed cases cannot be edited.');
        }
        $unitMode = $c->service->unit_mode;
        if (array_key_exists('teeth', $b) && $unitMode === 'tooth' && ! $b['teeth']) {
            throw ApiException::validation(['teeth' => ['Select at least one tooth.']]);
        }
        $teeth = $c->teeth ?? [];
        if (array_key_exists('teeth', $b) && $unitMode === 'tooth') {
            $teeth = array_values(array_unique($b['teeth']));
            sort($teeth);
        }
        $dentureType = array_key_exists('dentureType', $b) && $unitMode === 'denture' ? $b['dentureType'] : $c->denture_type;
        $priority = $b['priority'] ?? $c->priority;
        $settings = LabSettings::get();
        // Existing cases keep the unit price they were accepted at; only quantities and the emergency fee change.
        $price = Billing::priceCase($unitMode, $c->unit_price, $teeth, $dentureType, $priority === 'urgent', $settings['emergencyFeePerUnit']);
        if ($c->invoice && $price['total'] < Present::invoiceFigures($c->invoice, $now)['paid']) {
            throw ApiException::unprocessable('The new total would be lower than what has already been paid.');
        }
        DB::transaction(function () use ($u, $c, $b, $teeth, $dentureType, $priority, $price) {
            $c->forceFill([
                'material' => $b['material'] ?? $c->material,
                'shade' => $b['shade'] ?? $c->shade,
                'instructions' => $b['instructions'] ?? $c->instructions,
                'teeth' => $teeth,
                'denture_type' => $dentureType,
                'priority' => $priority,
                'units' => $price['units'],
                'emergency_fee' => $price['emergencyFee'],
                'total' => $price['total'],
            ])->save();
            $c->invoice?->forceFill(['subtotal' => $price['subtotal'], 'emergency_fee' => $price['emergencyFee'], 'total' => $price['total']])->save();
            Activity::log($u, 'case.update', "Edited case {$c->case_number}", 'case', $c->id, $c->case_number);
        });

        return self::present($u, $c->id);
    }

    public function remove(User $u, string $idOrNumber): void
    {
        /** @var DentalCase $c */
        $c = CaseQuery::find($u, $idOrNumber)->with(['invoice', 'attachments'])->first() ?? throw ApiException::notFound();
        if ($c->invoice && $c->invoice->payments()->exists()) {
            throw ApiException::unprocessable('This case has recorded payments and cannot be deleted. Cancel it instead.');
        }
        $keys = $c->attachments->pluck('storage_key')->all();
        DB::transaction(function () use ($u, $c) {
            // History, notes, files, QC, deliveries, invoice and notifications cascade with the case.
            $c->delete();
            Activity::log($u, 'case.delete', "Deleted case {$c->case_number}", 'case', null, $c->case_number);
        });
        // Files go only after the rows are gone, so a failed delete never leaves rows pointing at missing files.
        foreach ($keys as $key) {
            try {
                CaseFiles::delete($key);
            } catch (\Throwable $e) {
                Log::warning('could not remove a case file', ['case' => $c->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function addNote(User $u, string $idOrNumber, string $text): array
    {
        $id = CaseQuery::find($u, $idOrNumber)->value('cases.id') ?? throw ApiException::notFound();
        DB::transaction(function () use ($u, $id, $text) {
            CaseNote::create(['case_id' => $id, 'text' => $text, 'author_id' => $u->id]);
            DentalCase::whereKey($id)->update(['updated_at' => now()->format('Y-m-d H:i:s.v')]);
        });

        return self::present($u, $id);
    }
}
