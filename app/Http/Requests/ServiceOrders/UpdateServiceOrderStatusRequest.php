<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Core\Enums\ServiceOrderStatus;

class UpdateServiceOrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            $this->merge([
                'status' => strtoupper((string) $this->input('status')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                new \Illuminate\Validation\Rules\Enum(ServiceOrderStatus::class),
            ],
        ];
    }
}
