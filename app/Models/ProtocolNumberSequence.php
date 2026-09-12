<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProtocolNumberSequence extends Model
{
    use BelongsToCompany;

    protected $table = 'protocol_number_sequences';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'series',
        'period_year',
        'last_number',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'period_year' => 'integer',
        'last_number' => 'integer',
    ];
}
