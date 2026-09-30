<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaravanRegistrationSubmission extends Model
{
    protected $fillable = [
        'company_id',
        'submission_id',
        'registered_count',
        'registered',
    ];

    protected $casts = [
        'registered' => 'array',
        'registered_count' => 'integer',
    ];
}
