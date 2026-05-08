<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Core\Interfaces\ICompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        // Aplicar el Scope de forma global si existe un contexto en la petición
        static::addGlobalScope('company', function (Builder $builder) {
            $context = app(ICompanyContext::class);
            if ($context->hasCompanyContext()) {
                $builder->where('company_id', $context->getCompanyId());
            }
        });

        // Autocompletar el company_id al momento de crear un registro
        static::creating(function (Model $model) {
            $context = app(ICompanyContext::class);
            if ($context->hasCompanyContext() && empty($model->company_id)) {
                $model->company_id = $context->getCompanyId();
            }
        });
    }
}
