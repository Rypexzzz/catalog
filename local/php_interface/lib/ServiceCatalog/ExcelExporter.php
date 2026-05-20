<?php

namespace Ithive\Goalsbazhen\ServiceCatalog;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\{Border, Fill, Alignment};


class ExcelExporter
{
    /* ---- Стили ---- */
    private static function headerStyle(): array
    {
        return [
            'font'      => ['bold' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DFE3E8']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private static function dataStyle(): array
    {
        return [
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private static function stageStyle(): array
    {
        return [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '002060']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private static function totalRowStyle(): array
    {
        return [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0070C0']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    private static function summaryTotalStyle(): array
    {
        return [
            'font'    => ['bold' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];
    }

    public static function export(array $byRoot, string $projectName): void
    {
        if (empty($projectName)) $projectName = 'Проект';

        /* ---- Разделяем на dev / service ---- */
        $devItems     = [];
        $serviceItems = [];

        foreach ($byRoot as $root) {
            if (($root['CODE'] ?? '') === 'service') {
                $serviceItems = $root['ITEMS'] ?? [];
            } else {
                foreach ($root['ITEMS'] ?? [] as $item) {
                    $devItems[] = $item;
                }
            }
        }

        /* ---- Группируем dev по этапам ---- */
        $devByStage = [];
        foreach ($devItems as $item) {
            $stage = $item['SECTION_NAME'] ?? 'Без этапа';
            $devByStage[$stage][] = $item;
        }

        /* ---- Считаем итоги ---- */
        $devTotalHours = $devTotalCost = $svcTotalHours = $svcTotalCost = 0;

        foreach ($devItems as $item) {
            foreach ($item['ROLES'] as $role) $devTotalHours += $role['HOURS'] ?? 0;
            $devTotalCost += $item['SUM'] ?? 0;
        }
        foreach ($serviceItems as $item) {
            foreach ($item['ROLES'] as $role) $svcTotalHours += $role['HOURS'] ?? 0;
            $svcTotalCost += $item['SUM'] ?? 0;
        }

        /* ---- Создаём книгу ---- */
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        /* ---- Лист «Разработка» ---- */
        if (!empty($devItems)) {
            $sh = new Worksheet($book, 'Разработка');
            $book->addSheet($sh, 0);
            self::buildDevSheet($sh, $projectName, $devByStage, $devTotalHours, $devTotalCost);
        }

        /* ---- Лист «Поддержка» ---- */
        if (!empty($serviceItems)) {
            $sh = new Worksheet($book, 'Поддержка');
            $book->addSheet($sh);
            self::buildServiceSheet($sh, $projectName, $serviceItems, $svcTotalHours, $svcTotalCost);
        }

        /* ---- Лист «Сводная» (всегда первый) ---- */
        $summary = new Worksheet($book, 'Сводная');
        $book->addSheet($summary, 0);
        self::buildSummarySheet($summary, $projectName, $devTotalHours, $devTotalCost, $svcTotalHours, $svcTotalCost, !empty($devItems), !empty($serviceItems));

        $book->setActiveSheetIndex(0);

        /* ---- Отправляем файл ---- */
        $filename = 'Расчет стоимости ' . $projectName . '.xlsx';
        $filename = preg_replace('/[\\\\\/\:\*\?\"\<\>\|]/', '_', $filename);

        while (ob_get_level()) ob_end_clean();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        (new Xlsx($book))->save('php://output');
        exit();
    }

    /* ================================================================
       ЛИСТ «СВОДНАЯ»
       ================================================================ */

    private static function buildSummarySheet(
        Worksheet $sh, string $name,
        int $devH, float $devC, int $svcH, float $svcC,
        bool $hasDev, bool $hasSvc
    ): void {
        $cols = ['A' => 12, 'B' => 50, 'C' => 18, 'D' => 18];
        foreach ($cols as $c => $w) $sh->getColumnDimension($c)->setWidth($w);

        $sh->setCellValue('A1', '№ п/п');
        $sh->setCellValue('B1', 'Наименование работы/услуги');
        $sh->setCellValue('C1', 'Трудозатраты, чел/часы');
        $sh->setCellValue('D1', 'Стоимость, руб.');
        $sh->getStyle('A1:D1')->applyFromArray(self::headerStyle());
        $sh->getRowDimension(1)->setRowHeight(30);

        $row    = 2;
        $num    = 1;
        $start  = 2;

        if ($hasDev) {
            $sh->setCellValue("A{$row}", $num);
            $sh->setCellValue("B{$row}", "{$name} (Разработка)");
            $sh->setCellValue("C{$row}", $devH);
            $sh->setCellValue("D{$row}", $devC);
            $sh->getStyle("A{$row}:D{$row}")->applyFromArray(self::dataStyle());
            $row++; $num++;
        }
        if ($hasSvc) {
            $sh->setCellValue("A{$row}", $num);
            $sh->setCellValue("B{$row}", "{$name} (Поддержка)");
            $sh->setCellValue("C{$row}", $svcH);
            $sh->setCellValue("D{$row}", $svcC);
            $sh->getStyle("A{$row}:D{$row}")->applyFromArray(self::dataStyle());
            $row++;
        }

        $end = $row - 1;

        /* Итого без НДС */
        $sh->setCellValue("A{$row}", 'ИТОГО в руб., без НДС');
        $sh->mergeCells("A{$row}:B{$row}");
        $sh->setCellValue("C{$row}", "=SUM(C{$start}:C{$end})");
        $sh->setCellValue("D{$row}", "=SUM(D{$start}:D{$end})");
        $sh->getStyle("A{$row}:D{$row}")->applyFromArray(self::summaryTotalStyle());
        $totalRow = $row++;

        /* НДС */
        $sh->setCellValue("A{$row}", 'НДС 22%, в руб.');
        $sh->mergeCells("A{$row}:B{$row}");
        $sh->setCellValue("C{$row}", '22%');
        $sh->setCellValue("D{$row}", "=ROUND(D{$totalRow}*0.22,2)");
        $sh->getStyle("A{$row}:D{$row}")->applyFromArray(self::summaryTotalStyle());
        $ndsRow = $row++;

        /* Итого с НДС */
        $sh->setCellValue("A{$row}", 'ИТОГО в руб., с НДС');
        $sh->mergeCells("A{$row}:B{$row}");
        $sh->setCellValue("D{$row}", "=D{$totalRow}+D{$ndsRow}");
        $sh->getStyle("A{$row}:D{$row}")->applyFromArray(self::summaryTotalStyle());
        $row += 2;

        $sh->setCellValue("B{$row}", '*расчет производится, исходя из текущих требований к налогообложению');
    }

    /* ================================================================
       ЛИСТ «РАЗРАБОТКА»
       ================================================================ */

    private static function buildDevSheet(
        Worksheet $sh, string $name, array $byStage, int $totalH, float $totalC
    ): void {
        $widths = ['A' => 12, 'B' => 50, 'C' => 30, 'D' => 18, 'E' => 16, 'F' => 14, 'G' => 18];
        foreach ($widths as $c => $w) $sh->getColumnDimension($c)->setWidth($w);

        /* ---- Сводная мини-таблица (строки 1-3) ---- */
        $sh->setCellValue('A1', '№ п/п');
        $sh->setCellValue('B1', 'Работа/Услуга');
        $sh->setCellValue('C1', 'Трудозатраты, чел/часы');
        $sh->setCellValue('D1', 'Стоимость, руб. без НДС');
        $sh->getStyle('A1:D1')->applyFromArray(self::headerStyle());
        $sh->getRowDimension(1)->setRowHeight(30);

        $sh->setCellValue('A2', '1');
        $sh->setCellValue('B2', $name);
        $sh->setCellValue('C2', $totalH);
        $sh->setCellValue('D2', $totalC);
        $sh->getStyle('A2:D2')->applyFromArray(self::dataStyle());

        $sh->setCellValue('A3', 'ИТОГО');
        $sh->mergeCells('A3:B3');
        $sh->setCellValue('C3', $totalH);
        $sh->setCellValue('D3', $totalC);
        $sh->getStyle('A3:D3')->applyFromArray(self::summaryTotalStyle());

        /* ---- Детальная таблица (строки 6+) ---- */
        foreach (['A6' => '№ п/п', 'B6' => 'Название задачи', 'C6' => 'Роль на проекте',
                   'D6' => 'Категория персонала', 'E6' => 'Трудозатраты, чел/часы',
                   'F6' => 'Ставка, руб./час', 'G6' => 'Стоимость, руб. без НДС'] as $cell => $v) {
            $sh->setCellValue($cell, $v);
        }
        $sh->getStyle('A6:G6')->applyFromArray(self::headerStyle());
        $sh->getRowDimension(6)->setRowHeight(30);

        $sh->setCellValue('A7', $name);
        $sh->mergeCells('A7:G7');
        $sh->getStyle('A7')->applyFromArray(['font' => ['bold' => true, 'size' => 12]]);

        $sh->setCellValue('A8', 'ИТОГО');
        $sh->mergeCells('A8:D8');
        $sh->setCellValue('E8', $totalH);
        $sh->setCellValue('G8', $totalC);
        $sh->getStyle('A8:G8')->applyFromArray(self::totalRowStyle());

        $row      = 9;
        $stageNum = 1;

        foreach ($byStage as $stageName => $items) {
            $stageH = $stageC = 0;
            foreach ($items as $item) {
                foreach ($item['ROLES'] as $r) $stageH += $r['HOURS'] ?? 0;
                $stageC += $item['SUM'] ?? 0;
            }

            $sh->setCellValue("B{$row}", "{$stageNum}. {$stageName}");
            $sh->setCellValue("E{$row}", $stageH);
            $sh->setCellValue("G{$row}", $stageC);
            $sh->getStyle("A{$row}:G{$row}")->applyFromArray(self::stageStyle());
            $row++;

            $sub = 1;
            foreach ($items as $item) {
                $roles     = $item['ROLES'] ?? [];
                $first     = true;
                $startRow  = $row;

                foreach ($roles as $role) {
                    if ($first) {
                        $sh->setCellValue("A{$row}", "{$stageNum}.{$sub}");
                        $sh->setCellValue("B{$row}", $item['NAME']);
                    }
                    $sh->setCellValue("C{$row}", $role['ROLE_NAME'] ?? '');
                    $sh->setCellValue("D{$row}", $role['GRADE_NAME'] ?? '');
                    $sh->setCellValue("E{$row}", $role['HOURS'] ?? 0);
                    $sh->setCellValue("F{$row}", $role['RATE'] ?? 0);
                    if ($first) {
                        $sh->setCellValue("G{$row}", $item['SUM'] ?? 0);
                    }
                    $sh->getStyle("A{$row}:G{$row}")->applyFromArray(self::dataStyle());
                    $first = false;
                    $row++;
                }

                if (count($roles) > 1) {
                    $endRow = $row - 1;
                    $sh->mergeCells("A{$startRow}:A{$endRow}");
                    $sh->mergeCells("B{$startRow}:B{$endRow}");
                    $sh->mergeCells("G{$startRow}:G{$endRow}");
                    foreach (['A', 'B', 'G'] as $c) {
                        $sh->getStyle("{$c}{$startRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    }
                }
                $sub++;
            }
            $stageNum++;
        }
    }

    /* ================================================================
       ЛИСТ «ПОДДЕРЖКА»
       ================================================================ */

    private static function buildServiceSheet(
        Worksheet $sh, string $name, array $items, int $totalH, float $totalC
    ): void {
        $widths = ['A' => 12, 'B' => 50, 'C' => 30, 'D' => 18, 'E' => 16, 'F' => 14, 'G' => 18, 'H' => 18];
        foreach ($widths as $c => $w) $sh->getColumnDimension($c)->setWidth($w);

        /* ---- Сводная мини-таблица (строки 1-3) ---- */
        $sh->setCellValue('A1', '№ п/п');
        $sh->setCellValue('B1', 'Работа/Услуга');
        $sh->setCellValue('C1', 'Трудозатраты, чел/часы');
        $sh->setCellValue('D1', 'Стоимость, руб. без НДС');
        $sh->getStyle('A1:D1')->applyFromArray(self::headerStyle());
        $sh->getRowDimension(1)->setRowHeight(30);

        $sh->setCellValue('A2', '1');
        $sh->setCellValue('B2', $name);
        $sh->setCellValue('C2', $totalH);
        $sh->setCellValue('D2', $totalC);
        $sh->getStyle('A2:D2')->applyFromArray(self::dataStyle());

        $sh->setCellValue('A3', 'ИТОГО');
        $sh->mergeCells('A3:B3');
        $sh->setCellValue('C3', $totalH);
        $sh->setCellValue('D3', $totalC);
        $sh->getStyle('A3:D3')->applyFromArray(self::summaryTotalStyle());

        /* ---- Детальная таблица (строки 6+) ---- */
        foreach (['A6' => '№ п/п', 'B6' => 'Название задачи', 'C6' => 'Роль на проекте',
                   'D6' => 'Категория персонала', 'E6' => 'Трудозатраты, чел/часы',
                   'F6' => 'Ставка, руб./час', 'G6' => 'Коэфф. уровня обслуживания',
                   'H6' => 'Стоимость, руб. без НДС'] as $cell => $v) {
            $sh->setCellValue($cell, $v);
        }
        $sh->getStyle('A6:H6')->applyFromArray(self::headerStyle());
        $sh->getRowDimension(6)->setRowHeight(30);

        $sh->setCellValue('A7', $name);
        $sh->mergeCells('A7:H7');
        $sh->getStyle('A7')->applyFromArray(['font' => ['bold' => true, 'size' => 12]]);

        $sh->setCellValue('A8', 'ИТОГО');
        $sh->mergeCells('A8:D8');
        $sh->setCellValue('E8', $totalH);
        $sh->setCellValue('H8', $totalC);
        $sh->getStyle('A8:H8')->applyFromArray(self::totalRowStyle());

        $sh->setCellValue('B9', '1.0 Поддержка и управление сервисом');
        $sh->setCellValue('E9', $totalH);
        $sh->setCellValue('H9', $totalC);
        $sh->getStyle('A9:H9')->applyFromArray(self::stageStyle());

        $sh->setCellValue('A10', '3-я линия поддержки');
        $sh->mergeCells('A10:H10');
        $sh->getStyle('A10:H10')->applyFromArray(self::dataStyle());

        $row  = 11;
        $task = 1;

        foreach ($items as $item) {
            $roles     = $item['ROLES'] ?? [];
            $level     = $item['SERVICE_LEVEL'] ?? CostCalculator::LEVEL_MEDIUM;
            $coeff     = CostCalculator::getLevelCoefficient($level);
            $first     = true;
            $startRow  = $row;

            foreach ($roles as $role) {
                if ($first) {
                    $sh->setCellValue("A{$row}", "1.{$task}");
                    $sh->setCellValue("B{$row}", $item['NAME']);
                }
                $sh->setCellValue("C{$row}", $role['ROLE_NAME'] ?? '');
                $sh->setCellValue("D{$row}", $role['GRADE_NAME'] ?? '');
                $sh->setCellValue("E{$row}", $role['HOURS'] ?? 0);
                $sh->setCellValue("F{$row}", $role['RATE'] ?? 0);
                if ($first) {
                    $sh->setCellValue("G{$row}", $coeff);
                    $sh->setCellValue("H{$row}", $item['SUM'] ?? 0);
                }
                $sh->getStyle("A{$row}:H{$row}")->applyFromArray(self::dataStyle());
                $first = false;
                $row++;
            }

            if (count($roles) > 1) {
                $endRow = $row - 1;
                foreach (['A', 'B', 'G', 'H'] as $c) {
                    $sh->mergeCells("{$c}{$startRow}:{$c}{$endRow}");
                    $sh->getStyle("{$c}{$startRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                }
            }
            $task++;
        }
    }
}
