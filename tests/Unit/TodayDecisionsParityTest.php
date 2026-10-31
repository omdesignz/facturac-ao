<?php

use App\Analytics\TodayDecisions;

/**
 * The cases in tests/Fixtures/today-decisions-cases.json are the contract
 * between the web's cards and the phone app's. tests/today-decisions.test.mjs
 * reads the same file.
 *
 * @return array<string, array{array<string, mixed>, list<array<string, mixed>>}>
 */
function todayDecisionCases(): array
{
    $file = json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/today-decisions-cases.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return collect($file['cases'])
        ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case['input'], $case['expected']]])
        ->all();
}

test('the cards match the shared contract with the web', function (array $input, array $expected) {
    $cards = (new TodayDecisions)->for($input['focus'], $input['collections'], $input['lowStock']);

    expect(array_map(function (array $card): array {
        unset($card['document_public_id']);

        return $card;
    }, $cards))->toBe($expected);
})->with(todayDecisionCases());
