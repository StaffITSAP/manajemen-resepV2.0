<?php

namespace App\Http\Controllers\Accurate;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ItemAdjustmentTemplateController
{
    public function __invoke()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Isi data
        $sheet->fromArray([
            // ✅ Tambah kolom unitCost setelah warehouseName
            ['transDate', 'description', 'itemAdjustmentType', 'itemNo', 'quantity', 'itemUnitName', 'warehouseName', 'unitCost'],
            ['03/11/2025', 'TEST POSTMAN ITEM ADJUSTMENT', 'ADJUSTMENT_OUT', '1004.39', 50, 'grm', 'KITCHEN', ''],
            ['',                 '',                     'ADJUSTMENT_OUT', '1003.09', 50, 'btr', 'KITCHEN', ''],
            ['',                 '',                     'ADJUSTMENT_OUT', '100185',  50, 'grm', 'KITCHEN', ''],
            ['',                 '',                     'ADJUSTMENT_IN',  '100185', 350, 'grm', 'KITCHEN', 40000],
        ]);

        // ================================
        // 🔥 SET FORMAT TANGGAL dd/mm/yyyy
        // ================================
        // Format kolom A seluruh baris → format tanggal Excel
        $sheet->getStyle('A:A')
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);

        // Optional: jadikan nilai di baris contoh sebagai date integer Excel
        foreach ([2] as $row) {
            $dateString = $sheet->getCell("A{$row}")->getValue(); // 03/11/2025
            if ($dateString) {
                $excelDate = \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($dateString);
                $sheet->setCellValue("A{$row}", $excelDate);
            }
        }

        // ✅ Optional: format angka untuk unitCost (kolom H)
        $sheet->getStyle('H:H')
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER);

        // Buat file
        $writer = new Xlsx($spreadsheet);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($tmp);

        return response()
            ->download($tmp, 'template_item_adjustment.xlsx')
            ->deleteFileAfterSend(true);
    }
}
