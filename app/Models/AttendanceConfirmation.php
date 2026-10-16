<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceConfirmation extends Model
{
    protected $table = 'bandeja_salida_confirmaciones_asistencia';

    protected $fillable = [
        'attendance_event_id',
        'status',
        'attempt_count',
        'last_attempt_at',
        'sent_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function attendanceEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }
}
