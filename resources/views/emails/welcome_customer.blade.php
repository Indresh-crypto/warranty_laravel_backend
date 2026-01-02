<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background-color: #f4f6f8;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: auto;
            background: #ffffff;
            border-radius: 6px;
            overflow: hidden;
        }
        .header {
            background: #0d47a1;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .content {
            padding: 20px;
            color: #333;
        }
        .footer {
            background: #f1f1f1;
            padding: 12px;
            text-align: center;
            font-size: 12px;
            color: #555;
        }
        .highlight {
            color: #0d47a1;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h2>Your're Welcome Goelectronix Warranty</h2>
    </div>

    <div class="content">
        <p>Hello <strong>{{ $customer->name }}</strong>,</p>

        <p>
            Thank you for registering with
            <span class="highlight">{{ config('app.name') }}</span>.
        </p>

        <p><strong>Your Customer Code:</strong> {{ $customer->c_code }}</p>

        <p>
            You can now register devices, activate warranties,
            and track your warranty details easily.
        </p>

        <p>
            If you have any questions, feel free to contact our support team.
        </p>

        <p>
            Regards,<br>
            <strong>{{ config('app.name') }} Team</strong>
        </p>
    </div>

    <div class="footer">
        © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
    </div>

</div>
</body>
</html>