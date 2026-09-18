<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ChampionshipParticipantsExport implements FromCollection, WithColumnWidths, WithCustomValueBinder, WithStyles
{
    use SafeSpreadsheetValues;

    public function __construct(private readonly Collection $participants) {}

    public function collection(): Collection
    {
        $rows = collect();

        $this->participants
            ->groupBy('coach_id')
            ->each(function (Collection $participants) use ($rows): void {
                $coachName = $participants->first()['coach_name'] ?: __('exports.no_coach');

                $rows->push([__('exports.team_heading', ['coach' => $coachName]), '', '', '', '', '']);
                $rows->push([__('exports.first_name'), __('exports.last_name'), __('exports.age'), __('exports.disciplines_count'), __('exports.disciplines'), __('exports.rank')]);

                $participants
                    ->sortBy([
                        ['last_name', 'asc'],
                        ['first_name', 'asc'],
                    ])
                    ->each(function (array $participant) use ($rows): void {
                        $rows->push([
                            $participant['first_name'],
                            $participant['last_name'],
                            $participant['age'] ?? '-',
                            $participant['disciplines_count'],
                            $participant['disciplines'],
                            $participant['rang'] ?: '-',
                        ]);
                    });

                $rows->push(['', '', '', '', '', '']);
            });

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        foreach ($sheet->getRowIterator() as $row) {
            $rowIndex = $row->getRowIndex();
            $cellValue = (string) $sheet->getCell("A{$rowIndex}")->getValue();

            if (str_starts_with($cellValue, strstr(__('exports.team_heading', ['coach' => ':coach']), ':coach', true))) {
                $sheet->mergeCells("A{$rowIndex}:F{$rowIndex}");
                $sheet->getStyle("A{$rowIndex}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);
            }

            if ($cellValue === __('exports.first_name')) {
                $sheet->getStyle("A{$rowIndex}:F{$rowIndex}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => [
                        'bottom' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                ]);
            }
        }

        $sheet->getStyle('A:F')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A:F')->getAlignment()->setWrapText(true);

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20,
            'B' => 30,
            'C' => 12,
            'D' => 22,
            'E' => 35,
            'F' => 15,
        ];
    }
}
