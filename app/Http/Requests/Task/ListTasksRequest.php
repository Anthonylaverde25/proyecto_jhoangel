<?php

declare(strict_types=1);

namespace App\Http\Requests\Task;

use App\Core\ValueObjects\TaskFilter;
use App\Core\Enums\TaskUrgency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'urgency' => ['nullable', 'string', Rule::in(TaskUrgency::codes())],
            'provider_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . TaskFilter::MAX_PER_PAGE],
        ];
    }

    public function toFilter(int $companyId): TaskFilter
    {
        $urgency = $this->validated('urgency');
        $providerId = $this->validated('provider_id');
        $search = $this->validated('search');

        return new TaskFilter(
            companyId: $companyId,
            urgency: $urgency !== null ? TaskUrgency::from((string) $urgency) : null,
            providerId: $providerId !== null ? (int) $providerId : null,
            search: $search !== null ? trim((string) $search) : null,
            page: (int) ($this->validated('page') ?? 1),
            perPage: (int) ($this->validated('per_page') ?? TaskFilter::DEFAULT_PER_PAGE),
        );
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'urgency.in' => 'La urgencia debe ser no urgente, urgente o muy urgente.',
        ];
    }
}
