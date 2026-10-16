<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceIncident extends Model
{
    protected $table = 'incidencias_asistencia';

    protected $fillable = [
        'employee_id',
        'attendance_event_id',
        'type',
        'description',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }
}
