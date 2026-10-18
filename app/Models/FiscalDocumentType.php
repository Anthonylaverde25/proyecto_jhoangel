<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed catalog of provider document types (DS15). Read-only: no company scope, no writes from the
 * application.
 */
class FiscalDocumentType extends Model
{
    protected $table = 'fiscal_document_types';

    /** Written only by migrations. */
    protected $guarded = ['*'];

    protected $casts = [
        'discriminates_vat' => 'boolean',
        'carries_amounts' => 'boolean',
        'sign' => 'integer',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];
}
