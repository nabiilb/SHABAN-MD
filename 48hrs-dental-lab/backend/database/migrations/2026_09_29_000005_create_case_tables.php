<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dental cases and everything that happens to them: notes, status history, assignments, files, QC, deliveries. */
return new class extends Migration
{
    /** In workflow order: MySQL sorts ENUM columns by this order, so ORDER BY status follows the production line. */
    public const STATUSES = ['submitted', 'correction', 'received', 'review', 'assigned', 'in_production', 'rework', 'quality_control', 'ready', 'out_for_delivery', 'delivered', 'completed', 'cancelled', 'rejected'];

    public function up(): void
    {
        Schema::create('cases', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            // Display number, e.g. DL-2026-00126.
            $t->string('case_number', 30)->unique();
            $t->string('patient_id', 40);
            $t->string('doctor_id', 40);
            $t->string('clinic_id', 40);
            $t->string('service_id', 40);
            $t->enum('case_type', ['crown', 'bridge', 'veneer', 'implant', 'denture', 'appliance']);
            $t->string('restoration_type', 160);
            $t->string('material', 120);
            $t->string('shade', 20);
            // Universal numbering 1–32, as a JSON array.
            $t->json('teeth');
            $t->enum('denture_type', ['full_upper', 'full_lower', 'upper_lower', 'partial'])->nullable();
            $t->unsignedInteger('units');
            $t->decimal('unit_price', 12, 2);
            $t->decimal('emergency_fee', 12, 2);
            $t->decimal('total', 12, 2);
            // By severity: ORDER BY priority sorts normal → urgent.
            $t->enum('priority', ['normal', 'high', 'urgent'])->default('normal');
            $t->enum('status', self::STATUSES);
            $t->string('technician_id', 40)->nullable();
            $t->text('instructions')->nullable();
            $t->unsignedInteger('rework_count')->default(0);

            $t->dateTime('submitted_at', 3)->nullable();
            // When the lab accepted the case — the SLA clock starts here (server time).
            $t->dateTime('received_at', 3)->nullable();
            // received_at + SLA hours.
            $t->dateTime('due_at', 3)->nullable();
            $t->dateTime('assigned_at', 3)->nullable();
            $t->dateTime('production_started_at', 3)->nullable();
            $t->dateTime('production_completed_at', 3)->nullable();
            $t->dateTime('qc_completed_at', 3)->nullable();
            $t->dateTime('ready_at', 3)->nullable();
            $t->dateTime('delivered_at', 3)->nullable();
            $t->dateTime('completed_at', 3)->nullable();
            $t->dateTime('cancelled_at', 3)->nullable();
            // Set by the deadline scan so each alert is raised once per case.
            $t->dateTime('at_risk_notified_at', 3)->nullable();
            $t->dateTime('overdue_notified_at', 3)->nullable();

            $t->string('created_by_id', 40);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();

            $t->index('patient_id');
            $t->index('doctor_id');
            $t->index('clinic_id');
            $t->index('technician_id');
            $t->index('status');
            $t->index('priority');
            $t->index('due_at');
            $t->index('received_at');
            $t->index('created_at');
            $t->index(['status', 'due_at']);
            $t->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $t->foreign('doctor_id')->references('id')->on('doctors')->restrictOnDelete();
            $t->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
            $t->foreign('service_id')->references('id')->on('lab_services')->restrictOnDelete();
            $t->foreign('technician_id')->references('id')->on('technicians')->restrictOnDelete();
            $t->foreign('created_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('case_notes', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            $t->text('text');
            $t->string('author_id', 40);
            $t->dateTime('created_at', 3)->nullable();
            $t->index(['case_id', 'created_at']);
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
        });

        // Every status transition, with who made it and why.
        Schema::create('case_status_history', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            $t->enum('from_status', self::STATUSES)->nullable();
            $t->enum('to_status', self::STATUSES);
            $t->string('user_id', 40);
            // Role at the time of the change (roles can change later).
            $t->string('user_role', 40);
            $t->text('note')->nullable();
            $t->dateTime('created_at', 3)->nullable();
            $t->index(['case_id', 'created_at']);
            $t->index('user_id');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // Technician assignments over time (a reassignment closes the previous row).
        Schema::create('case_assignments', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            $t->string('technician_id', 40);
            $t->string('assigned_by_id', 40);
            $t->text('note')->nullable();
            $t->dateTime('assigned_at', 3);
            $t->dateTime('unassigned_at', 3)->nullable();
            $t->index('case_id');
            $t->index('technician_id');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('technician_id')->references('id')->on('technicians')->restrictOnDelete();
            $t->foreign('assigned_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        // File metadata; the bytes live on the private "cases" disk under an opaque storage key.
        Schema::create('case_attachments', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            // Original file name, for display and download only.
            $t->string('name', 200);
            // Random server-side key; never derived from user input and never exposed.
            $t->string('storage_key', 120)->unique();
            $t->string('mime_type', 120);
            $t->string('extension', 10);
            $t->unsignedBigInteger('size');
            $t->enum('category', ['photo', 'scan', 'xray', 'prescription', 'document', 'production', 'qc']);
            $t->string('uploaded_by_id', 40);
            $t->dateTime('created_at', 3)->nullable();
            $t->index('case_id');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('uploaded_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('quality_checks', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            $t->enum('result', ['passed', 'failed']);
            $t->boolean('rework_required');
            $t->text('notes')->nullable();
            $t->string('checked_by_id', 40);
            $t->dateTime('checked_at', 3);
            $t->index('case_id');
            $t->index('checked_at');
            $t->index('result');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('checked_by_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('quality_issues', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('quality_check_id', 40);
            $t->enum('issue', ['fit', 'margins', 'occlusion', 'contacts', 'shade', 'contour', 'finish', 'other']);
            $t->unique(['quality_check_id', 'issue']);
            $t->foreign('quality_check_id')->references('id')->on('quality_checks')->cascadeOnDelete();
        });

        Schema::create('deliveries', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('case_id', 40);
            $t->enum('status', ['ready', 'out_for_delivery', 'delivered'])->default('ready');
            $t->enum('method', ['clinic_pickup', 'lab_courier', 'third_party']);
            $t->string('courier_name', 120)->nullable();
            $t->string('delivered_to', 160)->nullable();
            $t->string('received_by', 120)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('dispatched_at', 3)->nullable();
            $t->dateTime('delivered_at', 3)->nullable();
            // Who last recorded a step (dispatch / hand-over): "delivered by".
            $t->string('recorded_by_id', 40);
            $t->dateTime('created_at', 3)->nullable();
            $t->dateTime('updated_at', 3)->nullable();
            $t->index('case_id');
            $t->index('status');
            $t->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();
            $t->foreign('recorded_by_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['deliveries', 'quality_issues', 'quality_checks', 'case_attachments', 'case_assignments', 'case_status_history', 'case_notes', 'cases'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
