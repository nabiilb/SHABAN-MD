<?php

namespace Database\Seeders;

use App\Domain\Dates;
use App\Models\User;
use App\Support\CaseFiles;
use App\Support\Sequences;
use Database\Seeders\Support\SampleFiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Replaces ALL data with the demo lab: the same dataset the web app's mock mode
 * uses (database/data/demo.json, exported from packages/shared), with every
 * timestamp re-anchored to "now". Every demo account gets SEED_USER_PASSWORD.
 * Refused in production unless ALLOW_DEMO_SEED=true.
 */
class DemoSeeder extends Seeder
{
    /** Business tables, children first. */
    public const TABLES = [
        'activity_log', 'notifications', 'payments', 'invoices', 'deliveries', 'quality_issues', 'quality_checks',
        'case_attachments', 'case_assignments', 'case_status_history', 'case_notes', 'cases', 'lab_services', 'patients',
        'password_reset_tokens', 'sessions', 'technicians', 'users', 'doctors', 'clinics', 'role_permissions', 'roles',
        'permissions', 'settings', 'sequences',
    ];

    /** Tests pass a pre-computed hash so repeated resets skip hashing. */
    public static ?string $passwordHash = null;

    public static bool $writeFiles = true;

    public static ?int $now = null;

    private int $nowMs;

