<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

use App\Core\Enums\PartnerRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['distinct', Rule::enum(PartnerRole::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'roles.required' => 'Marcá al menos un rol: qué nos vende o qué nos compra.',
            'roles.min' => 'Marcá al menos un rol: qué nos vende o qué nos compra.',
        ];
    }

    /** @return list<PartnerRole> */
    public function roles(): array
    {
        return array_map(fn ($role) => PartnerRole::from((string) $role), array_values($this->validated('roles')));
    }
}
