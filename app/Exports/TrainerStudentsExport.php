<?php

namespace App\Exports;

use App\Models\User;
use App\Services\Exports\ExportLabels;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TrainerStudentsExport implements FromGenerator, ShouldAutoSize, WithCustomValueBinder, WithEvents, WithHeadings, WithStyles
{
    use SafeSpreadsheetValues;

    public function __construct(
        private readonly User $trainer,
        private readonly ?int $tournamentId = null,
    ) {}

    public function generator(): \Generator
    {
        yield from User::query()
            ->select(['id', 'first_name', 'last_name', 'birthday', 'weight', 'rang'])
            ->where('coach_id', $this->trainer->id)
            ->when($this->tournamentId, fn (Builder $query) => $query->whereExists(function ($subQuery): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('student_tournaments')
                    ->whereColumn('student_tournaments.student_id', 'users.id')
                    ->where('student_tournaments.tournament_id', $this->tournamentId);
            }))
            ->orderBy('created_at')
            ->orderBy('id')
            ->lazy(500)
            ->values()
            ->map(fn (User $student, int $index) => [
                $index + 1,
                trim($student->last_name.' '.$student->first_name),
                ExportLabels::age($student->birthday) ?? '-',
                $student->weight ? $student->weight.' '.__('exports.kg') : '-',
                ExportLabels::rank($student->rang) ?: '-',
            ]);
    }

    public function headings(): array
    {
        return ['№', __('exports.name'), __('exports.age'), __('exports.weight'), __('exports.rank')];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet;
                $highestRow = $sheet->getHighestRow();
                $sheet->getStyle("A1:E{$highestRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                ]);
            },
        ];
    }
}
