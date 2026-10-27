<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Core\Entities\TaskEntity;
use App\Core\Enums\TaskUrgency;
use App\Core\Interfaces\ICompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The company comes from the context, never from the body; providers must belong to it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) app(ICompanyContext::class)->getCompanyId();

        return [
            'description' => ['required', 'string', 'min:3', 'max:' . TaskEntity::MAX_DESCRIPTION_LENGTH],
            'urgency' => ['nullable', 'string', Rule::in(TaskUrgency::codes())],
            'provider_ids' => ['nullable', 'array', 'max:' . TaskEntity::MAX_PROVIDERS],
            'provider_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('providers', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    public function urgency(): TaskUrgency
    {
        $code = $this->validated('urgency');

        return $code !== null ? TaskUrgency::from((string) $code) : TaskUrgency::default();
    }

    /** @return list<int> */
    public function providerIds(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('provider_ids') ?? [])));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.required' => 'Escribí el texto de la tarea.',
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
