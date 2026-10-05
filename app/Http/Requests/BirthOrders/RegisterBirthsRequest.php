<?php

declare(strict_types=1);

namespace App\Http\Requests\BirthOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The same shape as an order, minus `issue` (it is born executed), plus what happened to each
 * female. A registration leaves nothing pending, so every female carries her outcome.
 */
final class RegisterBirthsRequest extends FormRequest
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
        $rules = (new EmitBirthOrderRequest())->rules();
        unset($rules['issue']);

        return [
            ...$rules,
            ...BirthFieldRules::rules(),
            'animals.*.outcome' => 'required|string|in:LIVE,STILLBORN,PERINATAL_DEATH',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...(new EmitBirthOrderRequest())->messages(),
            ...BirthFieldRules::messages(),
            'animals.*.outcome.required' => 'Cada vientre del registro tiene que decir qué pasó: Parió, Nació muerto o Murió al pie.',
        ];
    }
}
