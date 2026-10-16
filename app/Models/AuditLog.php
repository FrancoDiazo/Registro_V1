<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'registros_auditoria';

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
