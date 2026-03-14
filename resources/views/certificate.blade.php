<!DOCTYPE html>
<html>

<head>
<meta charset="UTF-8">
<title>Extended Warranty Certificate</title>

<style>

@page {
    margin: 20px;
}

body{
    font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
    margin:0;
    padding:0;
}

.certificate{
    width:100%;
    border:1px solid #444;
}

.header{
    text-align:center;
    padding-top:5px;
}

.header img{
    width:200px;
}

.title{
    text-align:center;
    color:#081faa;
    font-size:18px;
    font-weight:bold;
    margin:10px 0 15px 0;
}

.section{
    padding:8px 15px;
}

.table{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
    font-size:12px;
}

.table th{
    background:#e5e5e5;
    text-align:left;
    padding:6px;
}

.table td{
    padding:5px 6px;
    vertical-align:top;
    word-break:break-word;
}

.label{
    font-weight:bold;
    width:35%;
}

.value{
    width:65%;
}

.small{
    font-size:11px;
}

.coverage td{
    padding:4px;
    font-size:11px;
}

.footer{
    border-top:1px solid #ccc;
    margin-top:10px;
    padding:8px 15px;
    font-size:11px;
    text-align:center;
}

</style>

</head>

<body>

<div class="certificate">

<!-- HEADER -->
<div class="header">
<img src="{{ public_path('logo2.png') }}" width="200">
</div>

<div class="title">
Extended Warranty Certificate
</div>


<!-- CUSTOMER + WARRANTY -->
<div class="section">

<table class="table">

<tr>
<th width="30%">Customer Details</th>
<th width="70%">Warranty Details</th>
</tr>

<tr>

<td>

<strong>{{ $device->customer->name }}</strong><br>

{{ $device->customer->address ?? '' }}<br>

Email: {{ $device->customer->email }}<br>

Ph: {{ $device->customer->mobile }}

</td>

<td>

<table class="table small">

<tr>
<td class="label">Warranty Pack</td>
<td class="value">{{ $device->product->name }}</td>
</tr>

<tr>
<td class="label">Item / Device</td>
<td class="value">{{ $device->category_name }}</td>
</tr>

<tr>
<td class="label">Warranty Type</td>
<td class="value">Extended Warranty</td>
</tr>

<tr>
<td class="label">Warranty Duration</td>
<td class="value">{{ $device->product->validity }} Days</td>
</tr>

<tr>
<td class="label">Warranty ID</td>
<td class="value">{{ $device->w_code }}</td>
</tr>

<tr>
<td class="label">Warranty Start Date</td>
<td class="value">{{ \Carbon\Carbon::parse($device->created_at)->format('d M Y') }}</td>
</tr>

<tr>
<td class="label">Warranty End Date</td>
<td class="value">{{ \Carbon\Carbon::parse($device->expiry_date)->format('d M Y') }}</td>
</tr>

</table>

</td>

</tr>

</table>

</div>


<!-- DEVICE DETAILS -->
<div class="section">

<table class="table">

<tr>
<th colspan="2">Item / Device Details</th>
</tr>

<tr>
<td class="label">Item / Device</td>
<td class="value">{{ $device->category_name }}</td>
</tr>

<tr>
<td class="label">Brand</td>
<td class="value">{{ $device->brand_name }}</td>
</tr>

<tr>
<td class="label">Model</td>
<td class="value">{{ $device->model }}</td>
</tr>

<tr>
<td class="label">IMEI / Serial Number</td>
<td class="value">{{ $device->imei1 }} / {{ $device->serial }}</td>
</tr>
<tr>
<td class="label">Coverage Limit</td>
<td class="value">₹ {{ number_format($device->device_price,2) }}</td>
</tr>

<tr>
<td class="label">Item Purchased From</td>
<td class="value">
{{ $retailer->business_name }}
({{ $retailer->company_code }})</br>
{{ $retailer->address_line1 }},
Ph. {{ $retailer->contact_phone }},
</td>
</tr>

<tr>
<td class="label">Item Purchase Date</td>
<td class="value">{{ \Carbon\Carbon::parse($device->created_at)->format('d M Y') }}</td>
</tr>

</table>

</div>


<!-- COVERAGE -->
<div class="section">

<table class="table">

<tr>
<th>Coverage Includes</th>
</tr>

<tr>
<td>

<table width="100%" class="coverage">

@php
$coverages = $device->product->coverages;
$chunks = $coverages->chunk(3);
@endphp

@foreach($chunks as $chunk)

<tr>

@foreach($chunk as $c)

<td>• {{ $c->title }}</td>

@endforeach

@for($i=$chunk->count(); $i<3; $i++)

<td></td>

@endfor

</tr>

@endforeach

</table>

</td>
</tr>

</table>

</div>


<!-- FOOTER -->
<div class="footer">

<strong>GoElectronix Technologies Pvt. Ltd.</strong><br>

Corporate Office: Unit No. 403, 4th Floor, Ellora Olearise Plot No. A-786,
TTC Industrial Area MIDC, Kopar Khairane, Navi Mumbai, Maharashtra – 400709, India

<br>

Email: hello@goelectronix.com

</div>

</div>

</body>
</html>