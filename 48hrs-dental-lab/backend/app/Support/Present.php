<?php

namespace App\Support;

use App\Domain\Billing;
use App\Domain\Catalog;
use App\Domain\Dates;
use App\Domain\Num;
use App\Models\ActivityLog;
use App\Models\CaseAttachment;
use App\Models\Clinic;
use App\Models\Delivery;
use App\Models\DentalCase;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\LabService;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\QualityCheck;
use App\Models\Technician;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Eloquent rows → the JSON shapes the React app expects (packages/shared/src/models.ts):
 * camelCase keys, ISO-8601 UTC timestamps with milliseconds, money as numbers.
 */
final class Present
{
    public static function iso(?\DateTimeInterface $d): ?string
    {
        return $d ? Dates::iso($d) : null;
    }

    public static function money(float|int|string|null $v): float|int
    {
        return Num::clean(Num::round2((float) ($v ?? 0)));
    }

    public static function user(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role_key,
            'active' => (bool) $u->active,
            'clinicId' => $u->clinic_id,
            'doctorId' => $u->doctor_id,
            'technicianId' => $u->technicianId(),
            'lastLoginAt' => self::iso($u->last_login_at),
            'createdAt' => self::iso($u->created_at),
        ];
    }

    public static function clinic(Clinic $k): array
    {
        return ['id' => $k->id, 'name' => $k->name, 'contactPerson' => $k->contact_person, 'phone' => $k->phone, 'email' => $k->email, 'address' => $k->address, 'status' => $k->status, 'notes' => $k->notes ?? '', 'createdAt' => self::iso($k->created_at)];
    }

    public static function doctor(Doctor $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'clinicId' => $d->clinic_id, 'phone' => $d->phone, 'email' => $d->email, 'specialty' => $d->specialty, 'status' => $d->status, 'createdAt' => self::iso($d->created_at)];
    }

    public static function patient(Patient $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'phone' => $p->phone,
            'email' => $p->email,
            'gender' => $p->gender,
            'dateOfBirth' => $p->date_of_birth?->format('Y-m-d'),
            'clinicId' => $p->clinic_id,
            'notes' => $p->notes ?? '',
            'createdAt' => self::iso($p->created_at),
        ];
    }

    public static function technician(Technician $t): array
    {
        return ['id' => $t->id, 'userId' => $t->user_id, 'name' => $t->name, 'email' => $t->email, 'phone' => $t->phone, 'specialty' => $t->specialty, 'active' => (bool) $t->active, 'createdAt' => self::iso($t->created_at)];
    }

    public static function service(LabService $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'caseType' => $s->case_type, 'unitMode' => $s->unit_mode, 'unitPrice' => self::money($s->unit_price), 'defaultMaterial' => $s->default_material, 'active' => (bool) $s->active];
    }

    /** paid / remaining / status are derived here and nowhere else (Total − Paid = Remaining). */
    public static function invoiceFigures(Invoice $inv, int $now): array
    {
        $total = self::money($inv->total);
        $paid = self::money($inv->amount_paid);

        return ['total' => $total, 'paid' => $paid, 'remaining' => Num::clean(max(0, Num::round2($total - $paid))), 'status' => Billing::invoiceStatus($total, $paid, $inv->due_date, $now)];
    }

    public static function invoice(Invoice $inv, int $now): array
    {
        return [
            'id' => $inv->id,
            'invoiceNumber' => $inv->invoice_number,
            'caseId' => $inv->case_id,
            'patientId' => $inv->patient_id,
            'doctorId' => $inv->doctor_id,
            'clinicId' => $inv->clinic_id,
            'subtotal' => self::money($inv->subtotal),
            'emergencyFee' => self::money($inv->emergency_fee),
            'discount' => self::money($inv->discount),
            ...self::invoiceFigures($inv, $now),
            'issuedAt' => self::iso($inv->issued_at),
            'dueDate' => self::iso($inv->due_date),
        ];
    }

    public static function ref(?object $m, array $fields = ['id', 'name']): ?array
    {
        if (! $m) {
            return null;
        }
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = $m->{$f};
        }

        return $out;
    }

    public static function invoiceListItem(Invoice $inv, int $now): array
    {
        return [
            ...self::invoice($inv, $now),
            'caseNumber' => $inv->dentalCase->case_number,
            'patient' => self::ref($inv->patient),
            'doctor' => self::ref($inv->doctor),
            'clinic' => self::ref($inv->clinic),
        ];
    }

    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'invoiceId' => $p->invoice_id,
            'amount' => self::money($p->amount),
            'method' => $p->method,
            'reference' => $p->reference ?? '',
            'notes' => $p->notes ?? '',
            'receivedById' => $p->received_by_id,
            'receivedByName' => $p->receivedBy->name,
            'paidAt' => self::iso($p->paid_at),
        ];
    }

    /** The LabCase core (notes and invoice must be loaded). */
    public static function labCase(DentalCase $c, int $now): array
    {
        return [
            'id' => $c->id,
            'caseNumber' => $c->case_number,
            'patientId' => $c->patient_id,
            'doctorId' => $c->doctor_id,
            'clinicId' => $c->clinic_id,
            'serviceId' => $c->service_id,
            'caseType' => $c->case_type,
            'restorationType' => $c->restoration_type,
            'material' => $c->material,
            'shade' => $c->shade,
            'teeth' => array_map('intval', $c->teeth ?? []),
            'dentureType' => $c->denture_type,
            'units' => $c->units,
            'unitPrice' => self::money($c->unit_price),
            'emergencyFee' => self::money($c->emergency_fee),
            'total' => self::money($c->total),
            'priority' => $c->priority,
            'status' => $c->status,
            'technicianId' => $c->technician_id,
            'instructions' => $c->instructions ?? '',
            'notes' => $c->notes->map(fn ($n) => ['id' => $n->id, 'text' => $n->text, 'authorId' => $n->author_id, 'authorName' => $n->author->name, 'createdAt' => self::iso($n->created_at)])->all(),
            'reworkCount' => $c->rework_count,
            'submittedAt' => self::iso($c->submitted_at),
            'receivedAt' => self::iso($c->received_at),
            'dueAt' => self::iso($c->due_at),
            'assignedAt' => self::iso($c->assigned_at),
            'productionStartedAt' => self::iso($c->production_started_at),
            'productionCompletedAt' => self::iso($c->production_completed_at),
            'qcCompletedAt' => self::iso($c->qc_completed_at),
            'readyAt' => self::iso($c->ready_at),
            'deliveredAt' => self::iso($c->delivered_at),
            'completedAt' => self::iso($c->completed_at),
            'cancelledAt' => self::iso($c->cancelled_at),
            'invoiceId' => $c->invoice?->id,
            'paymentStatus' => $c->invoice ? self::invoiceFigures($c->invoice, $now)['status'] : 'unpaid',
            'createdById' => $c->created_by_id,
            'createdAt' => self::iso($c->created_at),
            'updatedAt' => self::iso($c->updated_at),
        ];
    }

    /** Relations for list rows (eager-load these to avoid N+1). */
    public const CASE_LIST_WITH = ['patient:id,name,code', 'doctor:id,name', 'clinic:id,name', 'technician:id,name', 'invoice', 'notes.author:id,name'];

    public static function caseListItem(DentalCase $c, int $now): array
    {
        return [
            ...self::labCase($c, $now),
            'patient' => self::ref($c->patient, ['id', 'name', 'code']),
            'doctor' => self::ref($c->doctor),
            'clinic' => self::ref($c->clinic),
            'technician' => self::ref($c->technician),
            'attachmentCount' => (int) ($c->attachments_count ?? $c->attachments()->count()),
        ];
    }

    public static function attachment(CaseAttachment $a): array
    {
        return [
            'id' => $a->id,
            'caseId' => $a->case_id,
            'name' => $a->name,
            'mimeType' => $a->mime_type,
            'extension' => $a->extension,
            'size' => $a->size,
            'category' => $a->category,
            'uploadedById' => $a->uploaded_by_id,
            'uploadedByName' => $a->uploadedBy->name,
            'createdAt' => self::iso($a->created_at),
            'url' => null,
        ];
    }

    public static function qualityCheck(QualityCheck $q): array
    {
        $order = array_flip(Catalog::qcIssues());
        $issues = $q->issues->pluck('issue')->all();
        usort($issues, fn ($a, $b) => $order[$a] <=> $order[$b]);

        return [
            'id' => $q->id,
            'caseId' => $q->case_id,
            'result' => $q->result,
            'reworkRequired' => (bool) $q->rework_required,
            'issues' => $issues,
            'notes' => $q->notes ?? '',
            'checkedById' => $q->checked_by_id,
            'checkedByName' => $q->checkedBy->name,
            'checkedAt' => self::iso($q->checked_at),
        ];
    }

    public static function delivery(Delivery $d): array
    {
        return array_filter([
            'id' => $d->id,
            'caseId' => $d->case_id,
            'status' => $d->status,
            'method' => $d->method,
            'courierName' => $d->courier_name,
            'deliveredTo' => $d->delivered_to,
            'receivedBy' => $d->received_by,
            'dispatchedAt' => self::iso($d->dispatched_at),
            'deliveredAt' => self::iso($d->delivered_at),
            'notes' => $d->notes,
            'recordedById' => $d->recorded_by_id,
            'recordedByName' => $d->recordedBy->name,
            'createdAt' => self::iso($d->created_at),
        ], fn ($v, $k) => $v !== null || in_array($k, ['dispatchedAt', 'deliveredAt'], true), ARRAY_FILTER_USE_BOTH);
    }

    public const CASE_DETAIL_WITH = ['patient', 'doctor', 'clinic', 'technician', 'service', 'invoice', 'notes.author:id,name', 'history.user:id,name', 'attachments.uploadedBy:id,name', 'qualityChecks.issues', 'qualityChecks.checkedBy:id,name', 'deliveries.recordedBy:id,name'];

    /** Files and money only for users allowed to see them. */
    public static function caseDetail(DentalCase $c, int $now, bool $files, bool $money): array
    {
        return [
            ...self::labCase($c, $now),
            'patient' => self::patient($c->patient),
            'doctor' => self::doctor($c->doctor),
            'clinic' => self::clinic($c->clinic),
            'technician' => $c->technician ? self::technician($c->technician) : null,
            'service' => self::service($c->service),
            'attachmentCount' => $c->attachments->count(),
            'history' => $c->history->map(fn ($h) => array_filter([
                'id' => $h->id,
                'caseId' => $h->case_id,
                'fromStatus' => $h->from_status,
                'toStatus' => $h->to_status,
                'userId' => $h->user_id,
                'userName' => $h->user->name,
                'userRole' => $h->user_role,
                'note' => $h->note,
                'createdAt' => self::iso($h->created_at),
            ], fn ($v, $k) => $v !== null || $k === 'fromStatus', ARRAY_FILTER_USE_BOTH))->all(),
            'attachments' => $files ? $c->attachments->map(fn ($a) => self::attachment($a))->all() : [],
            'qualityChecks' => $c->qualityChecks->map(fn ($q) => self::qualityCheck($q))->all(),
            'deliveries' => $c->deliveries->map(fn ($d) => self::delivery($d))->all(),
            'invoice' => $money && $c->invoice ? self::invoice($c->invoice, $now) : null,
        ];
    }

    public static function notification(UserNotification $n): array
    {
        return [
            'id' => $n->id,
            'userId' => $n->user_id,
            'type' => $n->type,
            'title' => $n->title,
            'message' => $n->message,
            'caseId' => $n->case_id,
            'caseNumber' => $n->dentalCase?->case_number,
            'readAt' => self::iso($n->read_at),
            'createdAt' => self::iso($n->created_at),
        ];
    }

    public static function activity(object $a): array
    {
        return [
            'id' => $a->id,
            'userId' => $a->user_id ?? '',
            'userName' => $a->user_name,
            'action' => $a->action,
            'description' => $a->description,
            'subjectType' => $a->subject_type,
            'subjectId' => $a->subject_id,
            'subjectLabel' => $a->subject_label,
            'createdAt' => $a->created_at instanceof \DateTimeInterface ? self::iso($a->created_at) : Dates::iso(new \DateTimeImmutable($a->created_at, new \DateTimeZone('UTC'))),
        ];
    }

    /** SLA fields of a case row, in the shared-rules shape. */
    public static function slaFields(DentalCase $c): array
    {
        return [
            'status' => $c->status,
            'receivedAt' => self::iso($c->received_at),
            'dueAt' => self::iso($c->due_at),
            'deliveredAt' => self::iso($c->delivered_at),
            'submittedAt' => self::iso($c->submitted_at),
            'createdAt' => self::iso($c->created_at),
        ];
    }
}
