<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Effect of an operation line on a warehouse; the id is the kardex order (D4).
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'warehouse_id',
        'item_document_line_id',
        'item_id',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'average_cost_after',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'warehouse_id' => 'integer',
        'item_document_line_id' => 'integer',
        'item_id' => 'integer',
        'direction' => 'integer',
    ];
}
