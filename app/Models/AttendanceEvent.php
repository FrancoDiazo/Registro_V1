<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AttendanceEvent extends Model
{
    protected $table = 'marcaciones_asistencia';

    protected $fillable = [
        'event_uuid',
        'employee_id',
        'event_type',
        'occurred_at_device',
        'device_offset_minutes',
        'client_timezone',
        'received_at_server',
        'device_id',
        'device_event_seq',
        'offline',
    ];

    public $timestamps = true;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Las marcas originales son inmutables.'));
        static::deleting(fn (): never => throw new LogicException('Las marcas originales no se pueden eliminar.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at_device' => 'immutable_datetime',
            'received_at_server' => 'immutable_datetime',
            'offline' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(AttendanceIncident::class);
    }

}
