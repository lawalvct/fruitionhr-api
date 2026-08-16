<?php

use App\Modules\Auth\Support\MemorablePassword;

/** Lowercase words joined by hyphens, ending in a digit group. */
const PASSWORD_SHAPE = '/^[a-z]+(-[a-z]+)*-\d+$/';

test('it produces lowercase words and digits only', function (): void {
    foreach (range(1, 50) as $_) {
        expect(MemorablePassword::generate())->toMatch(PASSWORD_SHAPE);
    }
});

test('the digit block keeps its width even when the number is small', function (): void {
    // str_pad matters: without it "7" would shorten a 3-digit block.
    $digits = collect(range(1, 200))
        ->map(fn (): string => (string) collect(explode('-', MemorablePassword::generate(2, 3)))->last());

    expect($digits->every(fn (string $d): bool => strlen($d) === 3))->toBeTrue();
});

test('the word and digit counts are configurable', function (): void {
    expect(MemorablePassword::generate(words: 2, digits: 4))->toMatch('/^[a-z]+-[a-z]+-\d{4}$/');
    expect(MemorablePassword::generate(words: 3, digits: 3))->toMatch('/^[a-z]+-[a-z]+-[a-z]+-\d{3}$/');
});

test('it does not repeat itself in practice', function (): void {
    // Drawn wide enough that a collision means a broken RNG, not bad luck.
    $generated = collect(range(1, 200))->map(fn (): string => MemorablePassword::generate(3, 4));

    expect($generated->unique()->count())->toBe(200);
});
