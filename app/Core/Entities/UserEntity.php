<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class UserEntity
{
    /**
     * @param CompanyEntity[] $companies
     */
    public function __construct(
        private readonly int $id,
        private string $name,
        private string $email,
        private string $role = 'admin',
        private array $companies = []
    ) {}

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }

    /**
     * @return CompanyEntity[]
     */
    public function getCompanies(): array
    {
        return $this->companies;
    }

    public function addCompany(CompanyEntity $company): void
    {
        $this->companies[] = $company;
    }
}
