<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8">
<style>
@page { margin:10mm; }
body { margin:0; color:#171717; font-family:DejaVu Sans,sans-serif; font-size:10px; line-height:1.15; }
.page { page-break-before:always; } .page:first-child { page-break-before:auto; }
h1 { font-size:15px; margin:0 0 2mm; } h2 { font-size:11px; margin:0 0 2mm; font-weight:normal; }
.header { text-align:center; height:22mm; } .header img { max-height:10mm; max-width:40mm; }
.grid { position:relative; } .line { position:absolute; background:#999; }
.match { position:absolute; border:0.2mm solid #777; background:#fff; }
.match-head { font-size:8px; background:#eee; padding:0.6mm 1mm; }
.person { padding:0.5mm 1mm; border-top:0.15mm solid #ccc; }
.person strong { display:block; font-size:11px; font-weight:bold; }
.person small { display:block; font-size:9px; color:#444; }
.winner { background:#edf5ee; } .absent { text-decoration:line-through; }
table.rr { width:100%; border-collapse:collapse; }
.rr td,.rr th { border:0.2mm solid #aaa; padding:3mm; text-align:left; }
</style></head><body>
@forelse($sortedLists as $list)
@php
$pools = $poolsGroupedByListId->get($list->id, collect());
$robin = $pools->contains('type', 'Round Robin');
$layout = app(\App\Services\Exports\BracketPdfLayout::class)->layout($pools, $pageWidth, $pageHeight);
@endphp
<div class="page">
<div class="header">
@if($logoSrc)<img src="{{ $logoSrc }}" alt="">@endif
<h1>{{ $list->templateStudentList?->name ?? __('exports.category') }}</h1>
<h2>{{ $tournament->name }} · {{ $tournament->date?->format('d.m.Y') }} · {{ __($protocol ? 'exports.kumite_protocols' : 'exports.brackets') }}</h2>
</div>
@if($robin)
<h2>{{ __('exports.round_robin') }}</h2>
<table class="rr"><thead><tr><th>{{ __('exports.fight') }}</th><th>{{ __('exports.participant') }}</th><th>{{ __('exports.participant') }}</th><th>{{ __('exports.winner') }}</th></tr></thead><tbody>
@foreach($pools as $pool)
<tr><td>{{ $pool->tatami_and_fight_number ?: $loop->iteration }}</td>
@foreach([$pool->student,$pool->opponent] as $person)
<td>{{ $person?->full_name ?: __('exports.bye') }}<br>{{ $participantLabels->affiliation($person) }}</td>
@endforeach
<td>{{ $people->get($pool->winner_id)?->full_name ?? '' }}</td></tr>
@endforeach
</tbody></table>
@php $rr = $pools->first(); @endphp
@foreach([1,2,3] as $place)
@if($person = $people->get($rr->{'winner_id_'.$place.'rd_robbin'}))
<p>{{ $place }} · {{ $person->full_name }} · {{ $participantLabels->affiliation($person) }}</p>
@endif
@endforeach
@else
<div class="grid" style="height:{{ $pageHeight }}mm;width:{{ $pageWidth }}mm;">
@foreach($layout['lines'] as $line)
<div class="line" style="left:{{ $line['x'] }}mm;top:{{ $line['y'] }}mm;width:{{ $line['w'] }}mm;height:{{ $line['h'] }}mm;"></div>
@endforeach
@foreach($layout['matches'] as $match)
@php $pool = $match['pool']; @endphp
<div class="match" style="left:{{ $match['x'] }}mm;top:{{ $match['y'] }}mm;width:{{ $match['width'] - 0.4 }}mm;min-height:{{ $match['height'] }}mm;">
<div class="match-head">{{ $match['stage'] }} · {{ $pool->tatami_and_fight_number ?: $pool->position_in_round }}</div>
@foreach(['student','opponent'] as $side)
@php $person=$pool->{$side}; $won=$person && (int)$person->id === (int)$pool->winner_id; @endphp
<div class="person {{ $won ? 'winner' : '' }} {{ $pool->{'absent_'.$side} ? 'absent' : '' }}">
<strong>{{ $person?->full_name ?: __('exports.bye') }}{{ $won ? ' *' : '' }}{{ $pool->{'absent_'.$side} ? ' ('.__('exports.absent').')' : '' }}</strong>
<small>{{ $participantLabels->affiliation($person) }}{{ $person?->coach_short ? ' · '.$person->coach_short : '' }}@if($won && ($pool->{$side.'_wazari_count'} || $pool->{$side.'_ippon'})) · W: {{ (int)$pool->{$side.'_wazari_count'} }} / I: {{ (int)$pool->{$side.'_ippon'} }}@endif</small>
</div>
@endforeach
</div>
@endforeach
</div>
@endif
@if($protocol) @include('pdf.partials.signatures') @endif
</div>
@empty
<p>{{ __('exports.empty') }}</p>
@endforelse
</body></html>
