<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Lead • Approved</title>
</head>

<body style="margin:0;padding:0;background:#f6f8fb;
font-family:Arial,Helvetica,sans-serif;color:#111827;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;padding:24px 0;">
<tr>
<td align="center" style="padding:0 12px;">

<table width="640" cellpadding="0" cellspacing="0"
style="max-width:640px;width:100%;background:#ffffff;
border-radius:16px;overflow:hidden;
box-shadow:0 10px 30px rgba(17,24,39,0.08);">

<!-- HEADER -->
<tr>
<td style="background:linear-gradient(90deg,#1e3a8a,#2563eb);
padding:22px 22px;">

<table width="100%">
<tr>
<td style="color:#ffffff;">
  <div style="font-size:13px;opacity:.9;">Warranty Team</div>
  <div style="font-size:22px;font-weight:900;margin-top:4px;">
    📝 Lead Approved
  </div>

  <div style="font-size:12px;opacity:.95;margin-top:10px;line-height:1.6;">
    <strong>Lead Code:</strong> {{ $lead->lead_code }}<br/>
    <strong>{{ $company->business_name }}</strong>
  </div>
</td>

<td align="right" style="vertical-align:top;">
  <div style="background:rgba(255,255,255,0.15);
  border:1px solid rgba(255,255,255,0.25);
  border-radius:999px;padding:8px 12px;text-align:center;">
    <div style="font-size:11px;color:#ffffff;">Submitted On</div>
    <div style="font-size:14px;font-weight:900;color:#ffffff;">
      {{ $lead->created_at->format('d M Y') }}
    </div>
  </div>
</td>
</tr>
</table>

</td>
</tr>

<!-- INTRO -->
<tr>
<td style="padding:20px 22px;">
  <div style="font-size:14px;line-height:1.8;color:#374151;">
    A new warranty onboarding lead has been submitted and
    <strong style="color:#39781a;">Approved</strong>.
  </div>
</td>
</tr>

<!-- REVIEW DETAILS CARD -->
<tr>
<td style="padding:0 22px 16px 22px;">
<table width="100%" style="border:1px solid #e5e7eb;border-radius:14px;">
<tr>
<td style="padding:16px;">

<div style="font-size:13px;font-weight:700;color:#2563eb;margin-bottom:14px;">
🔎 Review Details
</div>

<!-- ROW 1 -->
<table width="100%" style="padding-bottom:10px;border-bottom:1px dashed #e5e7eb;">
<tr>
<td style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">Lead Code</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->lead_code }}</div>
</td>
<td align="right" style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">Organization Name</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->name }}</div>
</td>
</tr>
</table>

<!-- ROW 2 -->
<table width="100%" style="padding:10px 0;border-bottom:1px dashed #e5e7eb;">
<tr>
<td style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">Contact Number</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->phone }}</div>
</td>
<td align="right" style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">Created By</div>
  <div style="font-size:14px;font-weight:900;">
    {{ $lead->created_by_name ?? 'System' }}
  </div>
</td>
</tr>
</table>

<!-- ROW 3 -->
<table width="100%" style="padding:10px 0;border-bottom:1px dashed #e5e7eb;">
<tr>
<td style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">Date & Time</div>
  <div style="font-size:13px;font-weight:900;">
    {{ $lead->created_at->format('d M Y h:i A') }}
  </div>
</td>
<td align="right" style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">PIN Code</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->pincode }}</div>
</td>
</tr>
</table>

<!-- ROW 4 -->
<table width="100%" style="padding:10px 0;border-bottom:1px dashed #e5e7eb;">
<tr>
<td style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">District</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->district }}</div>
</td>
<td align="right" style="padding:6px 0;">
  <div style="font-size:12px;color:#6b7280;">State</div>
  <div style="font-size:14px;font-weight:900;">{{ $lead->state }}</div>
</td>
</tr>
</table>

<!-- APPROVED SECTION (ENHANCED) -->
<table width="100%" style="padding-top:12px;">
<tr>
<td colspan="2" style="border:1px solid #22c55e;background:#f0fdf4;border-radius:10px;padding:12px;">
  
  <table width="100%">
  <tr>
    <td>
      <div style="font-size:12px;color:#166534;">Approved By</div>
      <div style="font-size:14px;font-weight:900;">
        {{ $lead->updated_by_name }}
      </div>
    </td>

    <td align="right">
      <div style="font-size:12px;color:#166534;">Approved Date</div>
      <div style="font-size:14px;font-weight:900;">
        {{ \Carbon\Carbon::parse($lead->updated_at)->format('d-m-Y') }}
      </div>
    </td>
  </tr>
  </table>

</td>
</tr>
</table>

<!-- STATUS -->
<div style="margin-top:14px;background:#dcfce7;border:1px solid #22c55e;
padding:10px;border-radius:10px;text-align:center;">
  <div style="font-size:13px;color:#166534;font-weight:700;">
    ✅ Status: Approved
  </div>
</div>

</td>
</tr>
</table>
</td>
</tr>

<!-- SYSTEM NOTE -->
<tr>
<td style="padding:16px 22px;border-top:1px solid #e5e7eb;">
  <div style="font-size:12px;color:#374151;line-height:1.8;">
    <strong style="color:#1e3a8a;">System Notice:</strong>
    This is an automated notification generated whenever a lead
    is approved.
</td>
</tr>

<!-- FOOTER -->
<tr>
<td style="background:#f9fafb;padding:16px 22px;">
  <div style="font-size:11px;color:#6b7280;line-height:1.7;">
    Regards,<br />
    <strong style="color:#111827;">Warranty Team</strong><br />
    GoElectronix Technologies Private Limited<br />
    © Automated System Notification • Please do not reply
  </div>
</td>
</tr>

</table>
</td>
</tr>
</table>

</body>
</html>