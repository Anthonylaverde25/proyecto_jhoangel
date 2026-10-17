<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

class ResolveServiceOrderIncidentRequest extends FormRequest
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
            'resolution' => 'required|string|max:1000',
        ];
    }
}
