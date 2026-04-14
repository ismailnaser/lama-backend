<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientAuditLog extends Model
{
    protected $fillable = [
        'patient_id',
        'user_id',
        'username',
        'action',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];
}

