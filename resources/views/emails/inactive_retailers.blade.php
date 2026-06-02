<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Inactive Retailers Report</title>
</head>
<body style="font-family: Arial; background:#f4f6f9; padding:20px;">

<div style="max-width:900px; margin:auto; background:#ffffff; padding:25px; border-radius:8px;">

<div style="text-align:center;">
    <img src="https://zoho.goelectronix.in/storage/logo.png" width="150">
    <h2>Inactive Retailers Alert</h2>
</div>

<p>
The following retailers have <strong>not registered any devices in the last {{ $days }} days</strong>.
Please contact them and ensure device registrations are updated.
</p>

<table width="100%" border="1" cellspacing="0" cellpadding="8" style="border-collapse:collapse;">
<thead style="background:#593884;color:white;">
<tr>
    <th>Retailer Name</th>
    <th>Retailer Code</th>
    <th>District</th>
    <th>Phone</th>
    <th>Email</th>
</tr>
</thead>
<tbody>

@foreach($retailers as $retailer)
<tr>
    <td>{{ $retailer->business_name }}</td>
    <td>{{ $retailer->company_code }}</td>
    <td>{{ $retailer->district }}</td>
    <td>{{ $retailer->contact_phone }}</td>
    <td>{{ $retailer->contact_email }}</td>
</tr>
@endforeach

</tbody>
</table>

<br>

<div style="text-align:center; font-size:12px; color:#888;">
GoElectronix Automated Monitoring System<br>
This is an automated email notification.
</div>

</div>
</body>
</html>