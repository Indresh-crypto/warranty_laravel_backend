<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Sales Report</title>
</head>
<body style="font-family: Arial; background:#f4f6f9; padding:20px;">

<div style="max-width:900px; margin:auto; background:#ffffff; padding:25px; border-radius:8px;">

<div style="text-align:center;">
    <img src="https://zoho.goelectronix.in/storage/logo.png" width="150">
    <h2>Sales Report</h2>
    <p>Period: {{ $date }}</p>
</div>

<hr>

<h3>Sales Summary</h3>

<table width="100%" cellpadding="10" style="background:#f9f9f9;">
<tr>
    <td><strong>Total Devices Sold</strong></td>
    <td>{{ $totalQty }}</td>
</tr>
<tr>
    <td><strong>Total Sales Amount</strong></td>
    <td>₹ {{ number_format($totalAmount,2) }}</td>
</tr>
</table>

<br>

<h3>Retailer Wise Sales</h3>

<table width="100%" border="1" cellspacing="0" cellpadding="8" style="border-collapse:collapse;">
<thead style="background:#593884;color:white;">
<tr>
    <th>Retailer Name</th>
    <th>Retailer Code</th>
    <th>Qty Sold</th>
    <th>Total Amount</th>
</tr>
</thead>
<tbody>
@foreach($reportData as $row)
<tr>
    <td>{{ $row->business_name }}</td>
    <td>{{ $row->company_code }}</td>
    <td>{{ $row->total_qty }}</td>
    <td>₹ {{ number_format($row->total_amount,2) }}</td>
</tr>
@endforeach
</tbody>
</table>

<br>

<div style="text-align:center; font-size:12px; color:#888;">
GoElectronix Automated Report
</div>

</div>
</body>
</html>