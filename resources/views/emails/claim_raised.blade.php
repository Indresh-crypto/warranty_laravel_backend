<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body { font-family: Arial; background:#f4f6f8; padding:20px; }
.box { max-width:700px; margin:auto; background:#fff; border-radius:6px; }
.header { background:#b71c1c; color:#fff; padding:20px; text-align:center; }
.section { padding:20px; }
table { width:100%; border-collapse:collapse; }
td, th { border:1px solid #ddd; padding:10px; }
.footer { background:#eee; padding:12px; text-align:center; font-size:12px; }
</style>
</head>
<body>

<div class="box">

<div class="header">
<h2>{{ $isCompany ? 'New Claim Raised' : 'Claim Registered Successfully' }}</h2>
</div>

<div class="section">
<h3>Claim Details</h3>
<table>
<tr><th>Claim Code</th><td>{{ $claim->claim_code }}</td></tr>
<tr><th>Status</th><td>{{ ucfirst($claim->status) }}</td></tr>
<tr><th>Issue</th><td>{{ $claim->issue_description }}</td></tr>
<tr><th>Type</th><td>{{ ucfirst($claim->claim_type) }}</td></tr>
</table>
</div>

<div class="section">
<h3>Customer</h3>
<table>
<tr><th>Name</th><td>{{ $claim->customer?->name }}</td></tr>
<tr><th>Email</th><td>{{ $claim->customer?->email }}</td></tr>
<tr><th>Mobile</th><td>{{ $claim->customer?->mobile }}</td></tr>
</table>
</div>

<div class="section">
<h3>Device</h3>
<table>
<tr><th>Product</th><td>{{ $claim->device?->product_name }}</td></tr>
<tr><th>Model</th><td>{{ $claim->device?->model }}</td></tr>
<tr><th>IMEI</th><td>{{ $claim->device?->imei1 }}</td></tr>
<tr><th>Warranty ID</th><td>{{ $claim->device?->w_code }}</td></tr>
</table>
</div>

<div class="section">
<p>📎 Claim photos are attached with this email.</p>
</div>

<div class="footer">
© {{ date('Y') }} {{ config('app.name') }}
</div>

</div>

</body>
</html>