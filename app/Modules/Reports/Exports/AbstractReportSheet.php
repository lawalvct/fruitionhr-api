<?php

namespace App\Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

abstract class AbstractReportSheet extends DefaultValueBinder implements WithCustomValueBinder
{
    protected const DARK_GREEN = '065F46';

    protected const GREEN = '0B7A5A';

    protected const LIGHT_GREEN = 'ECFDF5';

    protected const PALE_GREEN = 'D1FAE5';

    protected const DARK_TEXT = '172033';

    protected const MUTED_TEXT = '64748B';

    protected const BORDER = 'DCE5E2';

    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value)) {
            $cell->setValueExplicit(ReportExcelValue::safeText($value), DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    protected function applyBaseStyle(Worksheet $sheet): void
    {
        $sheet->setShowGridlines(false);
        $sheet->getTabColor()->setRGB(self::GREEN);
        $sheet->getParent()?->getDefaultStyle()->applyFromArray([
            'font' => [
                'name' => 'Aptos',
                'size' => 10,
                'color' => ['rgb' => self::DARK_TEXT],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    protected function styleTitleBand(Worksheet $sheet, string $lastColumn, string $subtitle): void
    {
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', ReportExcelValue::safeText($subtitle));

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => self::DARK_GREEN],
            ],
            'font' => [
                'bold' => true,
                'size' => 18,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => self::LIGHT_GREEN],
            ],
            'font' => [
                'italic' => true,
                'color' => ['rgb' => self::MUTED_TEXT],
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(22);
    }

    protected function styleSectionHeader(Worksheet $sheet, int $row, string $lastColumn): void
    {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => self::PALE_GREEN],
            ],
            'font' => [
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => self::DARK_GREEN],
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::BORDER],
                ],
            ],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(22);
    }

    protected function styleTableHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => [
                'fillType' => 'solid',
                'startColor' => ['rgb' => self::GREEN],
            ],
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => self::DARK_GREEN],
                ],
            ],
        ]);
    }

    protected function styleDataRange(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_HAIR,
                    'color' => ['rgb' => self::BORDER],
                ],
            ],
        ]);
    }
}
