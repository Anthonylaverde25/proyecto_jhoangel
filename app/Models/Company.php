<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'renspa',
        'cuit',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Digits only, whoever writes it: it is compared against the CUITs printed on documents. */
    protected function cuit(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null || trim($value) === '' ? null : preg_replace('/\D/', '', $value),
        );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function batchTypes(): BelongsToMany
    {
        return $this->belongsToMany(BatchType::class, 'company_batch_type')
            ->withPivot(['is_enabled', 'custom_name', 'custom_color'])
            ->withTimestamps();
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(Activity::class, 'company_activity')
            ->withPivot(['is_enabled', 'is_initial', 'is_final', 'sort_order'])
            ->withTimestamps();
    }
}
