<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('exports.lists') }}</title>
    <style>
        @page { margin:10mm; size:A4 landscape; }
        body { margin:0; }
        * {
            font-family: DejaVu Sans;
            font-size: 10px;
        }

        table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            border: 1px solid black;
            padding: 2mm;
            overflow-wrap:break-word;
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
        }

        .section-title {
            font-weight: bold;
            text-align: center;
            padding: 10px 0;
            font-size: 1.2em;
        }

        .report-logo {
            text-align: center;
            margin-bottom: 8px;
        }

        .report-logo img {
            max-height: 70px;
            width: auto;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>

{{--<h2 class="section-title">ПРОТОКОЛ РЕГИСТРАЦИИ УЧАСТНИКОВ СОРЕВНОВАНИЙ</h2>--}}

@foreach($listTournaments as $index => $listTournament)
    @if ($index > 0)
        <div class="page-break"></div>
    @endif

    @if(!empty($logoSrc))
        <div class="report-logo">
            <img src="{{ $logoSrc }}" alt="">
        </div>
    @endif
    <h3 class="section-title">
        {{ mb_strtoupper($listTournament->templateStudentList?->name ?? __('exports.category')) }}
    </h3>

    <table>
        <thead>
        <tr>
            <th style="width:4%">#</th>
            <th style="width:24%">{{ __('exports.participant') }}</th>
            <th style="width:5%">{{ __('exports.age') }}</th>
            <th>{{ __('exports.sport_rank') }}</th>
            <th style="width:7%">{{ __('exports.rank') }}</th>
            @if($listTournament->tournament->tournament_type != \App\Models\Tournament::KATA)
            <th style="width:7%">{{ __('exports.weight') }}</th>
            @endif
            <th style="width:20%">{{ __('exports.club') }}</th>
            <th style="width:19%">{{ __('exports.coach') }}</th>
        </tr>
        </thead>
        <tbody>
        @php
            $counter = 1;
            $studentsByGroup = [];
        @endphp

        @foreach($listTournament->tournamentStudentLists->sortBy(fn($s) => $s->student?->coach?->last_name) as $studentList)

            @php
                $groupId = $studentList->group_id ?? $studentList->id;
                $studentsByGroup[$groupId][] = $studentList;
            @endphp
        @endforeach

        @foreach($studentsByGroup as $group)
            @php
                $firstStudent = $group[0];

                // Объединяем ФИО всех участников группы в одну ячейку
                $studentNames = implode("<br>", array_map(function ($s) {
                    return e(mb_strtoupper((string) $s->student?->last_name) . ' ' . $s->student?->first_name);
                }, $group));

                $ages = implode("<br>", array_map(fn($s) => \App\Services\Exports\ExportLabels::age($s->student?->birthday) , $group));
                $weights = implode("<br>", array_map(fn($s) => $s->student?->weight . ' '.__('exports.kg'), $group));
                $rang = implode("<br>", array_map(fn($s) => e(\App\Services\Exports\ExportLabels::rank($s->student?->rang)), $group));
                $razriad = implode("<br>", array_map(function ($s) {
                    $value = trim((string) ($s->student?->razriad ?? ''));
                    return e($value !== '' ? $value : '-');
                }, $group));
            @endphp

            <tr>
                <td>{{ $counter++ }}</td>
                <td style="overflow-wrap: break-word;">{!! $studentNames !!}</td>
                <td>{!! $ages !!}</td>
                <td>{!! $razriad !!}</td>
                <td>{!! $rang !!}</td>
                @if($listTournament->tournament->tournament_type != \App\Models\Tournament::KATA)
                <td >{!! $weights !!}</td>
                @endif
                <td>@foreach($group as $member){{ $member->student?->coach?->club }}@unless($loop->last)<br>@endunless @endforeach</td>
                <td>@foreach($group as $member){{ $member->student?->coach_short }}@unless($loop->last)<br>@endunless @endforeach</td>

            </tr>
        @endforeach

        </tbody>
    </table>
    <br><br>
@endforeach

</body>
</html>
