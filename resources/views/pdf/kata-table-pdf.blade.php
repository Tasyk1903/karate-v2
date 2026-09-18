<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('exports.kata_tables') }}</title>
    <style>
        @page { margin: 9mm; size: A4 portrait; }

        * { box-sizing: border-box; }

        body {
            color: #111827;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
            margin: 0;
        }

        .header {
            margin-bottom: 7mm;
            text-align: center;
        }

        .logo {
            display: block;
            height: auto;
            margin: 0 auto 3mm;
            max-height: 18mm;
            max-width: 52mm;
            object-fit: contain;
        }

        h1 {
            font-size: 15px;
            font-weight: 800;
            margin: 0 0 1.5mm;
            text-transform: uppercase;
        }

        .subtitle {
            color: #667085;
            font-size: 9px;
        }

        .section {
            margin-top: 6mm;
        }

        .section:first-of-type {
            margin-top: 0;
        }

        h2 {
            page-break-after: avoid;
            color: #ad2b2d;
            font-size: 12px;
            font-weight: 900;
            margin: 0 0 2.5mm;
            text-transform: uppercase;
        }

        table {
            border-collapse: collapse;
            page-break-inside: auto;
            table-layout: fixed;
            width: 100%;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
        }

        th {
            background: #f6f7fa;
            color: #667085;
            font-size: 7.2px;
            font-weight: 800;
            padding: 1.6mm 1.3mm;
            text-align: center;
            text-transform: uppercase;
        }

        td {
            border-bottom: 1px solid #e5e7eb;
            padding: 1.7mm 1.3mm;
            vertical-align: top;
        }

        th,
        td {
            border-left: 1px solid #e5e7eb;
        }

        th:first-child,
        td:first-child {
            border-left: 0;
        }

        .table-wrap { page-break-inside: auto; }
        .results { page-break-inside: avoid; }
        .separate-stage { page-break-before: always; }
        .results .participant { width: 78%; }

        .number,
        .score,
        .place {
            text-align: center;
            white-space: nowrap;
            width: 7%;
        }

        .participant {
            width: 29%;
        }

        .participant strong {
            display: block;
            font-size: 10px;
            font-weight: 800;
        }

        .participant small {
            color: #667085;
            display: block;
            font-size: 8px;
            font-weight: 700;
            margin-top: .4mm;
        }

        .empty {
            color: #667085;
            padding: 5mm;
            text-align: center;
        }
    </style>
</head>
<body>
@foreach($sheets as $sheet)
<div style="{{ $loop->first ? '' : 'page-break-before:always;' }}">
@include('pdf.partials.kata-sheet', $sheet)
</div>
@endforeach
</body></html>
