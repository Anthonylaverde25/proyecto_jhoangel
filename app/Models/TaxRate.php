<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legal rate of a tax, in force between two dates. Read-only.
 */
class TaxRate extends Model
{
    /** Written only by migrations. */
    protected $guarded = ['*'];

    protected $casts = [
        'tax_id' => 'integer',
        'display_order' => 'integer',
    ];
}
