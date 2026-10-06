<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('the Vue preview follows schema 2 debit and credit rounding using exact integers', function () {
    $javascript = <<<'JS'
import { readFileSync } from 'node:fs';
import ts from 'typescript';

const source = readFileSync('resources/js/lib/fiscal-rounding.ts', 'utf8');
const script = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 },
}).outputText;
const { roundFiscalLineAmount } = await import(`data:text/javascript;base64,${Buffer.from(script).toString('base64')}`);
const cases = [
    [12345000n, 10000n, 'FT'],
    [12345000n, 10000n, 'NC'],
    [23144n, 10n, 'FT'],
    [23144n, 10n, 'NC'],
    [2250000000000n, 100000000n, 'GF'],
    [0n, 10000n, 'NC'],
];
process.stdout.write(JSON.stringify(cases.map(args => roundFiscalLineAmount(...args).toString())));
JS;
    $process = new Process(['node', '--input-type=module', '--eval', $javascript], base_path());
    $process->setTimeout(15);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['1234', '1235', '2314', '2315', '22500', '0']);
});
