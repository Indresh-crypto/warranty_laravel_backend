<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Warranty Cancelled</title>
</head>

<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0;">
<tr>
<td align="center">

<table width="700" cellpadding="0" cellspacing="0"
       style="background:#ffffff; border-radius:10px;
              box-shadow:0 6px 20px rgba(0,0,0,0.08); overflow:hidden;">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#b71c1c,#e53935);
           padding:26px; text-align:center; color:#ffffff;">
<h1 style="margin:0; font-size:24px;">Warranty Cancelled</h1>
<p style="margin:8px 0 0; font-size:14px; color:#ffebee;">
Credit note has been issued
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:26px; color:#1f2937; font-size:14px; line-height:1.6;">

<p>
Hello <strong>{{ $device->customer->name }}</strong>,
</p>

<p>
This is to inform you that the warranty for your device has been <strong>cancelled</strong>.
A credit note has been successfully issued against your invoice.
</p>

@if($reason)
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#fff7ed; border-left:4px solid #f97316;
              border-radius:6px; margin:16px 0;">
<tr>
<td style="padding:12px; font-size:13px; color:#9a3412;">
<strong>Reason:</strong> {{ $reason }}
</td>
</tr>
</table>
@endif

<!-- Device Details -->
<h3 style="margin:22px 0 10px; color:#b91c1c;">Device Details</h3>
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
<td style="padding:10px; background:#f9fafb;"><strong>Warranty ID</strong></td>
<td style="padding:10px;"><strong>{{ $device->w_code }}</strong></td>
</tr>
</table>


<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f0f9ff; border-left:4px solid #0284c7;
              border-radius:6px; margin-top:18px;">
<tr>
<td style="padding:12px; font-size:13px; color:#075985;">
If you have any questions regarding this cancellation or credit note,
please contact our support team.
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