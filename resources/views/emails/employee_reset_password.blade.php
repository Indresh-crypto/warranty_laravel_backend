<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Password Reset</title>
</head>
<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0;">
<tr>
<td align="center">

<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.08);">

<tr>
<td style="background:#ef4444; padding:18px; text-align:center;">
<h2 style="margin:0; color:#ffffff;">Password Reset Successful</h2>
</td>
</tr>

<tr>
<td style="padding:24px; font-size:14px; color:#111827; line-height:1.6;">

<p>Hello <strong>{{ $employee->first_name }}</strong>,</p>

<p>Your password has been reset successfully. Please use the new credentials below:</p>

<table width="100%" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; margin:16px 0;">
<tr>
<td style="padding:16px;">
<p><strong>Employee ID:</strong> {{ $employee->employee_id }}</p>
<p><strong>New Password:</strong>
<span style="background:#111827; color:#ffffff; padding:6px 10px; border-radius:4px; font-family:monospace;">
{{ $password }}
</span>
</p>
</td>
</tr>
</table>

<p style="background:#fff7ed; border-left:4px solid #f97316; padding:10px;">
🔐 <strong>Important:</strong> Please change this password immediately after login.
</p>

<p>If you did not request this reset, please contact your administrator.</p>

</td>
</tr>

<tr>
<td style="background:#f9fafb; padding:12px; text-align:center; font-size:12px; color:#6b7280;">
© {{ date('Y') }} {{ config('app.name') }}
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>