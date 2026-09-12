<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-30 / ADR-36: one dispatch of tubes, declared by the professional who made it.
 *
 * It belongs to the establishment, not to an act: one cooler is one row even when it carries
 * tubes from several chute sessions.
 */
class SampleShipment extends Model
{
    use BelongsToCompany;

    protected $table = 'sample_shipments';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'shipped_on',
        'institution',
        'cold_chain_ok',
        'condition_notes',
        'declared_by_veterinarian_id',
        'declared_by_name',
        'declared_by_user_id',
        'declared_at',
        'voided_at',
        'void_reason',
        'voided_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'shipped_on' => 'date:Y-m-d',
        'institution' => 'array',
        'cold_chain_ok' => 'boolean',
        'declared_by_veterinarian_id' => 'integer',
        'declared_by_user_id' => 'integer',
        'declared_at' => 'datetime',
        'voided_at' => 'datetime',
        'voided_by_user_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Which tubes travelled in this box. They may come from more than one act (ADR-36). */
    public function samples(): HasMany
    {
        return $this->hasMany(BullLabSample::class, 'sample_shipment_id');
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class, 'declared_by_veterinarian_id');
    }
}
