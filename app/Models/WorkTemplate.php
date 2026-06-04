<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'category',
        'title',
        'description',
        'schema_definition',
        'status',
        'code',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'schema_definition' => 'array',
    ];
}
