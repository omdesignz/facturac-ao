<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Someone says they sent the reminder themselves on WhatsApp. The key makes a
 * repeated click, or a retry from the phone, answer with the first record.
 */
class RecordWhatsAppReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'],
        ];
    }
}
