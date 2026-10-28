<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** A DTE covering the trip of an exit (number, date, head). */
class ExitOrderDte extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'exit_order_id',
        'dte_number',
        'dte_date',
        'head_count',
        'loaded_by_user_id',
        'observations',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'exit_order_id' => 'integer',
        'dte_date' => 'date:Y-m-d',
        'head_count' => 'integer',
        'loaded_by_user_id' => 'integer',
    ];
}
