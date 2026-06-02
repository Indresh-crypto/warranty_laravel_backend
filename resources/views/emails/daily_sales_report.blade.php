<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Daily Sales Report</title>
</head>
<body style="font-family: Arial; background:#f5f5f5; padding:20px;">

<div style="max-width:900px; margin:auto; background:#ffffff; padding:20px; border-radius:8px;">

<div style="text-align:center;">
    <img src="https://zoho.goelectronix.in/storage/logo.png" width="140">
    <h2>Daily Sales Report</h2>
    <p>Date: {{ $date }}</p>
</div>

<h3>Summary</h3>
<p><strong>Total Devices Sold:</strong> {{ $totalQty }}</p>
<p><strong>Total Sales Amount:</strong> ₹ {{ number_format($totalAmount, 2) }}</p>

<h3>Retailer Wise Sales</h3>

<table width="100%" border="1" cellspacing="0" cellpadding="8" style="border-collapse:collapse;">
    <thead style="background:#593884;color:white;">
        <tr>
            <th>Retailer Name</th>
            <th>Retailer Code</th>
            <th>Quantity Sold</th>
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

<div style="text-align:center;font-size:12px;color:#888;">
GoElectronix Automated Sales Report
</div>

</div>
</body>
</html>