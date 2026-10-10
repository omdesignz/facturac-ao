<?php

namespace App\Fiscal;

use Carbon\CarbonImmutable;

final class AssistantProviderProfile
{
    public const ID = 'p7-anthropic-haiku55-us-2026-10-08-v1';

    public const PRICE_ID = 'p7-price-haiku55-us-2026-10-08-v1';

    public const POLICY = 'p7-question-v1';

    public const MODEL = 'claude-haiku-5-5';

    public const HOST = 'api.anthropic.com';

    public const BASE_URL = 'https://'.self::HOST.'/v1';

    public const URL = self::BASE_URL.'/messages';

    public const API_VERSION = '2023-06-01';

    public const EXPIRES = '2026-11-07T00:00:00Z';

    public const INPUT_ENVELOPE = 1000000;

    public const OUTPUT_LIMIT = 1024;

    public const ESTIMATE_LIMIT = 8192;

    public const BODY_LIMIT = 16384;

    public const INPUT_RATE = 550000;

    public const OUTPUT_RATE = 2750000;

    public const BUDGET_CEILINGS = ['attempt' => 600000, 'user_day' => 2000000, 'workspace_day' => 5000000,
        'workspace_month' => 50000000, 'deployment_day' => 20000000, 'deployment_month' => 200000000];

    public static function reservation(): int
    {
        return self::charge(self::INPUT_ENVELOPE, self::OUTPUT_LIMIT, self::INPUT_RATE, self::OUTPUT_RATE);
    }

    public static function estimate(#[\SensitiveParameter] string $body): int
    {
        abort_if(strlen($body) > self::BODY_LIMIT || ! mb_check_encoding($body, 'UTF-8'), 503);
        $estimate = strlen($body) + 1024;
        abort_if($estimate > self::ESTIMATE_LIMIT, 503);

        return $estimate;
    }

    public static function actualCharge(int $input, int $output): int
    {
        abort_if($input < 0 || $input > self::INPUT_ENVELOPE || $output < 0 || $output > self::OUTPUT_LIMIT, 503);

        return self::charge($input, $output, $input <= 100000 ? 110000 : self::INPUT_RATE, $input <= 100000 ? 550000 : self::OUTPUT_RATE);
    }

    public static function valid(): bool
    {
        return config('assistant.provider.profile') === self::ID
            && config('assistant.provider.price_profile') === self::PRICE_ID
            && now()->lt(CarbonImmutable::parse(self::EXPIRES));
    }

    private static function charge(int $input, int $output, int $inputRate, int $outputRate): int
    {
        return intdiv($input * $inputRate + 999999, 1000000) + intdiv($output * $outputRate + 999999, 1000000);
    }
}
