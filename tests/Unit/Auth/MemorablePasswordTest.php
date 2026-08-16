<?php

use App\Modules\Auth\Support\MemorablePassword;

test('it produces lowercase words and digits only', function (): void {
    foreach (range(1, 50) as $_) {
        expect(MemorablePassword::generate())->toMatch('/^[a-z]+(-[a-z]+){2}-\d{3}$/');
    }
});

test('the digit block keeps its width even when the number is small', function (): void {
    // str_pad matters: without it "7" would shorten the password.
    $digits = collect(range(1, 200))
        ->map(fn (): string => (string) collect(explode('-', MemorablePassword::generate()))->last());

    expect($digits->every(fn (string $d): bool => strlen($d) === 3))->toBeTrue();
});

test('the word and digit counts are configurable', function (): void {
    expect(MemorablePassword::generate(words: 2, digits: 4))->toMatch('/^[a-z]+-[a-z]+-\d{4}$/');
});

test('it does not repeat itself in practice', function (): void {
    $generated = collect(range(1, 200))->map(fn (): string => MemorablePassword::generate());

    // Collisions would point at a broken RNG rather than bad luck.
    expect($generated->unique()->count())->toBe(200);
});
