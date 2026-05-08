<?php

declare(strict_types=1);

namespace App\Application\UseCases\Auth;

use App\Application\DTOs\UserAuthResult;
use App\Application\Mappers\UserMapper;
use App\Core\Interfaces\ICompanyRepository;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginUseCase
{
    public function __construct(
        private readonly ICompanyRepository $companyRepository
    ) {}

    public function __invoke(array $credentials): UserAuthResult
    {
        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        /** @var User $userModel */
        $userModel = Auth::user();
        
        // Cargar empresas
        $companies = $this->companyRepository->getForUser($userModel->id);
        
        // Crear Token
        $token = $userModel->createToken('auth_token')->plainTextToken;

        // Mapear Usuario a Entidad
        $userEntity = UserMapper::toEntity($userModel);

        return new UserAuthResult(
            user: $userEntity,
            accessToken: $token,
            companies: $companies
        );
    }

    public function getCompaniesForUser(int $userId): array
    {
        return $this->companyRepository->getForUser($userId);
    }
}
