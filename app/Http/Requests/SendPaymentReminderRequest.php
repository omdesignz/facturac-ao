<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendPaymentReminderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:20', 'max:4000'],
        ];
    }
}
