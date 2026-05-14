<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'type_id',
        'title',
        'description',
        'schema_definition',
        'status',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'type_id' => 'integer',
        'schema_definition' => 'array',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(TemplateType::class, 'type_id');
    }
}
