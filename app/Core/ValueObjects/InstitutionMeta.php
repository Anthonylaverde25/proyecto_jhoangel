<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Exceptions\VeterinaryDomainException;
use JsonSerializable;

/**
 * ADR-29: an institution as it is described at the moment it matters, not as a catalogue row
 * somebody had to create beforehand.
 *
 * The shape is fixed on purpose. A free-form JSON blob rots: six months in, every row carries
 * different keys and nothing can be queried. Five named fields, one of them mandatory, is enough
 * to describe a laboratory and little enough that nobody avoids filling it.
 */
final class InstitutionMeta implements JsonSerializable
{
    public function __construct(
        private readonly string $name,
        private readonly ?Cuit $cuit = null,
        private readonly ?string $address = null,
        private readonly ?string $officialCode = null,
        private readonly ?string $contact = null
    ) {
        if (trim($this->name) === '') {
            throw VeterinaryDomainException::institutionNameRequired();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws VeterinaryDomainException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['nombre'] ?? $data['name'] ?? '')),
            cuit: Cuit::fromNullable(isset($data['cuit']) ? (string) $data['cuit'] : null),
            address: self::optional($data, ['direccion', 'address']),
            officialCode: self::optional($data, ['codigo_oficial', 'official_code']),
            contact: self::optional($data, ['contacto', 'contact'])
        );
    }

    public static function fromNullableArray(?array $data): ?self
    {
        return $data === null || $data === [] ? null : self::fromArray($data);
    }

    /**
     * ADR-31: two descriptions name the same institution when their CUIT matches — never by
     * name, which is precisely the comparison that does not survive two people typing.
     *
     * A missing CUIT on either side answers `false`: the system does not know they are the same,
     * and it does not know they are different either. Callers must not read this as "distinct".
     */
    public function isSameAs(?self $other): bool
    {
        if ($other === null || $this->cuit === null || $other->cuit === null) {
            return false;
        }

        return $this->cuit->equals($other->cuit);
    }

    /** Whether a comparison against this institution can conclude anything at all. */
    public function isComparable(): bool
    {
        return $this->cuit !== null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCuit(): ?Cuit
    {
        return $this->cuit;
    }

    public function getOfficialCode(): ?string
    {
        return $this->officialCode;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    /**
     * Stored in Spanish keys: this meta is read by people looking at sanitary evidence, and the
     * domain vocabulary of the project is Spanish.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'nombre' => $this->name,
            'cuit' => $this->cuit?->value(),
            // ADR-43: the number is stored as typed, so whoever reads the evidence is told when it
            // does not check out. Warning instead of refusing is the whole point.
            'cuit_valido' => $this->cuit?->isWellFormed(),
            'direccion' => $this->address,
            'codigo_oficial' => $this->officialCode,
            'contacto' => $this->contact,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function optional(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }
}
