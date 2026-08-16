<?php

use App\Core\Notifications\PlainTextMail;

test('heading markers are stripped', function (): void {
    expect(PlainTextMail::format("# Confirm your email\n\n## Next steps"))
        ->toBe("Confirm your email\n\nNext steps");
});

test('bold markers are stripped but bare asterisks survive', function (): void {
    expect(PlainTextMail::format('**Keep this code** to yourself'))
        ->toBe('Keep this code to yourself');

    expect(PlainTextMail::format('5 * 3 = 15'))->toBe('5 * 3 = 15');
});

test('links collapse to a single url when the label is the url', function (): void {
    expect(PlainTextMail::format('[https://app.test/x](https://app.test/x)'))
        ->toBe('https://app.test/x');
});

test('labelled links keep the label alongside the url', function (): void {
    expect(PlainTextMail::format('Reach us at [support@fruitionhr.com](mailto:support@fruitionhr.com).'))
        ->toBe('Reach us at support@fruitionhr.com (mailto:support@fruitionhr.com).');
});

test('list bullets and body copy are left alone', function (): void {
    $body = "- View your payslips\n- Request leave";

    expect(PlainTextMail::format($body))->toBe($body);
});
