<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_confirmation_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_event_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_confirmation_outbox');
    }
};
