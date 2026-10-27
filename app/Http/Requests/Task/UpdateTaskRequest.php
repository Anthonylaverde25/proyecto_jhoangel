<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Core\Entities\TaskEntity;
use App\Core\Enums\TaskUrgency;
use App\Core\Interfaces\ICompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Partial edit of a task: only the fields sent change. `provider_ids` replaces the whole list.
 */
class UpdateTaskRequest extends FormRequest
{
    private const EDITABLE = ['description', 'urgency', 'provider_ids'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) app(ICompanyContext::class)->getCompanyId();

        return [
            'description' => ['sometimes', 'required', 'string', 'min:3', 'max:' . TaskEntity::MAX_DESCRIPTION_LENGTH],
            'urgency' => ['sometimes', 'required', 'string', Rule::in(TaskUrgency::codes())],
            'provider_ids' => ['sometimes', 'present', 'array', 'max:' . TaskEntity::MAX_PROVIDERS],
            'provider_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('providers', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (!$this->hasAny(self::EDITABLE)) {
                    $validator->errors()->add('task', 'Indicá qué cambiar de la tarea: texto, urgencia o proveedores.');
                }
            },
        ];
    }

    public function description(): ?string
    {
        return $this->has('description') ? (string) $this->validated('description') : null;
    }

    public function urgency(): ?TaskUrgency
    {
        return $this->has('urgency') ? TaskUrgency::from((string) $this->validated('urgency')) : null;
    }

    /** @return list<int>|null null when the providers are not being changed */
    public function providerIds(): ?array
    {
        if (!$this->has('provider_ids')) {
            return null;
        }

        return array_values(array_map('intval', (array) ($this->validated('provider_ids') ?? [])));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.min' => 'La tarea debe tener al menos 3 caracteres.',
            'description.max' => 'La tarea no puede superar los ' . TaskEntity::MAX_DESCRIPTION_LENGTH . ' caracteres.',
            'urgency.in' => 'La urgencia debe ser no urgente, urgente o muy urgente.',
            'provider_ids.max' => 'Una tarea puede tener como máximo ' . TaskEntity::MAX_PROVIDERS . ' proveedores.',
            'provider_ids.*.distinct' => 'Un proveedor está repetido en la tarea.',
            'provider_ids.*.exists' => 'Uno de los proveedores no existe en esta empresa.',
            'provider_ids.*.integer' => 'Los proveedores se indican por su id.',
        ];
    }
}
