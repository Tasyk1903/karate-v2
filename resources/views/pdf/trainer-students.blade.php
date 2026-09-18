<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        p { color: #6b7280; margin: 0 0 18px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; text-align: center; }
        td:first-child { text-align: center; width: 36px; }
    </style>
</head>
<body>
    <h1>{{ __('exports.trainer_students') }}</h1>
    <p>{{ __('exports.coach') }}: {{ $trainerName }}</p>

    <table>
        <thead>
            <tr>
                <th>№</th>
                <th>{{ __('exports.name') }}</th>
                <th>{{ __('exports.age') }}</th>
                <th>{{ __('exports.weight') }}</th>
                <th>{{ __('exports.rank') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($students as $index => $student)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ trim($student->last_name . ' ' . $student->first_name) }}</td>
                    <td>{{ \App\Services\Exports\ExportLabels::age($student->birthday) ?? '-' }}</td>
                    <td>{{ $student->weight ? $student->weight . ' '.__('exports.kg') : '-' }}</td>
                    <td>{{ \App\Services\Exports\ExportLabels::rank($student->rang) ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">{{ __('exports.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
