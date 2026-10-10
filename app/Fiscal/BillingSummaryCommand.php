<?php

namespace App\Fiscal;

use Carbon\CarbonImmutable;

final readonly class BillingSummaryCommand
{
    private function __construct(public string $month, public string $from, public string $toExclusive) {}

    public static function fromQueryString(string $query): self
    {
        abort_if(strlen($query) > 128 || preg_match('/%(?![0-9a-fA-F]{2})/', $query) === 1, 422);
        $parts = explode('&', $query);
        abort_unless(count($parts) === 1, 422);
        $pair = explode('=', $parts[0], 2);
        abort_unless(count($pair) === 2 && urldecode($pair[0]) === 'month', 422);

        return self::fromMonth(urldecode($pair[1]));
    }

    public static function fromMonth(string $month): self
    {
        abort_unless(preg_match('/\A[2-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month) === 1 && $month <= CarbonImmutable::now('Africa/Luanda')->format('Y-m'), 422);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', 'Africa/Luanda');
        abort_unless($from instanceof CarbonImmutable, 422);

        return new self($month, $from->toDateString(), $from->addMonth()->toDateString());
    }
}
