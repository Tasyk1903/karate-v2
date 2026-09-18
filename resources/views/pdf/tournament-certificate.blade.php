<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><style>
@page {margin:12mm;} body {font-family:DejaVu Sans,sans-serif;font-size:11px;color:#171717;}
h1 {font-size:17px;text-align:center;margin:5mm 0;} table.data {width:100%;border-collapse:collapse;table-layout:fixed;}
.data td,.data th {border:0.2mm solid #aaa;padding:3mm;vertical-align:top;text-align:left;} .data th {width:34%;}
.logo {max-height:18mm;max-width:50mm;}
</style></head><body>
@if($logoPath)<img class="logo" src="{{ $logoPath }}" alt="">@endif
<h1>{{ __('exports.certificate') }}</h1>
<table class="data">
@foreach(['competition_name'=>$tournament->championship?->name.' / '.$tournament->name, 'dates'=>$datesText, 'venue'=>$venue, 'chief_judge'=>$tournament->chief_judge, 'chief_secretary'=>$tournament->chief_secretary] as $key=>$value)
<tr><th>{{ __('exports.'.$key) }}</th><td>{{ $value ?: '-' }}</td></tr>
@endforeach
<tr><th>{{ __('exports.participant_total') }}</th><td>{{ $totalParticipants }}
@foreach($byGroups as $label=>$count)<div>{{ $label }}: {{ $count }}</div>@endforeach
</td></tr>
<tr><th>{{ __('exports.regions_count') }}</th><td>{{ $subjectsCount }}</td></tr>
<tr><th>{{ __('exports.regions') }}</th><td>{{ implode(', ', $subjects) ?: '-' }}</td></tr>
</table>
@include('pdf.partials.signatures')
</body></html>
