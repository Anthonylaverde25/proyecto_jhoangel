<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryOrderCategory extends Model
{
    use BelongsToCompany;

    /**
     * @var string[]
     */
    protected $fillable = [
        'company_id',
        'entry_order_id',
        'category_id',
        'head_count',
        'position',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'entry_order_id' => 'integer',
        'category_id' => 'integer',
        'head_count' => 'integer',
        'position' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnimalCategory::class, 'category_id');
    }
}
