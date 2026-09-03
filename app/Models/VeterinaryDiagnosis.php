<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VeterinaryDiagnosis extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'caravan_id',
        'pathogen_id',
        'veterinarian_id',
        'diagnosis_date',
        'status',
        'resolution_date',
        'treatment_notes',
        'source_context',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'pathogen_id' => 'integer',
        'veterinarian_id' => 'integer',
        'diagnosis_date' => 'date:Y-m-d',
        'resolution_date' => 'date:Y-m-d',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function pathogen(): BelongsTo
    {
        return $this->belongsTo(Pathogen::class);
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'veterinarian_id');
    }
}
