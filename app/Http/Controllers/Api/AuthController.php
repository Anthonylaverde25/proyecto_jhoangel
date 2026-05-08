<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Application\UseCases\Auth\LoginUseCase;
use App\Application\Mappers\UserMapper;
use App\Http\Resources\UserResource;
use App\Http\Resources\CompanyResource;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginUseCase $loginUseCase
    ) {}

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $authResult = ($this->loginUseCase)($credentials);

        return response()->json([
            'user' => new UserResource($authResult->user),
            'access_token' => $authResult->accessToken,
            'companies' => CompanyResource::collection($authResult->companies)
        ]);
    }

    public function me(Request $request)
    {
        $userModel = $request->user();
        $companies = $this->loginUseCase->getCompaniesForUser($userModel->id);
        
        $userEntity = UserMapper::toEntity($userModel);

        return response()->json([
            'user' => new UserResource($userEntity),
            'companies' => CompanyResource::collection($companies)
        ]);
    }
}
