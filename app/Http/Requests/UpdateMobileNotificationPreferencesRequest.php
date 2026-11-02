<?php

namespace App\Http\Requests;

use App\NotificationTopic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the topics being changed are sent, each with whether the phone
     * should buzz for it.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'topics' => ['required', 'array', 'min:1'],
            'topics.*' => ['required', 'boolean'],
            'topic_keys.*' => [Rule::in(array_map(
                fn (NotificationTopic $topic): string => $topic->value,
                NotificationTopic::configurable(),
            ))],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function pushChoices(): array
    {
        /** @var array<string, mixed> $topics */
        $topics = $this->validated('topics');

        return array_map(fn (mixed $wanted): bool => (bool) $wanted, $topics);
    }

    protected function prepareForValidation(): void
    {
        // The topics are the keys, and rules only look at values, so the keys
        // are copied into a list for `in` to check.
        $topics = $this->input('topics');

        $this->merge(['topic_keys' => is_array($topics) ? array_keys($topics) : []]);
    }
}
