<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Entities\VeterinarianEntity;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\ValueObjects\Cuit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IVeterinarianRepository;

/**
 * Catalogue aggregator for the veterinarians directory, including the quick-create dialog
 * used while filling a protocol without losing the form.
 */
final class ManageVeterinariansUseCase
{
    public function __construct(
        private readonly IVeterinarianRepository $repository,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * @return array<VeterinarianEntity>
     */
    public function list(bool $activeOnly = true): array
    {
        return $this->repository->findAll($this->companyId(), $activeOnly);
    }

    /**
     * Creating a professional creates their way in.
     *
     * The producer loads the file once and the veterinarian can already enter: no second step,
     * no invitation to chase. The account is born with the generic password from configuration,
     * which the producer hands over and the professional changes from their profile if they want.
     *
     * Two things this deliberately does NOT do: it never touches an existing account's password
     * — somebody already working for another producer keeps their own — and it creates nothing
     * when there is no email, because an account with no address is an account nobody can reach.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): VeterinarianEntity
    {
        $companyId = $this->companyId();
        $licenseNumber = trim((string) ($data['license_number'] ?? ''));

        // F9: the same professional must never fragment into two catalogue rows.
        $existing = $this->repository->findByLicenseNumber($licenseNumber, $companyId);

        if ($existing !== null) {
            throw VeterinaryDomainException::domainError(
                "Ya existe un profesional con matrícula {$licenseNumber} (ID {$existing->getId()})."
            );
        }

        $veterinarian = $this->repository->save(new VeterinarianEntity(
            id: null,
            companyId: $companyId,
            name: trim((string) ($data['name'] ?? '')),
            licenseNumber: $licenseNumber,
            userId: isset($data['user_id']) && $data['user_id'] !== null ? (int) $data['user_id'] : null,
            cuit: self::normaliseCuit($data['cuit'] ?? null),
            billingCuit: self::normaliseCuit($data['billing_cuit'] ?? null),
            accreditationCode: isset($data['accreditation_code']) ? (string) $data['accreditation_code'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            isActive: (bool) ($data['is_active'] ?? true)
        ));

        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '' || ($data['user_id'] ?? null) !== null) {
            return $veterinarian;
        }

        return $this->provisionPortalAccount($veterinarian, $email, $companyId);
    }

    /**
     * Links the professional to an account, creating it only when the address is new.
     */
    private function provisionPortalAccount(
        VeterinarianEntity $veterinarian,
        string $email,
        int $companyId
    ): VeterinarianEntity {
        return DB::transaction(function () use ($veterinarian, $email, $companyId): VeterinarianEntity {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $veterinarian->getName(),
                    'email' => $email,
                    'password' => Hash::make((string) config('livestock.veterinary_portal.default_password', '123456789')),
                ]);
            }

            // ADR-7: the portal role lives on the company pivot, so the same person can hold it
            // in more than one establishment without duplicating the user.
            $pivot = DB::table('company_user')
                ->where('company_id', $companyId)
                ->where('user_id', $user->id);

            if ($pivot->exists()) {
                $pivot->update(['role' => 'veterinarian', 'updated_at' => now()]);
            } else {
                DB::table('company_user')->insert([
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'role' => 'veterinarian',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $this->repository->save(new VeterinarianEntity(
                id: $veterinarian->getId(),
                companyId: $companyId,
                name: $veterinarian->getName(),
                licenseNumber: $veterinarian->getLicenseNumber(),
                userId: (int) $user->id,
                cuit: $veterinarian->getCuit(),
                billingCuit: $veterinarian->getBillingCuit(),
                accreditationCode: $veterinarian->getAccreditationCode(),
                phone: $veterinarian->getPhone(),
                email: $veterinarian->getEmail(),
                isActive: $veterinarian->isActive()
            ));
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): VeterinarianEntity
    {
        $companyId = $this->companyId();
        $current = $this->repository->findById($id, $companyId);

        if ($current === null) {
            throw VeterinaryDomainException::veterinarianNotFound($id);
        }

        // ADR-8: editing the catalogue never rewrites the signature already frozen on a protocol.
        return $this->repository->save(new VeterinarianEntity(
            id: $id,
            companyId: $companyId,
            name: trim((string) ($data['name'] ?? $current->getName())),
            licenseNumber: trim((string) ($data['license_number'] ?? $current->getLicenseNumber())),
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (int) $data['user_id'] : null)
                : $current->getUserId(),
            cuit: array_key_exists('cuit', $data)
                ? self::normaliseCuit($data['cuit'])
                : $current->getCuit(),
            billingCuit: array_key_exists('billing_cuit', $data)
                ? self::normaliseCuit($data['billing_cuit'])
                : $current->getBillingCuit(),
            accreditationCode: $data['accreditation_code'] ?? $current->getAccreditationCode(),
            phone: $data['phone'] ?? $current->getPhone(),
            email: $data['email'] ?? $current->getEmail(),
            isActive: array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $current->isActive()
        ));
    }

    /**
     * ADR-43: stored unformatted so two spellings of the same number are the same number. The
     * check digit is reported, never enforced — a professional's file is not the place to discover
     * that a number somebody read off a document does not close.
     */
    private static function normaliseCuit(mixed $raw): ?string
    {
        return Cuit::fromNullable($raw === null ? null : (string) $raw)?->value();
    }

    private function companyId(): int
    {
        return $this->companyContext->getCompanyId() ?? 0;
    }
}
