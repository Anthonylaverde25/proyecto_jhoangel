<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Veterinarian extends Model
{
    use BelongsToCompany;

    protected $table = 'veterinarians';

    /** @var string[] */
    protected $fillable = [
        'company_id',
        'user_id',
        'cuit',
        'billing_cuit',
        'name',
        'license_number',
        'accreditation_code',
        'phone',
        'email',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'company_id' => 'integer',
        'user_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }


    public function batchAssignments(): HasMany
    {
        return $this->hasMany(VeterinarianBatchAssignment::class);
    }

    public function protocols(): HasMany
    {
        return $this->hasMany(DiagnosticProtocol::class);
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(VeterinaryPortalAccessToken::class);
    }
}
