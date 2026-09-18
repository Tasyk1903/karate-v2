
@php
    $grouped = $kataPools->contains(fn ($pool) => !empty($pool->students));
    $listName = $listTournament->templateStudentList?->name ?? __('exports.kata');
    $dateLabel = $tournament->date ? \Illuminate\Support\Carbon::parse($tournament->date)->format('d.m.Y') : '';
    $scoreFields = ['referee_score', 'judge1_score', 'judge2_score', 'judge3_score', 'judge4_score'];
    $scoreLabels = [__('exports.referee'), __('exports.judge1'), __('exports.judge2'), __('exports.judge3'), __('exports.judge4')];
    $formatScore = static function ($value): string {
        if ($value === null || $value === '') {
            return '-';
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        return is_numeric($normalized) ? number_format((float) $normalized, 1, app()->getLocale() === 'en' ? '.' : ',', '') : (string) $value;
    };
    $studentLine = static function ($student): array {
        if (! $student) {
            return [__('exports.bye'), '-'];
        }

        $name = trim(($student->last_name ?? '').' '.($student->first_name ?? '')) ?: __('exports.unnamed');
        $club = $student->coach?->club ?: '-';

        return [$name, $club];
    };
    $poolStudents = static function ($pool) use ($groupStudents, $studentLine): array {
        if (! empty($pool->students)) {
            return collect($pool->students)
                ->map(fn ($id) => $groupStudents->get((int) $id))
                ->filter()
                ->map(fn ($student) => $studentLine($student))
                ->values()
                ->all();
        }

        return [$studentLine($pool->student)];
    };
    $preliminaryPools = $kataPools->where('round', 'PRELIMINARY STAGE')->values();
    $finalPools = $kataPools->where('round', 'FINAL')->values();
    $resultPools = $finalPools->filter(fn ($pool) => $pool->winner_1 || $pool->winner_2 || $pool->winner_3)->values();
@endphp

<header class="header">
    @if($logoSrc)
        <img class="logo" src="{{ $logoSrc }}" alt="">
    @endif
    <h1>{{ $listName }}</h1>
    <div class="subtitle">{{ $tournament->name }}{{ $dateLabel ? ' · '.$dateLabel : '' }}</div>
</header>

<section class="section">
    @include('pdf.partials.kata-score-table', ['rows' => $preliminaryPools, 'stageTitle' => __('exports.preliminary')])
</section>

@if($finalPools->isNotEmpty())
    <section class="section {{ $grouped ? 'separate-stage' : '' }}">
        @include('pdf.partials.kata-score-table', ['rows' => $finalPools, 'stageTitle' => __('exports.final')])
    </section>
@endif

@if($resultPools->isNotEmpty())
    <section class="section results {{ $grouped ? 'separate-stage' : '' }}">
        <h2>{{ __('exports.results') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="place">{{ __('exports.place') }}</th>
                    <th class="participant">{{ __('exports.participant') }}</th>
                    <th class="score">{{ __('exports.score') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($resultPools as $pool)
                    @foreach([1 => '1', 2 => '2', 3 => '3'] as $winnerField => $placeLabel)
                        @if($pool->{'winner_'.$winnerField})
                            <tr>
                                <td class="place">{{ $placeLabel }}</td>
                                <td class="participant">
                                    @foreach($poolStudents($pool) as [$name, $club])
                                        <strong>{{ $name }}</strong>
                                        <small>{{ $club }}</small>
                                    @endforeach
                                </td>
                                <td class="score">{{ $formatScore($pool->total_score) }}</td>
                            </tr>
                        @endif
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif

@if($protocol ?? false) @include('pdf.partials.signatures') @endif
