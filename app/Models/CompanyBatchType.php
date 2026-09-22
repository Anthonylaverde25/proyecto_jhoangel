<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBatchType extends Model
{
    protected $table = 'company_batch_type';

    protected $fillable = [
        'company_id',
        'batch_type_id',
        'is_enabled',
        'custom_name',
        'custom_color',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function batchType(): BelongsTo
    {
        return $this->belongsTo(BatchType::class);
    }
}
