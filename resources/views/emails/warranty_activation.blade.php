<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial; background:#f4f6f8; padding:20px; }
.box { max-width:700px; margin:auto; background:#fff; border-radius:6px; }
.header { background:#0d47a1; color:#fff; padding:20px; text-align:center; }
.section { padding:20px; }
table { width:100%; border-collapse:collapse; }
td, th { border:1px solid #ddd; padding:10px; }
.footer { background:#eee; padding:12px; text-align:center; font-size:12px; }
</style>
</head>
<body>

<div class="box">

<div class="header">
<h2>Warranty Activated</h2>
</div>

<div class="section">
<h3>Customer Details</h3>
<table>
<tr><th>Name</th><td>{{ $device->customer->name }}</td></tr>
<tr><th>Email</th><td>{{ $device->customer->email }}</td></tr>
<tr><th>Mobile</th><td>{{ $device->customer->mobile }}</td></tr>
<tr><th>Customer Code</th><td>{{ $device->customer->c_code }}</td></tr>
</table>
</div>

<div class="section">
<h3>Device Details</h3>
<table>
<tr><th>Device</th><td>{{ $device->name }}</td></tr>
<tr><th>Brand</th><td>{{ $device->brand_name }}</td></tr>
<tr><th>Model</th><td>{{ $device->model }}</td></tr>
<tr><th>IMEI</th><td>{{ $device->imei1 }}</td></tr>
<tr><th>Warranty ID</th><td>{{ $device->w_code }}</td></tr>
</table>
</div>

<div class="section">
<h3>Warranty Plan</h3>
<table>
<tr><th>Plan</th><td>{{ $device->product->name }}</td></tr>
<tr><th>Validity</th><td>{{ $device->product->validity }} Months</td></tr>
<tr><th>Expiry</th><td>{{ \Carbon\Carbon::parse($device->expiry_date)->format('d M Y') }}</td></tr>
<tr><th>Claims</th><td>{{ $device->available_claim }}</td></tr>
</table>
</div>

<div class="section">
<h3>Coverage</h3>
<ul>
@foreach($device->product->coverages as $c)
<li><strong>{{ $c->title }}</strong></li>
@endforeach
</ul>
</div>

<div class="footer">
© {{ date('Y') }} {{ config('app.name') }}
</div>

</div>

</body>
</html>