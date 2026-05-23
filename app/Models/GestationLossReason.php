<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GestationLossReason extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'company_id' => 'integer',
    ];

    /**
     * Get gestations associated with this loss reason.
     *
     * @return HasMany
     */
    public function gestations(): HasMany
    {
        return $this->hasMany(CaravanGestation::class, 'loss_reason_id');
    }
}
