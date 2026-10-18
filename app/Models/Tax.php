<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixed catalog of taxes (DS15). Read-only.
 */
class Tax extends Model
{
    /** Written only by migrations. */
    protected $guarded = ['*'];

    protected $casts = [
        'is_recoverable' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('display_order');
    }
}
