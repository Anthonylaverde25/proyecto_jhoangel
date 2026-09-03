<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BullLabSample extends Model
{
    use BelongsToCompany;

    protected $table = 'bull_lab_samples';

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'caravan_id',
        'evaluation_id',
        'sample_type',
        'sample_round',
        'sample_date',
        'tube_number',
        'status',
        'protocol_number',
        'result_date',
        'pathogen_id',
        'notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'caravan_id' => 'integer',
        'evaluation_id' => 'integer',
        'sample_round' => 'integer',
        'sample_date' => 'date:Y-m-d',
        'result_date' => 'date:Y-m-d',
        'pathogen_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function caravan(): BelongsTo
    {
        return $this->belongsTo(Caravan::class);
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(BullHealthEvaluation::class, 'evaluation_id');
    }

    public function pathogen(): BelongsTo
    {
        return $this->belongsTo(Pathogen::class);
    }
}
