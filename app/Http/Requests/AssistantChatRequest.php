<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AssistantChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'messages' => ['required', 'array', 'min:1', 'max:40'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $messages = $this->input('messages');

            if (is_array($messages) && $messages !== [] && (end($messages)['role'] ?? null) !== 'user') {
                $validator->errors()->add('messages', 'El último mensaje debe ser la pregunta del usuario.');
            }

            if ((int) $this->header('X-Company-ID') <= 0) {
                $validator->errors()->add('company', 'Falta la compañía activa (X-Company-ID).');
            }
        });
    }

    public function messages(): array
    {
        return [
            'messages.required' => 'Falta la pregunta.',
            'messages.max' => 'La conversación es demasiado larga; empezá una nueva.',
            'messages.*.content.max' => 'El mensaje no puede superar los 4000 caracteres.',
        ];
    }
}
