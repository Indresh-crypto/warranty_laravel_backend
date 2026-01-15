<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Warranty Activated</title>
</head>

<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0;">
<tr>
<td align="center">

<!-- Card -->
<table width="700" cellpadding="0" cellspacing="0"
       style="background:#ffffff; border-radius:10px;
              box-shadow:0 6px 20px rgba(0,0,0,0.08); overflow:hidden;">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#0d47a1,#1e88e5);
           padding:26px; text-align:center; color:#ffffff;">
<h1 style="margin:0; font-size:24px;">Warranty Activated ✅</h1>
<p style="margin:8px 0 0; font-size:14px; color:#e3f2fd;">
Your product is now protected
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:26px; color:#1f2937; font-size:14px; line-height:1.6;">

<!-- Greeting -->
<p>
Hello <strong>{{ $device->customer->name }}</strong>,
</p>
<p>
Your warranty has been successfully activated. Below are the details of your
registered device and coverage.
</p>

<!-- Customer Details -->
<h3 style="margin:20px 0 10px; color:#0d47a1;">Customer Details</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#f9fafb; width:30%;"><strong>Name</strong></td>
<td style="padding:10px;">{{ $device->customer->name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Email</strong></td>
<td style="padding:10px;">{{ $device->customer->email }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Mobile</strong></td>
<td style="padding:10px;">{{ $device->customer->mobile }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Customer Code</strong></td>
<td style="padding:10px;"><strong style="color:#0d47a1;">{{ $device->customer->c_code }}</strong></td>
</tr>
</table>

<!-- Device Details -->
<h3 style="margin:26px 0 10px; color:#0d47a1;">Device Details</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#f9fafb; width:30%;"><strong>Device Name</strong></td>
<td style="padding:10px;">{{ $device->name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Brand</strong></td>
<td style="padding:10px;">{{ $device->brand_name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Model</strong></td>
<td style="padding:10px;">{{ $device->model }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>IMEI / Serial</strong></td>
<td style="padding:10px;">{{ $device->imei1 }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Warranty ID</strong></td>
<td style="padding:10px;"><strong>{{ $device->w_code }}</strong></td>
</tr>
</table>

<!-- Warranty Plan -->
<h3 style="margin:26px 0 10px; color:#0d47a1;">Warranty Plan</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#f9fafb; width:30%;"><strong>Plan Name</strong></td>
<td style="padding:10px;">{{ $device->product->name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Validity</strong></td>
<td style="padding:10px;">{{ $device->product->validity }} Months</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Expiry Date</strong></td>
<td style="padding:10px;">{{ \Carbon\Carbon::parse($device->expiry_date)->format('d M Y') }}</td>
</tr>
<tr>
<td style="padding:10px; background:#f9fafb;"><strong>Available Claims</strong></td>
<td style="padding:10px;">{{ $device->available_claim }}</td>
</tr>
</table>

<!-- Coverage -->
<h3 style="margin:26px 0 10px; color:#0d47a1;">Coverage Includes</h3>
<ul style="padding-left:18px; margin:10px 0; color:#374151;">
@foreach($device->product->coverages as $c)
<li><strong>{{ $c->title }}</strong></li>
@endforeach
</ul>

<!-- Info Note -->
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f0f9ff; border-left:4px solid #0d47a1;
              border-radius:6px; margin-top:18px;">
<tr>
<td style="padding:12px; font-size:13px; color:#1e3a8a;">
Please keep this email for future reference. Your warranty details are securely stored with us.
</td>
</tr>
</table>

<p style="margin-top:20px;">
Regards,<br>
<strong>{{ config('app.name') }} Team</strong>
</p>

</td>
</tr>

<!-- Footer -->
<tr>
<td style="background:#f3f4f6; padding:14px; text-align:center;
           font-size:12px; color:#6b7280;">
© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>