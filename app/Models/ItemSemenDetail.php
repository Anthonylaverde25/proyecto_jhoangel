<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSemenDetail extends Model
{
    use BelongsToCompany;

    protected $primaryKey = 'item_id';
    public $incrementing = false;

    protected $fillable = [
        'item_id',
        'company_id',
        'semen_sire_id',
        'semen_type',
        'preservation',
        'straw_size',
        'straw_color',
        'cap_color',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'company_id' => 'integer',
        'semen_sire_id' => 'integer',
    ];

    public function sire(): BelongsTo
    {
        return $this->belongsTo(SemenSire::class, 'semen_sire_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
