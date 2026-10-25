<?php

namespace App\Services\Tables;

use App\Models\Company;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color as XlColor;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Convierte cualquier tabla de la aplicación a CSV, Excel o PDF.
 * Excel y PDF salen con el logo y los colores de la compañía; el CSV es solo datos.
 *
 * Una tabla es: ['title', 'subtitle', 'columns' => [['h' => encabezado, 't' => tipo]], 'rows' => [[...]], 'total' => [...]|null]
 * Tipos de columna: text, int, hours, money, date, bool.
 */
class TableExporter
{
    public const MAX_ROWS = 20000;

    public function render(Company $company, array $table, string $format): array
    {
        $table['rows'] = array_slice($table['rows'], 0, self::MAX_ROWS);
        $slug = \Illuminate\Support\Str::slug($table['title']) ?: 'tabla';
        $stamp = Carbon::now($company->timezone)->format('Ymd-His');
        return match ($format) {
            'csv' => ['body' => $this->csv($table), 'mime' => 'text/csv; charset=UTF-8', 'name' => "$slug-$stamp.csv"],
            'xlsx' => ['body' => $this->xlsx($company, $table), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'name' => "$slug-$stamp.xlsx"],
            default => ['body' => $this->pdf($company, $table), 'mime' => 'application/pdf', 'name' => "$slug-$stamp.pdf"],
        };
    }

    /** Valor como texto legible (PDF) según el tipo de la columna. */
    public function display(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return match ($type) {
            'hours' => number_format((float) $v, 2),
            'money' => '$'.number_format((float) $v, 2),
            'int' => (string) (int) $v,
            'bool' => $v ? __('Sí') : __('No'),
            default => (string) $v,
        };
    }

    // ---------------------------------------------------------------- CSV

    private function csv(array $t): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM: Excel abre bien las tildes
        fputcsv($fh, array_column($t['columns'], 'h'), ',', '"', '');
        $rows = $t['rows'];
        if (! empty($t['total'])) {
            $rows[] = $t['total'];
        }
        foreach ($rows as $r) {
            $line = [];
            foreach ($t['columns'] as $i => $c) {
                $v = $r[$i] ?? null;
                $line[] = $this->csvSafe(match ($c['t']) {
                    'hours', 'money' => $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', ''),
                    'bool' => $v === null || $v === '' ? '' : ($v ? __('Sí') : __('No')),
                    default => (string) ($v ?? ''),
                });
            }
            fputcsv($fh, $line, ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** Evita que Excel ejecute una celda que empieza con = + - @ (inyección de fórmulas) en texto libre. */
    private function csvSafe(string $s): string
    {
        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && ! is_numeric($s) ? "'".$s : $s;
    }

    // ---------------------------------------------------------------- Excel

    private function xlsx(Company $company, array $t): string
    {
        $primary = ltrim($company->color_primary ?: '#1f4e79', '#');
        $book = new Spreadsheet();
        $s = $book->getActiveSheet();
        $s->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $t['title']), 0, 31) ?: __('Tabla'));
        $ncols = count($t['columns']);
        $lastCol = Coordinate::stringFromColumnIndex(max($ncols, 1));

        $row = 1;
        $logo = $this->logoPath($company);
        if ($logo) {
            $d = new Drawing();
            $d->setPath($logo);
            $d->setHeight(40);
            $d->setCoordinates('A1');
            $d->setWorksheet($s);
            $s->getRowDimension(1)->setRowHeight(34);
            $row = 2;
        }
        $s->setCellValue([1, $row], $company->displayName());
        $s->getStyle([1, $row])->getFont()->setBold(true)->setSize(10)->getColor()->setRGB($primary);
        $row++;
        $s->setCellValue([1, $row], $t['title']);
        $s->getStyle([1, $row])->getFont()->setBold(true)->setSize(15)->getColor()->setRGB($primary);
        $row++;
        if (! empty($t['subtitle'])) {
            $s->setCellValue([1, $row], $t['subtitle']);
            $s->getStyle([1, $row])->getFont()->getColor()->setRGB('666666');
            $row++;
        }
        $row++;

