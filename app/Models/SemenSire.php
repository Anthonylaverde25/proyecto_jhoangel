<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SemenSire extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'registry_code',
        'breed_id',
        'stud_name',
        'caravan_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'breed_id' => 'integer',
        'caravan_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function breed(): BelongsTo
    {
        return $this->belongsTo(Breed::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }
}
