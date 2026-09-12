<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VeterinarianBatchAssignment extends Model
{
    use BelongsToCompany;

    protected $table = 'veterinarian_batch_assignments';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'veterinarian_id',
        'batch_id',
        'assigned_at',
        'unassigned_at',
        'assigned_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'veterinarian_id' => 'integer',
        'batch_id' => 'integer',
        'assigned_at' => 'date:Y-m-d',
        'unassigned_at' => 'date:Y-m-d',
        'assigned_by_user_id' => 'integer',
    ];

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(Veterinarian::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
