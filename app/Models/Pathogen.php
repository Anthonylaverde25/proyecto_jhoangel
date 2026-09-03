<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pathogen extends Model
{
    /**
     * @var string[]
     */
    protected $fillable = [
        'code',
        'name',
        'category',
        'is_disqualifying',
        'description',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_disqualifying' => 'boolean',
    ];

    public function diagnoses(): HasMany
    {
        return $this->hasMany(VeterinaryDiagnosis::class);
    }
}
