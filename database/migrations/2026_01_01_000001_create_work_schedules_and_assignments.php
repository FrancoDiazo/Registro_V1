<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('America/Santiago');
            $table->unsignedSmallInteger('expected_minutes_per_week')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('break_is_unpaid')->default(false);
            $table->json('days_of_week')->nullable();
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_schedule_id')->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestampsTz();
            $table->index(['employee_id', 'valid_from', 'valid_until']);
        });

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('schedule_assignments');
        Schema::dropIfExists('work_schedules');
    }
};
