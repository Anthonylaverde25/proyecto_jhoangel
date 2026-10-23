<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The consent screen's answer. The OAuth parameters are echoed back exactly as the client sent
 * them; their meaning is checked by McpAuthorizationRequestValidator, not here.
 */
class ApproveMcpAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,deny'],
            'response_type' => ['required', 'string'],
            'client_id' => ['required', 'string', 'max:64'],
            'redirect_uri' => ['required', 'string', 'max:2000'],
            'code_challenge' => ['required', 'string', 'max:128'],
            'code_challenge_method' => ['required', 'string'],
            // OAuth sets no limit on these; clients such as Gemini send a signed state of 1 KB+.
            'scope' => ['nullable', 'string', 'max:1000'],
            'state' => ['nullable', 'string', 'max:8000'],
            'resource' => ['nullable', 'string', 'max:2000'],
            'mcp_host' => ['nullable', 'string', 'max:255'],
        ];
    }
}
