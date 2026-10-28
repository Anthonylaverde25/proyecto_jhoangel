<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\PartnerException;

/**
 * Someone we talk to at a partner (C6): a name and a phone always, an email when there is one.
 * Whether it is the partner's default contact is decided by PartnerEntity (C7–C9).
 */
final class PartnerContactEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $position,
        private string $phone,
        private ?string $email,
        private ?string $notes,
        private bool $isDefault = false,
    ) {
        $this->assertValid();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function updateDetails(string $name, ?string $position, string $phone, ?string $email, ?string $notes): void
    {
        $this->name = $name;
        $this->position = $position;
        $this->phone = $phone;
        $this->email = $email;
        $this->notes = $notes;
        $this->assertValid();
    }

    public function markDefault(): void
    {
        $this->isDefault = true;
    }

    public function unmarkDefault(): void
    {
        $this->isDefault = false;
    }

    private function assertValid(): void
    {
        $this->name = trim($this->name);
        $this->phone = trim($this->phone);
        $this->email = self::blankToNull($this->email);
        $this->position = self::blankToNull($this->position);
        $this->notes = self::blankToNull($this->notes);

        $errors = [];
        if ($this->name === '') {
            $errors['name'] = ['Falta el nombre de la persona de contacto.'];
        }
        if ($this->phone === '') {
            $errors['phone'] = ['Falta el teléfono: es obligatorio para una persona de contacto.'];
        }
        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = ['El email no es válido.'];
        }
        if ($errors !== []) {
            throw PartnerException::many($errors);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