    public function run(): void
    {
        if (app()->isProduction() && env('ALLOW_DEMO_SEED') !== 'true') {
            throw new \RuntimeException('Refusing to load demo data in production (set ALLOW_DEMO_SEED=true to override).');
        }
        $hash = self::$passwordHash ?? Hash::make(SeedPassword::require('SEED_USER_PASSWORD'));
        $this->nowMs = self::$now ?? Dates::nowMs();
        $file = json_decode(file_get_contents(database_path('data/demo.json')), true, flags: JSON_THROW_ON_ERROR);
        $year = (string) Dates::labYear($this->nowMs);
        $d = $this->anchor($file['dataset'], (string) $file['numberYear'], $year);

        self::wipe();
        (new BaseSeeder)->run($d['settings']);

        DB::transaction(function () use ($d, $hash) {
            DB::table('role_permissions')->delete();
            DB::table('role_permissions')->insert(array_merge(...array_map(fn ($r) => array_map(fn ($k) => ['role_key' => $r['key'], 'permission_key' => $k], $r['permissions']), $d['roles'])));

            $this->insert('clinics', array_map(fn ($k) => ['id' => $k['id'], 'name' => $k['name'], 'contact_person' => $k['contactPerson'], 'phone' => $k['phone'], 'email' => $k['email'], 'address' => $k['address'], 'status' => $k['status'], 'notes' => $k['notes'] ?? '', 'created_at' => $k['createdAt'], 'updated_at' => $k['createdAt']], $d['clinics']));
            $this->insert('doctors', array_map(fn ($x) => ['id' => $x['id'], 'name' => $x['name'], 'clinic_id' => $x['clinicId'], 'phone' => $x['phone'], 'email' => $x['email'], 'specialty' => $x['specialty'], 'status' => $x['status'], 'created_at' => $x['createdAt'], 'updated_at' => $x['createdAt']], $d['doctors']));
            $this->insert('users', array_map(fn ($u) => ['id' => $u['id'], 'name' => $u['name'], 'email' => strtolower($u['email']), 'phone' => $u['phone'] ?? '', 'password' => $hash, 'role_key' => $u['role'], 'active' => $u['active'], 'clinic_id' => $u['clinicId'] ?? null, 'doctor_id' => $u['doctorId'] ?? null, 'created_at' => $u['createdAt'], 'updated_at' => $u['createdAt']], $d['users']));
            $this->insert('technicians', array_map(fn ($t) => ['id' => $t['id'], 'user_id' => $t['userId'] ?? null, 'name' => $t['name'], 'email' => $t['email'], 'phone' => $t['phone'], 'specialty' => $t['specialty'], 'active' => $t['active'], 'created_at' => $t['createdAt'], 'updated_at' => $t['createdAt']], $d['technicians']));
            $this->insert('patients', array_map(fn ($p) => ['id' => $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '', 'gender' => $p['gender'] ?? null, 'date_of_birth' => $p['dateOfBirth'] ?? null, 'clinic_id' => $p['clinicId'] ?? null, 'notes' => $p['notes'] ?? '', 'created_at' => $p['createdAt'], 'updated_at' => $p['createdAt']], $d['patients']));
            $this->insert('lab_services', array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'case_type' => $s['caseType'], 'unit_mode' => $s['unitMode'], 'unit_price' => $s['unitPrice'], 'default_material' => $s['defaultMaterial'], 'active' => $s['active']], $d['services']));

            $flags = $d['deadlineFlags'];
            $now = Dates::fromMs($this->nowMs)->format('Y-m-d H:i:s.v');
            $this->insert('cases', array_map(fn ($c) => [
                'id' => $c['id'], 'case_number' => $c['caseNumber'], 'patient_id' => $c['patientId'], 'doctor_id' => $c['doctorId'], 'clinic_id' => $c['clinicId'],
                'service_id' => $c['serviceId'], 'case_type' => $c['caseType'], 'restoration_type' => $c['restorationType'], 'material' => $c['material'], 'shade' => $c['shade'],
                'teeth' => json_encode($c['teeth']), 'denture_type' => $c['dentureType'] ?? null, 'units' => $c['units'], 'unit_price' => $c['unitPrice'],
                'emergency_fee' => $c['emergencyFee'], 'total' => $c['total'], 'priority' => $c['priority'], 'status' => $c['status'], 'technician_id' => $c['technicianId'] ?? null,
                'instructions' => $c['instructions'], 'rework_count' => $c['reworkCount'],
                'submitted_at' => $c['submittedAt'] ?? null, 'received_at' => $c['receivedAt'] ?? null, 'due_at' => $c['dueAt'] ?? null, 'assigned_at' => $c['assignedAt'] ?? null,
                'production_started_at' => $c['productionStartedAt'] ?? null, 'production_completed_at' => $c['productionCompletedAt'] ?? null, 'qc_completed_at' => $c['qcCompletedAt'] ?? null,
                'ready_at' => $c['readyAt'] ?? null, 'delivered_at' => $c['deliveredAt'] ?? null, 'completed_at' => $c['completedAt'] ?? null, 'cancelled_at' => $c['cancelledAt'] ?? null,
                'at_risk_notified_at' => ! empty($flags[$c['id']]['atRisk']) ? $now : null, 'overdue_notified_at' => ! empty($flags[$c['id']]['overdue']) ? $now : null,
                'created_by_id' => $c['createdById'], 'created_at' => $c['createdAt'], 'updated_at' => $c['updatedAt'],
            ], $d['cases']));
            $this->insert('case_notes', array_merge(...array_map(fn ($c) => array_map(fn ($n) => ['id' => $n['id'], 'case_id' => $c['id'], 'text' => $n['text'], 'author_id' => $n['authorId'], 'created_at' => $n['createdAt']], $c['notes']), $d['cases'])));
            $this->insert('case_status_history', array_map(fn ($h) => ['id' => $h['id'], 'case_id' => $h['caseId'], 'from_status' => $h['fromStatus'], 'to_status' => $h['toStatus'], 'user_id' => $h['userId'], 'user_role' => $h['userRole'], 'note' => $h['note'] ?? null, 'created_at' => $h['createdAt']], $d['history']));
            // Assignment log from the "→ assigned" history entries.
            $assignments = [];
            foreach ($d['cases'] as $i => $c) {
                if (empty($c['technicianId']) || empty($c['assignedAt'])) {
                    continue;
                }
                $h = collect($d['history'])->first(fn ($x) => $x['caseId'] === $c['id'] && $x['toStatus'] === 'assigned');
                $assignments[] = ['id' => 'asg_'.$c['id'], 'case_id' => $c['id'], 'technician_id' => $c['technicianId'], 'assigned_by_id' => $h['userId'] ?? $c['createdById'], 'note' => $h['note'] ?? null, 'assigned_at' => $c['assignedAt']];
            }
            $this->insert('case_assignments', $assignments);

            $png = self::$writeFiles ? SampleFiles::png() : null;
            $caseNumbers = array_column($d['cases'], 'caseNumber', 'id');
            $rows = [];
            foreach ($d['attachments'] as $a) {
                $key = CaseFiles::newKey($a['extension'], new \DateTimeImmutable($a['createdAt']));
                $size = $a['size'];
                if (self::$writeFiles) {
                    $content = match ($a['extension']) { 'pdf' => SampleFiles::prescriptionPdf($caseNumbers[$a['caseId']]), 'stl' => SampleFiles::stl($a['name']), default => $png };
                    CaseFiles::put($key, $content);
                    $size = strlen($content);
                }
                $rows[] = ['id' => $a['id'], 'case_id' => $a['caseId'], 'name' => $a['name'], 'storage_key' => $key, 'mime_type' => $a['mimeType'], 'extension' => $a['extension'], 'size' => $size, 'category' => $a['category'], 'uploaded_by_id' => $a['uploadedById'], 'created_at' => $a['createdAt']];
            }
            $this->insert('case_attachments', $rows);

            $this->insert('quality_checks', array_map(fn ($q) => ['id' => $q['id'], 'case_id' => $q['caseId'], 'result' => $q['result'], 'rework_required' => $q['reworkRequired'], 'notes' => $q['notes'], 'checked_by_id' => $q['checkedById'], 'checked_at' => $q['checkedAt']], $d['qualityChecks']));
            $this->insert('quality_issues', array_merge(...array_map(fn ($q) => array_map(fn ($issue) => ['id' => "qi_{$q['id']}_{$issue}", 'quality_check_id' => $q['id'], 'issue' => $issue], $q['issues']), $d['qualityChecks'])));
            $this->insert('deliveries', array_map(fn ($x) => [
                'id' => $x['id'], 'case_id' => $x['caseId'], 'status' => $x['status'], 'method' => $x['method'], 'courier_name' => $x['courierName'] ?? null, 'delivered_to' => $x['deliveredTo'] ?? null,
                'received_by' => $x['receivedBy'] ?? null, 'notes' => $x['notes'] ?? null, 'dispatched_at' => $x['dispatchedAt'] ?? null, 'delivered_at' => $x['deliveredAt'] ?? null,
                'recorded_by_id' => $x['recordedById'], 'created_at' => $x['createdAt'], 'updated_at' => $x['createdAt'],
            ], $d['deliveries']));

            $paid = [];
            foreach ($d['payments'] as $p) {
                $paid[$p['invoiceId']] = ($paid[$p['invoiceId']] ?? 0) + $p['amount'];
            }
            $this->insert('invoices', array_map(fn ($i) => [
                'id' => $i['id'], 'invoice_number' => $i['invoiceNumber'], 'case_id' => $i['caseId'], 'patient_id' => $i['patientId'], 'doctor_id' => $i['doctorId'], 'clinic_id' => $i['clinicId'],
                'subtotal' => $i['subtotal'], 'emergency_fee' => $i['emergencyFee'], 'discount' => $i['discount'], 'total' => $i['total'], 'amount_paid' => round($paid[$i['id']] ?? 0, 2),
                'issued_at' => $i['issuedAt'], 'due_date' => $i['dueDate'], 'created_at' => $i['issuedAt'], 'updated_at' => $i['issuedAt'],
            ], $d['invoices']));
            $this->insert('payments', array_map(fn ($p) => ['id' => $p['id'], 'invoice_id' => $p['invoiceId'], 'amount' => $p['amount'], 'method' => $p['method'], 'reference' => ($p['reference'] ?? '') !== '' ? $p['reference'] : null, 'notes' => $p['notes'] ?? '', 'received_by_id' => $p['receivedById'], 'paid_at' => $p['paidAt'], 'created_at' => $p['paidAt']], $d['payments']));
            $this->insert('notifications', array_map(fn ($n) => ['id' => $n['id'], 'user_id' => $n['userId'], 'type' => $n['type'], 'title' => $n['title'], 'message' => $n['message'], 'case_id' => $n['caseId'] ?? null, 'read_at' => $n['readAt'] ?? null, 'created_at' => $n['createdAt']], $d['notifications']));

            foreach ([Sequences::CASE => 'case', Sequences::INVOICE => 'invoice', Sequences::PATIENT => 'patient'] as $name => $k) {
                DB::table('sequences')->where('name', $name)->update(['value' => $d['counters'][$k]]);
            }
        });

        $this->command?->info(sprintf('Demo data loaded: %d users, %d cases, %d invoices, %d files.', count($d['users']), count($d['cases']), count($d['invoices']), count($d['attachments'])));
    }

    /** Empties every business table (and the files) — demo and tests only. */
    public static function wipe(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::TABLES as $t) {
                DB::table($t)->truncate();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        if (self::$writeFiles) {
            CaseFiles::clear();
        }
    }

    /** {at: offset} → "Y-m-d H:i:s.v" (UTC) at now + offset; case/invoice numbers take the current lab year. */
    private function anchor(mixed $v, string $fromYear, string $toYear): mixed
    {
        if (is_array($v)) {
            if (array_keys($v) === ['at']) {
                return Dates::fromMs($this->nowMs + (int) $v['at'])->format('Y-m-d H:i:s.v');
            }

            return array_map(fn ($x) => $this->anchor($x, $fromYear, $toYear), $v);
        }
        if (is_string($v) && $fromYear !== $toYear) {
            return preg_replace('/\b(DL|INV)-'.$fromYear.'-/', '$1-'.$toYear.'-', $v);
        }

        return $v;
    }

    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
