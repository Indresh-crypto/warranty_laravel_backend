<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Welcome to Goelectronix Warranty</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

<!-- Wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0; background:#f4f6f8;">
<tr>
<td align="center">

<!-- Card -->
<table width="600" cellpadding="0" cellspacing="0"
       style="background:#ffffff; border-radius:10px; overflow:hidden;
              box-shadow:0 6px 18px rgba(0,0,0,0.08);">

<!-- Header -->
<tr>
<td style="background:linear-gradient(135deg,#0d47a1,#1e88e5);
           padding:28px; text-align:center;">
<h1 style="margin:0; font-size:24px; color:#ffffff;">
Welcome to Goelectronix Warranty 🎉
</h1>
<p style="margin:8px 0 0; font-size:14px; color:#e3f2fd;">
Your trusted warranty management partner
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:30px; color:#1f2933; font-size:15px; line-height:1.7;">

<p style="margin-top:0;">
Hello <strong>{{ $customer->name }}</strong>,
</p>

<p>
Thank you for registering with
<strong style="color:#0d47a1;">{{ config('app.name') }}</strong>.
We're excited to have you on board.
</p>

<!-- Highlight Box -->
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f1f8ff; border:1px solid #dbeafe;
              border-radius:8px; margin:20px 0;">
<tr>
<td style="padding:16px; text-align:center;">
<p style="margin:0; font-size:13px; color:#64748b;">Your Customer Code</p>
<p style="margin:6px 0 0; font-size:22px; font-weight:700;
          letter-spacing:1px; color:#0d47a1;">
{{ $customer->c_code }}
</p>
</td>
</tr>
</table>

<p>
You can now:
</p>

<ul style="padding-left:18px; margin:10px 0 20px; color:#374151;">
<li>Register your devices</li>
<li>Activate and manage warranties</li>
<li>Track warranty status and claims</li>
</ul>

<!-- Support Note -->
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#fff7ed; border-left:4px solid #fb923c;
              border-radius:6px; margin:16px 0;">
<tr>
<td style="padding:12px 14px; font-size:14px; color:#7c2d12;">
If you have any questions, our support team is always happy to help.
</td>
</tr>
</table>

<p style="margin-bottom:0;">
Warm regards,<br>
<strong>{{ config('app.name') }} Team</strong>
</p>

</td>
</tr>

<!-- Footer -->
<tr>
<td style="background:#f9fafb; padding:16px; text-align:center;
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