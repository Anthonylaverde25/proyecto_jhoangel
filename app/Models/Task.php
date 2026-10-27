<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A to-do of a company: text, urgency and the providers it concerns.
 */
class Task extends Model
{
    use BelongsToCompany;

    protected $table = 'tasks';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'description',
        'urgency',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];

    /** The pivot carries company_id: write it on attach (composite foreign keys). */
    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class, 'task_provider')
            ->withPivot('company_id')
            ->withTimestamps();
    }
}
