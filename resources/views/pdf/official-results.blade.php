<!doctype html><html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><style>
@page { margin:12mm; } body { font-family:DejaVu Sans,sans-serif; font-size:11px; color:#171717; }
h1 {font-size:17px;text-align:center;margin:4mm 0;} h2 {font-size:12px;text-align:center;font-weight:normal;}
.category {page-break-inside:avoid;margin:5mm 0;} h3 {font-size:12px;margin:0 0 2mm;}
table.results {width:100%;border-collapse:collapse;} .results td {padding:2mm;border-bottom:0.2mm solid #ddd;vertical-align:top;}
small {color:#555;} .logo {max-height:18mm;max-width:50mm;}
</style></head><body>
@if($logoPath)<div style="text-align:center"><img class="logo" src="{{ $logoPath }}" alt=""></div>@endif
<h1>{{ __('exports.official_results') }}</h1><h2>{{ $tournament->name }} · {{ $tournament->date?->format('d.m.Y') }}</h2>
@forelse($rows as $row)
<div class="category"><h3>{{ $row['title'] }}</h3><table class="results">
@foreach(['gold','silver','bronze'] as $key)
@php $winners = $row[$key] instanceof \Illuminate\Support\Collection ? $row[$key] : collect([$row[$key]])->filter(); @endphp
@if($winners->isNotEmpty())
<tr><td style="width:8%">{{ $loop->iteration }}</td><td>
@foreach($winners as $person){{ $person->full_name }}<br><small>{{ $person->coach?->club }}{{ $person->city ? ' · '.$person->city : '' }}</small>@unless($loop->last)<br>@endunless @endforeach
</td></tr>
@endif
@endforeach
</table></div>
@empty<p>{{ __('exports.empty') }}</p>@endforelse
@include('pdf.partials.signatures')
</body></html>
