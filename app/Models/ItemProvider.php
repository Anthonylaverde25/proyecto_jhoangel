<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemProvider extends Model
{
    use BelongsToCompany;

    protected $table = 'item_provider';

    protected $fillable = [
        'company_id',
        'item_id',
        'provider_id',
        'lead_time_days',
        'is_preferred',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'item_id' => 'integer',
        'provider_id' => 'integer',
        'lead_time_days' => 'integer',
        'is_preferred' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class)->withoutGlobalScopes();
    }
}
