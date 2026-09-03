<?php

declare(strict_types=1);

namespace App\Core\Entities;

class ColorEntity
{
    public function __construct(
        private ?int $id,
        private string $name,
        private ?string $code = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }
}
