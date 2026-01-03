<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial; background:#f4f6f8; padding:20px; }
.box { max-width:700px; background:#fff; margin:auto; border-radius:6px; }
.header { background:#1565c0; color:#fff; padding:20px; text-align:center; }
.section { padding:20px; }
table { width:100%; border-collapse:collapse; }
td, th { border:1px solid #ddd; padding:10px; }
.footer { background:#eee; padding:12px; text-align:center; font-size:12px; }
</style>
</head>
<body>

<div class="box">
<div class="header">
<h2>Warranty Claim Update</h2>
</div>

<div class="section">
<table>
<tr><th>Claim Code</th><td>{{ $claim->claim_code }}</td></tr>
<tr><th>Status</th><td>{{ ucfirst(str_replace('_',' ',$status)) }}</td></tr>
<tr><th>Customer</th><td>{{ $claim->customer->name }}</td></tr>
<tr><th>Device</th><td>{{ $claim->device->product_name }} ({{ $claim->device->model }})</td></tr>
<tr><th>IMEI</th><td>{{ $claim->device->imei1 }}</td></tr>
</table>
</div>

@if($status === 'estimate_sent')
<div class="section">
<p><strong>Inspection Report:</strong></p>
<p>{{ $claim->inspection_report }}</p>
<p><strong>Estimated Amount:</strong> ₹{{ number_format($claim->estimate_amount, 2) }}</p>
</div>
@endif

<div class="footer">
© {{ date('Y') }} {{ config('app.name') }}
</div>
</div>

</body>
</html>