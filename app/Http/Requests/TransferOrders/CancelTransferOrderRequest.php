<?php

declare(strict_types=1);

namespace App\Http\Requests\TransferOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reason is optional here because discarding a draft needs none. Cancelling an issued order
 * does, and that is enforced by the entity, which knows which of the two it is.
 */
final class CancelTransferOrderRequest extends FormRequest
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
            'reason' => 'nullable|string|max:2000',
        ];
    }
}
