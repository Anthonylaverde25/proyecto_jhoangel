<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyActivity extends Model
{
    protected $table = 'company_activity';

    protected $fillable = ['company_id', 'activity_id', 'is_enabled'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
