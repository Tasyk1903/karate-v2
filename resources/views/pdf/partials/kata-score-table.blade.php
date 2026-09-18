<h2>{{ $stageTitle }}</h2>
<div class="table-wrap">
    <table>
        <colgroup>
            <col style="width: 7%;">
            <col style="width: 29%;">
            @foreach($scoreLabels as $label)
                <col style="width: 7%;">
            @endforeach
            <col style="width: 8%;">
            <col style="width: 7%;">
            <col style="width: 7%;">
            <col style="width: 7%;">
        </colgroup>
        <thead>
        <tr>
            <th class="number">№</th>
            <th class="participant">{{ __('exports.name') }}</th>
            @foreach($scoreLabels as $label)
                <th class="score">{{ $label }}</th>
            @endforeach
            <th class="score">{{ __('exports.total') }}</th>
            <th class="score">Min</th>
            <th class="score">Max</th>
            <th class="place">{{ __('exports.place') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($rows as $pool)
            <tr>
                <td class="number">{{ $pool->participant_number ?: '-' }}</td>
                <td class="participant">
                    @foreach($poolStudents($pool) as [$name, $club])
                        <strong>{{ $name }}</strong>
                        <small>{{ $club }}</small>
                    @endforeach
                </td>
                @foreach($scoreFields as $field)
                    <td class="score">{{ $formatScore($pool->{$field}) }}</td>
                @endforeach
                <td class="score">{{ $formatScore($pool->total_score) }}</td>
                <td class="score">{{ $formatScore($pool->min_score) }}</td>
                <td class="score">{{ $formatScore($pool->max_score) }}</td>
                <td class="place">{{ $pool->rank ?: '-' }}</td>
            </tr>
        @empty
            <tr><td class="empty" colspan="11">{{ __('exports.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
