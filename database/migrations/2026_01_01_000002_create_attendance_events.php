<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 16);
            $table->timestampTz('occurred_at_device');
            $table->smallInteger('device_offset_minutes')->default(0);
            $table->string('client_timezone', 64);
            $table->timestampTz('received_at_server');
            $table->uuid('device_id')->nullable();
            $table->unsignedBigInteger('device_event_seq')->nullable();
            $table->boolean('offline')->default(false);
            $table->timestampsTz();
            $table->index(['employee_id', 'occurred_at_device']);
            $table->index(['employee_id', 'received_at_server']);
            $table->index(['employee_id', 'device_id', 'device_event_seq'], 'attendance_device_seq_index');
        });

        Schema::create('attendance_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 48);
            $table->text('description');
            $table->string('status', 16)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('correction_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('requested_event_type', 16)->nullable();
            $table->timestampTz('requested_occurred_at')->nullable();
            $table->string('requested_timezone', 64)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 128)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('correction_requests');
        Schema::dropIfExists('attendance_incidents');
        Schema::dropIfExists('attendance_events');
    }
};
