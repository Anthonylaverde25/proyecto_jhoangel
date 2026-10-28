<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\PartnerRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role a partner holds (a row of partner_roles). Named apart from the PartnerRole enum it casts to.
 */
class PartnerRoleAssignment extends Model
{
    protected $table = 'partner_roles';

    protected $fillable = [
        'company_id',
        'partner_id',
        'role',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'partner_id' => 'integer',
        'role' => PartnerRole::class,
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withoutGlobalScopes();
    }
}
