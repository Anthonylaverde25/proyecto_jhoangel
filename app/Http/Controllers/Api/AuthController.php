<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Application\UseCases\Auth\LoginUseCase;
use App\Application\Mappers\UserMapper;
use App\Http\Resources\UserResource;
use App\Http\Resources\CompanyResource;

use App\Http\Requests\Auth\LoginRequest;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginUseCase $loginUseCase
    ) {}

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        $authResult = ($this->loginUseCase)($credentials);

        return response()->json([
            'user' => new UserResource($authResult->user),
            'access_token' => $authResult->accessToken,
            'companies' => CompanyResource::collection($authResult->companies)
        ]);
    }

    /**
     * SEC-02: server-side revocation. Deleting the current access token invalidates the session
     * immediately, so a captured token stops working the moment the user logs out. The client
     * still clears its local copy, but that is no longer the only line of defence.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
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
