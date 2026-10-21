<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Why an animal died: a catalog per company.
 */
class DeathCause extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
