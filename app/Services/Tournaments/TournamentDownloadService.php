<?php

namespace App\Services\Tournaments;

use App\Exports\TournamentListExport;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Exports\KataPdf;
use App\Services\Exports\PdfRenderer;
use App\Services\MediaStorage;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class TournamentDownloadService
{
    public function download(Tournament $tournament, string $type)
    {
        return match ($type) {
            'brackets' => $this->downloadKumiteProtocols($tournament, false),
            'kata-tables' => $this->downloadKataProtocols($tournament, false),
            'kumite-protocols' => $this->downloadKumiteProtocols($tournament),
            'kata-protocols' => $this->downloadKataProtocols($tournament),
            'results' => $this->downloadOfficialResults($tournament),
            'certificate' => $this->downloadTournamentCertificate($tournament),
            'lists-excel' => Excel::download(
                new TournamentListExport($this->listTournamentsForReports($tournament)),
                "tournament-{$tournament->id}-lists.xlsx"
            ),
            'lists-pdf' => $this->downloadListsPdf($tournament),
        };
    }

    private function downloadKumiteProtocols(Tournament $tournament, bool $protocol = true)
    {
        $pools = $tournament->pools()->with(['student.coach', 'opponent.coach'])->orderBy('round')->orderBy('position_in_round')->orderBy('id')->get();
        $sortedLists = $tournament->listWhereExistPools()->with('templateStudentList')->orderBy('sort_order')->orderBy('id')->get();
        $people = User::with('coach')->whereIn('id', $pools->flatMap(fn ($p) => [$p->student_id, $p->opponent_id, $p->winner_id_1rd_robbin, $p->winner_id_2rd_robbin, $p->winner_id_3rd_robbin])->filter()->unique())->get()->keyBy('id');
        $filename = "tournament-{$tournament->id}-".($protocol ? 'kumite-protocols' : 'brackets').'.pdf';
        $labels = new BracketParticipantLabels($tournament, $people);
        $renderer = app(PdfRenderer::class);
        $logoSrc = $this->reportLogoSource($tournament);
        $documents = function () use ($sortedLists, $pools, $people, $protocol, $tournament, $filename, $renderer, $logoSrc, $labels) {
            foreach ($sortedLists as $list) {
                $firstCount = $pools->where('list_id', $list->id)->where('round', 1)->count();
                [$paper, $orientation, $width, $height] = match (true) {
                    $firstCount >= 16 => ['a2', 'portrait', 400, 510],
                    $firstCount >= 8 => ['a3', 'portrait', 277, 340],
                    default => ['a4', 'landscape', 277, 140],
                };
                yield $renderer->render($protocol ? 'pdf.report-bracket' : 'pdf.bracket', [
                    'tournament' => $tournament, 'sortedLists' => collect([$list]),
                    'poolsGroupedByListId' => $pools->groupBy('list_id'), 'people' => $people,
                    'logoSrc' => $logoSrc, 'protocol' => $protocol, 'participantLabels' => $labels,
                    'pageWidth' => $width, 'pageHeight' => $height,
                ], $filename, $paper, $orientation);
            }
        };
        abort_if($sortedLists->isEmpty(), 404);

        return $renderer->combine($documents(), $filename);
    }

    public function downloadKataProtocols(Tournament $tournament, bool $protocol = true, ?int $list = null)
    {
        return $this->pdfDownload($protocol ? 'pdf.kata-report' : 'pdf.kata-table-pdf', [
            'tournament' => $tournament,
            'sheets' => app(KataPdf::class)->sheets($tournament, $list),
            'logoSrc' => $this->reportLogoSource($tournament), 'protocol' => $protocol,
        ], "tournament-{$tournament->id}-".($protocol ? 'kata-protocols' : 'kata-tables').($list ? "-{$list}" : '').'.pdf');
    }

    private function downloadOfficialResults(Tournament $tournament)
    {
        $tournament->load([
            'pools' => fn ($query) => $query->where('tournament_id', $tournament->id),
            'pools.student.coach',
            'pools.opponent.coach',
            'pools.listTournament.templateStudentList',
        ]);

        $sortedLists = $tournament->listWhereExistPools()
            ->with('templateStudentList')
            ->orderBy('sort_order')
            ->get();
        $poolsByList = $tournament->pools->groupBy('list_id');
        $people = User::with('coach')->whereIn('id', $tournament->pools->flatMap(fn ($p) => [$p->winner_id_1rd_robbin, $p->winner_id_2rd_robbin, $p->winner_id_3rd_robbin])->filter()->unique())->get()->keyBy('id');
        $rows = [];

        foreach ($sortedLists as $list) {
            $pools = $poolsByList->get($list->id, collect());
            $final = $pools->firstWhere('type', 'final');
            $third = $pools->firstWhere('type', '3rd');
            $roundRobin = $pools->firstWhere('type', 'Round Robin');
            $gold = $silver = $bronze = null;

            if ($final && $final->winner_id) {
                $gold = (int) $final->student_id === (int) $final->winner_id ? $final->student : $final->opponent;
                $silver = (int) $final->student_id === (int) $final->winner_id ? $final->opponent : $final->student;
                if ($tournament->fight_for_third_place && $third && $third->winner_id) {
                    $bronze = (int) $third->student_id === (int) $third->winner_id ? $third->student : $third->opponent;
                }
            } elseif ($roundRobin) {
                $map = fn ($id) => ! $id ? null : match ((int) $id) {
                    (int) $roundRobin->student_id => $roundRobin->student,
                    (int) $roundRobin->opponent_id => $roundRobin->opponent,
                    default => $people->get($id),
                };
                $gold = $map($roundRobin->winner_id_1rd_robbin ?? null);
                $silver = $map($roundRobin->winner_id_2rd_robbin ?? null);
                $bronze = $map($roundRobin->winner_id_3rd_robbin ?? null);
            }

            if (! ($gold || $silver || $bronze)) {
                continue;
            }

            $rows[] = [
                'title' => $list->templateStudentList?->name ?? $list->name ?? __('exports.category'),
                'gold' => $gold,
                'silver' => $silver,
                'bronze' => $bronze,
            ];
        }

        if ($tournament->tournament_type === Tournament::KATA && $tournament->tournament_type_kata === Tournament::POINT_SYSTEM) {
            $rows = [];
            foreach (app(KataPdf::class)->sheets($tournament) as $sheet) {
                $row = ['title' => $sheet['listTournament']->templateStudentList?->name ?? __('exports.category')];
                foreach ([1 => 'gold', 2 => 'silver', 3 => 'bronze'] as $place => $key) {
                    $winners = $sheet['kataPools']->where('round', 'FINAL')->filter(fn ($p) => $p->{'winner_'.$place});
                    $row[$key] = $winners->flatMap(fn ($p) => $p->students
                        ? collect($p->students)->map(fn ($id) => $sheet['groupStudents']->get($id))->filter()
                        : collect([$p->student])->filter());
                }
                if ($row['gold']->isNotEmpty() || $row['silver']->isNotEmpty() || $row['bronze']->isNotEmpty()) {
                    $rows[] = $row;
                }
            }
        }

        return $this->pdfDownload('pdf.official-results', [
            'tournament' => $tournament,
            'rows' => $rows,
            'logoPath' => $this->reportLogoSource($tournament),
        ], "tournament-{$tournament->id}-official-results.pdf");
    }

    private function downloadTournamentCertificate(Tournament $tournament)
    {
        $tournament->loadMissing('students');
        $allStudents = $tournament->students->unique('id');
        $referenceDate = Carbon::parse($tournament->date_finish ?? $tournament->date);
        $genderWord = function (string $gender, int $high): string {
            $gender = strtolower($gender);
            if ($gender === 'm') {
                return __($high <= 11 ? 'exports.boys' : ($high <= 15 ? 'exports.young_men' : 'exports.juniors_m'));
            }

            return __($high <= 11 ? 'exports.girls' : ($high <= 15 ? 'exports.young_women' : 'exports.juniors_f'));
        };
        $byGroups = [];

        foreach ($allStudents as $student) {
            if (! $student->birthday || ! $student->gender) {
                continue;
            }

            $age = Carbon::parse($student->birthday)->diff($referenceDate)->y;
            $low = $age % 2 === 1 ? $age : $age - 1;
            $low = max(0, $low);
            $high = $low + 1;
            $label = $genderWord($student->gender, $high)." {$low}-{$high} ".__('exports.years');
            $byGroups[$label] = ($byGroups[$label] ?? 0) + 1;
        }

        uksort($byGroups, function (string $first, string $second): int {
            preg_match('/(\d+)[–-](\d+)/u', $first, $firstMatches);
            preg_match('/(\d+)[–-](\d+)/u', $second, $secondMatches);

            return ((int) ($firstMatches[1] ?? 0)) <=> ((int) ($secondMatches[1] ?? 0))
                ?: ((int) ($firstMatches[2] ?? 0)) <=> ((int) ($secondMatches[2] ?? 0));
        });

        $subjects = $allStudents
            ->map(fn (User $user) => $user->subject ?? $user->region ?? $user->state ?? $user->city ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $this->pdfDownload('pdf.tournament-certificate', [
            'tournament' => $tournament,
            'datesText' => Carbon::parse($tournament->date)->translatedFormat('d F Y').' - '.Carbon::parse($tournament->date_finish)->translatedFormat('d F Y'),
            'venue' => $tournament->address,
            'totalParticipants' => $allStudents->count(),
            'byGroups' => $byGroups,
            'subjects' => $subjects,
            'subjectsCount' => count($subjects),
            'logoPath' => $this->reportLogoSource($tournament),
        ], "tournament-{$tournament->id}-certificate.pdf");
    }

    private function downloadListsPdf(Tournament $tournament)
    {
        return $this->pdfDownload('pdf.report-pdf', [
            'listTournaments' => $this->listTournamentsForReports($tournament),
            'logoSrc' => $this->reportLogoSource($tournament),
        ], "tournament-{$tournament->id}-lists.pdf", 'a4', 'landscape');
    }

    private function listTournamentsForReports(Tournament $tournament)
    {
        return ListTournament::query()
            ->where('tournament_id', $tournament->id)
            ->whereHas('tournamentStudentLists.student.coach')
            ->with(['tournament', 'templateStudentList', 'tournamentStudentLists' => fn ($q) => $q->whereHas('student.coach'), 'tournamentStudentLists.student.coach'])
            ->orderBy('sort_order')
            ->get();
    }

    private function pdfDownload(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait')
    {
        return app(PdfRenderer::class)->render($view, $data, $filename, $paper, $orientation);
    }

    private function reportLogoSource(Tournament $tournament): ?string
    {
        return app(MediaStorage::class)->imageDataUri($tournament->logo_report);
    }
}
