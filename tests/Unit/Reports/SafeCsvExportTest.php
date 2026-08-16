<?php

use App\Modules\Reports\Exports\SafeCsvExport;

test('csv exports neutralize spreadsheet formulas while preserving numeric values', function (): void {
    $response = app(SafeCsvExport::class)->download(
        ['Name', 'Value'],
        [
            ['=HYPERLINK("https://example.test")', 1200],
            [' +SUM(1,2)', -50],
            ['@malicious-command', null],
            ["\t=1+1", true],
        ],
        'safe-report.csv',
    );

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('safe-report.csv')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->toContain("' +SUM")
        ->and($csv)->toContain("'@malicious-command")
        ->and($csv)->toContain("'\t=1+1")
        ->and($csv)->toContain('-50')
        ->and($csv)->toContain('Yes');
});
