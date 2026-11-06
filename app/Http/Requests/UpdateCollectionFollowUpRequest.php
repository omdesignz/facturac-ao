<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCollectionFollowUpRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'next_contact_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'promised_payment_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'note' => ['present', 'nullable', 'string', 'max:2000'],
            'completed' => ['required', 'boolean'],
        ];
    }

    /** @return array{revision: int, next_contact_on: string|null, promised_payment_on: string|null, note: string|null, completed: bool} */
    public function followUp(): array
    {
        return [
            'revision' => (int) $this->validated('revision'),
            'next_contact_on' => $this->validated('next_contact_on'),
            'promised_payment_on' => $this->validated('promised_payment_on'),
            'note' => $this->validated('note'),
            'completed' => $this->boolean('completed'),
        ];
    }
}
