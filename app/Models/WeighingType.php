<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Enums\WeighingTypeCode;
use Illuminate\Database\Eloquent\Model;

class WeighingType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'affects_adpv',
        'default_method',
        'default_shrunk_condition',
        'display_order',
        'is_system',
        'is_active',
    ];

    protected $casts = [
        'affects_adpv' => 'boolean',
        'display_order' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Catalog row of a code, for fixtures and seeders that write weights directly. */
    public static function idOf(WeighingTypeCode $code): int
    {
        return (int) static::where('code', $code->value)->value('id');
    }
}
