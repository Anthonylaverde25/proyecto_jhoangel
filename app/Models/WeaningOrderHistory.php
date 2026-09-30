<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeaningOrderHistory extends Model
{
    use BelongsToCompany;

    /**
     * @var string
     */
    protected $table = 'weaning_order_histories';

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'weaning_order_id',
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
        'weaning_order_id' => 'integer',
        'action_user_id' => 'integer',
        'action_metadata' => 'array',
    ];

    public function weaningOrder(): BelongsTo
    {
        return $this->belongsTo(WeaningOrder::class);
    }

    public function actionUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_user_id');
    }
}
