<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A storage place of a company (D8).
 */
class Warehouse extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'kind',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
