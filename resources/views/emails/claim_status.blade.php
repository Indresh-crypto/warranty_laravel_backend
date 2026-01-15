<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Warranty Claim Update</title>
</head>

<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0;">
<tr>
<td align="center">

<!-- Card -->
<table width="700" cellpadding="0" cellspacing="0"
       style="background:#ffffff; border-radius:10px;
              box-shadow:0 6px 18px rgba(0,0,0,0.08); overflow:hidden;">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#1565c0,#1e88e5);
           padding:24px; text-align:center; color:#ffffff;">
<h1 style="margin:0; font-size:22px;">Warranty Claim Update</h1>
<p style="margin:6px 0 0; font-size:14px; color:#e3f2fd;">
Claim Code: <strong>{{ $claim->claim_code }}</strong>
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:26px; color:#1f2937; font-size:14px; line-height:1.6;">

<p>Hello <strong>{{ $claim->customer->name }}</strong>,</p>

<p>
We would like to inform you about the latest update on your warranty claim.
Please find the details below.
</p>

<!-- Summary Table -->
<h3 style="margin:22px 0 10px; color:#1565c0;">Claim Summary</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#fafafa; width:30%;"><strong>Status</strong></td>
<td style="padding:10px;">
<strong style="color:#1565c0;">
{{ ucfirst(str_replace('_',' ',$status)) }}
</strong>
</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Device</strong></td>
<td style="padding:10px;">
{{ $claim->device->product_name }} ({{ $claim->device->model }})
</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>IMEI / Serial</strong></td>
<td style="padding:10px;">{{ $claim->device->imei1 }}</td>
</tr>
</table>

<!-- Estimate Section -->
@if($status === 'estimate_sent')
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f1f8ff; border-left:4px solid #1565c0;
              border-radius:6px; margin-top:18px;">
<tr>
<td style="padding:14px; font-size:14px;">
<p style="margin:0 0 6px;"><strong>Inspection Report</strong></p>
<p style="margin:0 0 10px; color:#374151;">
{{ $claim->inspection_report }}
</p>
<p style="margin:0;">
<strong>Estimated Amount:</strong>
<span style="font-size:16px; color:#1565c0;">
₹{{ number_format($claim->estimate_amount, 2) }}
</span>
</p>
</td>
</tr>
</table>
@endif

<p style="margin-top:24px;">
If you have any questions or need further assistance, feel free to contact our support team.
</p>

<p>
Regards,<br>
<strong>{{ config('app.name') }} Support Team</strong>
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