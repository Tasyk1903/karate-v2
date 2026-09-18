<?php

namespace App\Exports;

use App\Models\Examination;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExaminationStudentsExport implements FromCollection, ShouldAutoSize, WithCustomStartCell, WithEvents, WithStrictNullComparison, WithStyles
{
    private ?Collection $studentsCache = null;

    public function __construct(
        private readonly Examination $examination,
        private readonly ?int $coachId = null,
        private readonly ?int $organizationId = null,
    ) {}

    public function startCell(): string
    {
        return 'A7';
    }

    public function collection(): Collection
    {
        $rows = collect();
        $number = 1;

        foreach ($this->studentsForExport() as $student) {
            $currentKyu = (int) filter_var((string) $student->rang, FILTER_SANITIZE_NUMBER_INT);
            $nextKyu = $currentKyu === 0 ? 10 : $currentKyu - 1;
            $coach = $student->coach;
            $coachName = $coach
                ? trim($coach->last_name.' '.mb_substr($coach->first_name ?? '', 0, 1).'. '.($coach->patronymic ? mb_substr($coach->patronymic, 0, 1).'.' : ''))
                : '';

            $rows->push([
                $number++,
                trim($student->last_name.' '.$student->first_name),
                $student->birthday,
                $student->city_training,
                $coachName,
                $currentKyu,
                $nextKyu,
                $student->number_brand,
                $student->number_iko,
                $student->number_certificate,
                $student->last_examination_date.' '.$student->last_examination_city,
                $student->last_receiving,
            ]);
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            6 => [
                'font' => ['bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:D1');
                $sheet->setCellValue('A1', 'КЮ-ТЕСТ');
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 48],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->mergeCells('A2:B2');
                $sheet->mergeCells('C2:D2');
                $sheet->setCellValue('A2', "Место проведения\n(субъект РФ, город)");
                $sheet->setCellValue('C2', $this->examination->city);
                $sheet->getRowDimension(2)->setRowHeight(50);

                $sheet->mergeCells('A3:B3');
                $sheet->mergeCells('C3:D3');
                $sheet->setCellValue('A3', 'Дата');
                $sheet->setCellValue('C3', $this->examination->date?->format('d.m.Y'));

                $sheet->mergeCells('A4:B4');
                $sheet->mergeCells('C4:D4');
                $sheet->setCellValue('A4', 'Принимающий БЧ');
                $sheet->setCellValue('C4', $this->examination->receiving);
                $sheet->setCellValue('A5', '');

                foreach ([
                    'A6' => '№ п/п',
                    'B6' => 'Фамилия, имя',
                    'C6' => 'Дата рождения',
                    'D6' => "Субъект РФ, город,\nв котором тренируется\nспортсмен",
                    'E6' => 'Инструктор спортсмена',
                    'F6' => 'Кю имеет',
                    'G6' => 'Кю сдаёт',
                    'H6' => "Номер марки\nежегодного\nчленского взноса",
                    'I6' => "Номер карточки\nИКО",
                    'J6' => "Номер сертификата\nна предыдущий кю",
                    'K6' => "Дата и место\nэкзамена на\nпредыдущий кю",
                    'L6' => "БЧ принимавший\nэкзамен на\nпредыдущий кю",
                ] as $cell => $text) {
                    $sheet->setCellValue($cell, $text);
                }

                $boldLabel = [
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                ];
                $sheet->getStyle('A2:B4')->applyFromArray($boldLabel);
                $sheet->getStyle('A6:L6')->applyFromArray([
                    'font' => ['bold' => true],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ]);
                $sheet->getRowDimension(6)->setRowHeight(60);

                $border = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ];
                $sheet->getStyle('A1:D1')->applyFromArray($border);
                $sheet->getStyle('A2:D4')->applyFromArray($border);
                $sheet->getStyle('A6:L6')->applyFromArray($border);

                $students = $this->studentsForExport()->values();
                $lastRow = 6 + $students->count();

                if ($lastRow >= 7) {
                    $sheet->getStyle("A7:L{$lastRow}")->applyFromArray($border);
                    $sheet->getStyle("A7:L{$lastRow}")->applyFromArray([
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                    ]);
                }

                $duplicateStyle = [
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FF9999'],
                    ],
                ];

                foreach (['H' => 'number_brand', 'I' => 'number_iko', 'J' => 'number_certificate'] as $column => $field) {
                    $duplicates = $students->pluck($field)
                        ->filter()
                        ->map(fn ($value) => trim((string) $value))
                        ->countBy()
                        ->filter(fn (int $count) => $count > 1)
                        ->keys();

                    foreach ($students as $index => $student) {
                        $value = trim((string) $student->{$field});
                        if ($value !== '' && $duplicates->contains($value)) {
                            $sheet->getStyle($column.(7 + $index))->applyFromArray($duplicateStyle);
                        }
                    }
                }
            },
        ];
    }

    private function studentsForExport(): Collection
    {
        return $this->studentsCache ??= $this->examination
            ->students()
            ->when($this->organizationId, fn ($query) => $query->where('users.organization_id', $this->organizationId))
            ->when($this->coachId, fn ($query) => $query->where('users.coach_id', $this->coachId))
            ->with('coach:id,first_name,last_name,patronymic')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->get();
    }
}
