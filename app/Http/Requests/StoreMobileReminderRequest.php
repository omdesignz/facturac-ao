<?php

namespace App\Http\Requests;

class StoreMobileReminderRequest extends SendPaymentReminderRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_key' => ['required', 'uuid'],
        ];
    }
}
