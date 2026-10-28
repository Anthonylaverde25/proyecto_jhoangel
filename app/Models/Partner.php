<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\PartnerRole;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A supplier, a customer or both (egreso plan v5, 02). The table was `providers`; the columns that
 * point here keep the name of the role they play in their document (`provider_id`, C5).
 */
class Partner extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'commercial_name',
        'cuit',
        'location',
        'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function roles(): HasMany
    {
        return $this->hasMany(PartnerRoleAssignment::class);
    }

    /** The default one first. */
    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class)->orderByDesc('is_default')->orderBy('id');
    }

    public function defaultContact(): HasOne
    {
        return $this->hasOne(PartnerContact::class)->where('is_default', true);
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class, 'provider_id');
    }

    /** Invoices, delivery notes and credit notes it issued, voided ones included (lock the CUIT). */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class, 'provider_id');
    }

    /**
     * Leaves the partner with exactly these roles (one row each).
     *
     * @param list<PartnerRole> $roles
     */
    public function syncRoles(array $roles): void
    {
        $codes = array_values(array_unique(array_map(fn (PartnerRole $role) => $role->value, $roles)));

        $this->roles()->whereNotIn('role', $codes)->delete();
        $existing = $this->roles()->pluck('role')->map(fn ($role) => $role instanceof PartnerRole ? $role->value : (string) $role)->all();

        foreach (array_diff($codes, $existing) as $code) {
            $this->roles()->create(['company_id' => $this->company_id, 'role' => $code]);
        }

        $this->unsetRelation('roles');
    }

    /** @return list<PartnerRole> */
    public function roleList(): array
    {
        return $this->roles->map(fn (PartnerRoleAssignment $row) => $row->role)->values()->all();
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
