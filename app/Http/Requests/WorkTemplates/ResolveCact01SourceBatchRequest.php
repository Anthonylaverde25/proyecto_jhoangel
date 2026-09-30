<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkTemplates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the review screen knows while a CACT-01 is being checked: the source batch name as the
 * scan read it, and the tags of every row loaded so far.
 */
final class ResolveCact01SourceBatchRequest extends FormRequest
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
            'lote_origen' => 'nullable|string|max:255',
            'caravanas' => 'present|array|max:1000',
            'caravanas.*' => 'nullable|string|max:50',
        ];
    }
}
