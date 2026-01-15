<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Claim Notification</title>
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
<td style="background:linear-gradient(135deg,#b71c1c,#e53935);
           padding:26px; text-align:center; color:#ffffff;">
<h1 style="margin:0; font-size:24px;">
{{ $isCompany ? 'New Claim Raised' : 'Claim Registered Successfully' }}
</h1>
<p style="margin:8px 0 0; font-size:14px; color:#ffebee;">
Claim Reference: <strong>{{ $claim->claim_code }}</strong>
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:26px; color:#1f2937; font-size:14px; line-height:1.6;">

<p>
Hello,
</p>

@if($isCompany)
<p>
A new warranty claim has been raised. Please review the details below and take the necessary action.
</p>
@else
<p>
Your warranty claim has been successfully registered. Our support team will review it and keep you updated.
</p>
@endif

<!-- Claim Details -->
<h3 style="margin:22px 0 10px; color:#b71c1c;">Claim Details</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#fafafa; width:30%;"><strong>Claim Code</strong></td>
<td style="padding:10px;">{{ $claim->claim_code }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Status</strong></td>
<td style="padding:10px;">
<strong style="color:#b71c1c;">{{ ucfirst($claim->status) }}</strong>
</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Claim Type</strong></td>
<td style="padding:10px;">{{ ucfirst($claim->claim_type) }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Issue Description</strong></td>
<td style="padding:10px;">{{ $claim->issue_description }}</td>
</tr>
</table>

<!-- Customer -->
<h3 style="margin:26px 0 10px; color:#b71c1c;">Customer Details</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#fafafa; width:30%;"><strong>Name</strong></td>
<td style="padding:10px;">{{ $claim->customer?->name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Email</strong></td>
<td style="padding:10px;">{{ $claim->customer?->email }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Mobile</strong></td>
<td style="padding:10px;">{{ $claim->customer?->mobile }}</td>
</tr>
</table>

<!-- Device -->
<h3 style="margin:26px 0 10px; color:#b71c1c;">Device Details</h3>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse; font-size:13.5px;">
<tr>
<td style="padding:10px; background:#fafafa; width:30%;"><strong>Product</strong></td>
<td style="padding:10px;">{{ $claim->device?->product_name }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Model</strong></td>
<td style="padding:10px;">{{ $claim->device?->model }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>IMEI / Serial</strong></td>
<td style="padding:10px;">{{ $claim->device?->imei1 }}</td>
</tr>
<tr>
<td style="padding:10px; background:#fafafa;"><strong>Warranty ID</strong></td>
<td style="padding:10px;"><strong>{{ $claim->device?->w_code }}</strong></td>
</tr>
</table>

<!-- Attachment Note -->
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#fff5f5; border-left:4px solid #b71c1c;
              border-radius:6px; margin-top:18px;">
<tr>
<td style="padding:12px; font-size:13px; color:#7f1d1d;">
📎 Claim images and supporting documents are attached with this email.
</td>
</tr>
</table>

<p style="margin-top:22px;">
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