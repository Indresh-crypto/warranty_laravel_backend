<!DOCTYPE html>
<html>
<body style="font-family: Arial; background:#f5f5f5; padding:20px;">

<div style="max-width:800px; margin:auto; background:#fff; padding:20px;">
    <h2>Inactive Retailer Alert</h2>

    <p>The following retailer has not registered any devices in the last 2 days.</p>

    <table width="100%" border="1" cellspacing="0" cellpadding="8">
        <tr>
            <th>Retailer Name</th>
            <th>Code</th>
            <th>District</th>
            <th>Phone</th>
        </tr>
        <tr>
            <td>{{ $retailer->business_name }}</td>
            <td>{{ $retailer->company_code }}</td>
            <td>{{ $retailer->district }}</td>
            <td>{{ $retailer->contact_phone }}</td>
        </tr>
    </table>

    <br>
    Please contact the retailer and ensure device registrations are updated.

</div>

</body>
</html>