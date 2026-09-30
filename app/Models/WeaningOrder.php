<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeaningOrder extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'code',
        'status',
        'kind',
        'destination_mode',
        'category_mode',
        'destination_activity_id',
        'weaning_type',
        'planned_head_count',
        'weaning_date',
        'requested_by_user_id',
        'emitted_at',
        'printed_at',
        'first_executed_at',
        'closed_at',
        'responsable',
        'observations',
        'closing_reason',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'destination_activity_id' => 'integer',
        'planned_head_count' => 'integer',
        'requested_by_user_id' => 'integer',
        'weaning_date' => 'date:Y-m-d',
        'emitted_at' => 'datetime',
        'printed_at' => 'datetime',
        'first_executed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function destinationActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'destination_activity_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(WeaningOrderDestination::class);
    }

    public function animals(): HasMany
    {
        return $this->hasMany(WeaningOrderAnimal::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(WeaningOrderHistory::class);
    }
}
