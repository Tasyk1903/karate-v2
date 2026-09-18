<table style="width:100%; margin-top:4mm; border-collapse:collapse; font-size:10px;">
@foreach(['chief_judge', 'chief_secretary'] as $field)
<tr><td style="width:30%;padding:1.5mm 0;">{{ __('exports.'.$field) }}</td><td style="width:25%;">________________</td><td>{{ $tournament->{$field} ?: '________________' }}</td></tr>
@endforeach
</table>
