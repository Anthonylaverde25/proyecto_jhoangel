<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaravanCastration extends Model
{
    use BelongsToCompany;

    protected $table = 'caravan_castrations';

    protected $fillable = [
        'company_id',
        'caravan_id',
        'castrated_on',
        'method',
        'notes',
        'from_category_id',
        'registered_by_user_id',
        'voided_at',
        'voided_by_user_id',
        'void_reason',
    ];

    protected $casts = [
        'castrated_on' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class, 'caravan_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'from_category_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}

