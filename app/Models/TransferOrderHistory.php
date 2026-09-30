<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferOrderHistory extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    protected $table = 'transfer_order_histories';

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'transfer_order_id',
        'from_status',
        'to_status',
        'action_user_id',
        'action_reason',
        'action_metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'transfer_order_id' => 'integer',
        'action_user_id' => 'integer',
        'action_metadata' => 'array',
    ];

    public function transferOrder(): BelongsTo
    {
        return $this->belongsTo(TransferOrder::class);
    }

    public function actionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_user_id');
    }
}
