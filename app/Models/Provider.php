<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'commercial_name',
        'cuit',
        'location',
        'email',
        'phone',
        'is_active',
        'supplies_livestock',
        'supplies_items',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'supplies_livestock' => 'boolean',
        'supplies_items' => 'boolean',
    ];

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }

    /** Invoices, delivery notes and credit notes it issued, voided ones included (lock the CUIT). */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Digits only, whoever writes it (use case, seeder, test): the per-company unique index has to
     * compare the number, not the way it was typed.
     */
    protected function cuit(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : (preg_replace('/\D/', '', $value) ?? ''),
        );
    }
}
