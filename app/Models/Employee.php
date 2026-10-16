<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $table = 'trabajadores';

    protected $fillable = ['user_id', 'employee_number', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function attendanceIncidents(): HasMany
    {
        return $this->hasMany(AttendanceIncident::class);
    }

    public function correctionRequests(): HasMany
    {
        return $this->hasMany(CorrectionRequest::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }
}