        $headRow = $row;
        foreach ($t['columns'] as $i => $c) {
            $s->setCellValue([$i + 1, $row], $c['h']);
        }
        $head = $s->getStyle("A{$row}:{$lastCol}{$row}");
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
        $row++;

        $rows = $t['rows'];
        $totalIndex = null;
        if (! empty($t['total'])) {
            $rows[] = $t['total'];
            $totalIndex = count($rows) - 1;
        }
        foreach ($rows as $n => $r) {
            foreach ($t['columns'] as $i => $c) {
                $v = $r[$i] ?? null;
                $coord = [$i + 1, $row];
                if ($v === null || $v === '') {
                    continue;
                }
                // En una columna de fechas puede venir texto (la fila de "Total"): se escribe como texto.
                $type = $c['t'] === 'date' && ! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) ? 'text' : $c['t'];
                switch ($type) {
                    case 'date':
                        $s->setCellValue($coord, XlDate::PHPToExcel(Carbon::parse($v)->toDateTime()));
                        $s->getStyle($coord)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                        break;
                    case 'hours':
                        $s->setCellValue($coord, (float) $v);
                        $s->getStyle($coord)->getNumberFormat()->setFormatCode('#,##0.00');
                        break;
                    case 'money':
                        $s->setCellValue($coord, (float) $v);
                        $s->getStyle($coord)->getNumberFormat()->setFormatCode('$#,##0.00');
                        break;
                    case 'int':
                        $s->setCellValue($coord, (int) $v);
                        break;
                    case 'bool':
                        $s->setCellValue($coord, $v ? __('Sí') : __('No'));
                        break;
                    default:
                        // Texto explícito: una celda que empiece con "=" no se evalúa como fórmula.
                        $s->setCellValueExplicit($coord, (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $s->getStyle($coord)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                }
            }
            if ($totalIndex === $n) {
                $tot = $s->getStyle("A{$row}:{$lastCol}{$row}");
                $tot->getFont()->setBold(true);
                $tot->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM)->getColor()->setRGB($primary);
            } elseif ($n % 2 === 1) {
                $s->getStyle("A{$row}:{$lastCol}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F4F6');
            }
            $row++;
        }
        foreach ($t['columns'] as $i => $c) {
            $s->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }
        $s->freezePane('A'.($headRow + 1));
        if ($company->email_footer) {
            $s->setCellValue([1, $row + 1], $company->email_footer);
            $s->getStyle([1, $row + 1])->getFont()->setSize(8)->getColor()->setRGB('888888');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tbl');
        (new Xlsx($book))->save($tmp);
        $body = file_get_contents($tmp);
        @unlink($tmp);
        return $body;
    }

    /** Ruta local del logo si Excel puede incrustarlo (png o jpg). */
    private function logoPath(Company $company): ?string
    {
        if (! $company->logo_path || ! Storage::disk('public')->exists($company->logo_path)) {
            return null;
        }
        $ext = strtolower(pathinfo($company->logo_path, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg'], true) ? Storage::disk('public')->path($company->logo_path) : null;
    }

    // ---------------------------------------------------------------- PDF

    private function pdf(Company $company, array $t): string
    {
        $logo = null;
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $logo = 'data:'.Storage::disk('public')->mimeType($company->logo_path).';base64,'.base64_encode(Storage::disk('public')->get($company->logo_path));
        }
        $html = $this->html($company, $t, $logo);
        $o = new Options();
        $o->set('isRemoteEnabled', false);
        $o->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($o);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', count($t['columns']) > 6 ? 'landscape' : 'portrait');
        $pdf->render();
        return $pdf->output();
    }

    public function html(Company $company, array $t, ?string $logo): string
    {
        return view('pdf.table', [
            't' => $t, 'brand' => $company->brand(), 'logo' => $logo, 'x' => $this,
            'when' => Carbon::now($company->timezone)->format('Y-m-d H:i'),
        ])->render();
    }
}
