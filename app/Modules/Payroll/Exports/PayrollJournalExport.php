<?php

namespace App\Modules\Payroll\Exports;

use App\Support\Money\Naira;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Payroll journal as xlsx: one row per DR/CR line, plus a totals row.
 */
class PayrollJournalExport implements FromArray, WithHeadings
{
    /**
     * @param  array{entries:list<array{account:string,type:string,amount:int}>, total_debit:int, total_credit:int}  $journal
     */
    public function __construct(private readonly array $journal)
    {
    }

    public function headings(): array
    {
        return ['Account', 'Debit (NGN)', 'Credit (NGN)'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->journal['entries'] as $entry) {
            $rows[] = [
                $entry['account'],
                $entry['type'] === 'debit' ? Naira::toNaira($entry['amount']) : '',
                $entry['type'] === 'credit' ? Naira::toNaira($entry['amount']) : '',
            ];
        }

        $rows[] = ['TOTAL', Naira::toNaira($this->journal['total_debit']), Naira::toNaira($this->journal['total_credit'])];

        return $rows;
    }
}
