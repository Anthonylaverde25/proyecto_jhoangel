<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\FarmMapper;
use App\Application\Mappers\PartnerMapper;
use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use App\Core\Interfaces\IPartnerRepository;
use App\Core\ValueObjects\Cuit;
use App\Models\Partner;
use App\Models\PartnerContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentPartnerRepository implements IPartnerRepository
{
    private const RELATIONS = ['farms', 'roles', 'contacts'];

    public function findAll(?array $roles = null): array
    {
        $codes = $roles === null ? null : array_map(fn (PartnerRole $role) => $role->value, $roles);

        return Partner::with(self::RELATIONS)
            ->withExists('fiscalDocuments')
            ->when($codes !== null, fn (Builder $query) => $query->whereHas(
                'roles',
                fn (Builder $roleQuery) => $roleQuery->whereIn('role', $codes)
            ))
            ->get()
            ->map(fn (Partner $model) => PartnerMapper::toEntity($model))
            ->all();
    }

    public function findById(int $id): ?PartnerEntity
    {
        $model = Partner::with(self::RELATIONS)->withExists('fiscalDocuments')->find($id);

        return $model ? PartnerMapper::toEntity($model) : null;
    }

    public function findByCuit(string $cuit, int $companyId): ?PartnerEntity
    {
        // Explicit company filter: this may run outside a request, without company context.
        $model = Partner::withoutGlobalScopes()
            ->with(self::RELATIONS)
            ->where('company_id', $companyId)
            ->where('cuit', Cuit::fromString($cuit)->value())
            ->first();

        return $model ? PartnerMapper::toEntity($model) : null;
    }

    public function save(PartnerEntity $partner): PartnerEntity
    {
        return DB::transaction(function () use ($partner) {
            $model = $partner->getId() !== null ? Partner::find($partner->getId()) : null;
            $model = PartnerMapper::toModel($partner, $model);
            $model->save();
            $model->syncRoles($partner->getRoles());

            foreach ($partner->getFarms() as $farmEntity) {
                $farmModel = FarmMapper::toModel($farmEntity);
                $farmModel->provider_id = $model->id;
                // The farm belongs to the partner's company, never to whatever context is active.
                $farmModel->company_id = $model->company_id;
                $farmModel->save();
            }

            foreach ($partner->getContacts() as $contact) {
                if ($contact->getId() === null) {
                    $model->contacts()->create(
                        ['company_id' => $model->company_id] + PartnerMapper::contactToAttributes($contact)
                    );
                }
            }

            return PartnerMapper::toEntity($model->fresh(self::RELATIONS)->loadExists('fiscalDocuments'));
        });
    }

    public function updateRoles(PartnerEntity $partner): void
    {
        DB::transaction(fn () => $this->model($partner)->syncRoles($partner->getRoles()));
    }

    public function updateDetails(PartnerEntity $partner): void
    {
        $this->model($partner)
            ->fill([
                'name' => $partner->getName(),
                'commercial_name' => $partner->getCommercialName(),
                'cuit' => $partner->getCuit(),
                'location' => $partner->getLocation(),
            ])
            ->save();
    }

    public function saveContact(PartnerEntity $partner, PartnerContactEntity $contact): PartnerContactEntity
    {
        $attributes = PartnerMapper::contactToAttributes($contact);

        $model = $contact->getId() === null
            ? PartnerContact::create(['company_id' => $partner->getCompanyId(), 'partner_id' => $partner->getId()] + $attributes)
            : tap($this->contactModel($partner, $contact->getId()))->update($attributes);

        return PartnerMapper::contactToEntity($model);
    }

    public function setDefaultContact(PartnerEntity $partner, int $contactId): void
    {
        DB::transaction(function () use ($partner, $contactId): void {
            // Clear first: uq_pc_one_default rejects two defaults even for an instant.
            $this->contacts($partner)->where('is_default', true)->update(['is_default' => false]);
            $this->contacts($partner)->whereKey($contactId)->update(['is_default' => true]);
        });
    }

    public function deleteContact(PartnerEntity $partner, int $contactId): void
    {
        $this->contacts($partner)->whereKey($contactId)->delete();
    }

    private function model(PartnerEntity $partner): Partner
    {
        return Partner::withoutGlobalScopes()
            ->where('company_id', $partner->getCompanyId())
            ->whereKey($partner->getId())
            ->firstOrFail();
    }

    /** @return Builder<PartnerContact> */
    private function contacts(PartnerEntity $partner): Builder
    {
        return PartnerContact::query()
            ->where('company_id', $partner->getCompanyId())
            ->where('partner_id', $partner->getId());
    }

    private function contactModel(PartnerEntity $partner, int $contactId): PartnerContact
    {
        return $this->contacts($partner)->whereKey($contactId)->firstOrFail();
    }
}
