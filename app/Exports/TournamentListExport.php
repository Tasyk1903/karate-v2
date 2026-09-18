<?php

namespace App\Exports;

use App\Services\Exports\ExportLabels;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TournamentListExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithEvents, WithStyles
{
    public function __construct(private readonly iterable $listTournaments) {}

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function collection(): Collection
    {
        $rows = [];

        foreach ($this->listTournaments as $listTournament) {
            $rows[] = [$listTournament->templateStudentList?->name ?? __('exports.category'), '', '', '', '', '', '', ''];
            $rows[] = [__('exports.number'), __('exports.participant'), __('exports.age'), __('exports.sport_rank'), __('exports.rank'), __('exports.weight'), __('exports.club'), __('exports.coach')];

            $studentsByGroup = [];
            foreach ($listTournament->tournamentStudentLists as $studentList) {
                $studentsByGroup[$studentList->group_id ?? $studentList->id][] = $studentList;
            }

            $counter = 1;
            foreach ($studentsByGroup as $group) {
                $rows[] = [
                    $counter++,
                    implode("\n", array_map(fn ($row) => trim(mb_strtoupper((string) ($row->student?->last_name ?? '')).' '.($row->student?->first_name ?? '')), $group)),
                    implode("\n", array_map(fn ($row) => ExportLabels::age($row->student?->birthday) ?? '', $group)),
                    implode("\n", array_map(fn ($row) => filled($row->student?->razriad) ? $row->student?->razriad : '-', $group)),
                    implode("\n", array_map(fn ($row) => ExportLabels::rank($row->student?->rang), $group)),
                    implode("\n", array_map(fn ($row) => $row->student?->weight ? $row->student?->weight.' '.__('exports.kg') : '', $group)),
                    collect($group)->map(fn ($row) => $row->student?->coach?->club ?? '')->filter()->unique()->implode("\n"),
                    collect($group)->map(fn ($row) => $row->student?->coach_short ?? '')->filter()->unique()->implode("\n"),
                ];
            }
        }

        return new Collection($rows);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet;
                $row = 1;

                foreach ($this->listTournaments as $listTournament) {
                    $sheet->mergeCells("A{$row}:H{$row}");
                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 14],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                        ],
                    ]);
                    $row++;

                    $sheet->getStyle("A{$row}:H{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                    ]);
                    $row++;

                    $groupsCount = $listTournament->tournamentStudentLists
                        ->groupBy(fn ($studentList) => $studentList->group_id ?? $studentList->id)
                        ->count();

                    if ($groupsCount > 0) {
                        $endRow = $row + $groupsCount - 1;
                        $sheet->getStyle("A{$row}:H{$endRow}")->applyFromArray([
                            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                            'alignment' => [
                                'vertical' => Alignment::VERTICAL_CENTER,
                                'horizontal' => Alignment::HORIZONTAL_LEFT,
                                'wrapText' => true,
                            ],
                        ]);
                        $row = $endRow + 1;
                    }
                }
            },
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(34);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(14);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(22);
        $sheet->getColumnDimension('H')->setWidth(24);

        return [
            'A:H' => [
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
            ],
        ];
    }
}
