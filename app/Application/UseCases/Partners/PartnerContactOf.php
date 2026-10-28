<?php

declare(strict_types=1);

namespace App\Application\UseCases\Partners;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** A contact the request names, only when it is one of that partner's: any other is a 404. */
final class PartnerContactOf
{
    public static function require(PartnerEntity $partner, int $contactId): PartnerContactEntity
    {
        return $partner->findContact($contactId)
            ?? throw new ModelNotFoundException("Contact {$contactId} not found.");
    }
}
