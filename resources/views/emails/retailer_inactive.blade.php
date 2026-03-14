<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Warranty Portal Activity Reminder</title>
  <style>
    body {
      margin: 0;
      background: #f5f7fb;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif
    }

    .wrapper {
      max-width: 640px;
      margin: 40px auto;
      padding: 0 15px
    }

    .card {
      background: #ffffff;
      border-radius: 10px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
      overflow: hidden
    }

    .header {
      padding: 28px 30px;
      border-bottom: 1px solid #eef1f5;
      text-align: center
    }

    .logo img {
      max-height: 55px
    }

    .brand {
      font-size: 20px;
      font-weight: 700;
      color: #111827;
      margin-top: 8px
    }

    .content {
      padding: 30px;
      font-size: 15px;
      color: #374151;
      line-height: 1.6
    }

    .content h2 {
      margin: 0 0 10px;
      font-size: 20px;
      color: #111827
    }

    .info {
      background: #f1f5f9;
      border-radius: 8px;
      padding: 16px;
      margin: 18px 0
    }

    .highlight {
      background: #eef2ff;
      border-left: 4px solid #111827;
      padding: 14px 16px;
      border-radius: 6px;
      margin: 18px 0
    }

    .btn {
      display: inline-block;
      background: #111827;
      color: #fff;
      text-decoration: none;
      padding: 11px 20px;
      border-radius: 6px;
      font-size: 14px;
      margin-top: 12px
    }

    .footer {
      border-top: 1px solid #eef1f5;
      padding: 22px 30px;
      font-size: 12.5px;
      color: #6b7280;
      background: #fafafa
    }

    .footer strong {
      color: #111827
    }
  </style>
</head>

<body>

  <div class="wrapper">
    <div class="card">

      <div class="header">

        <!-- Company Logo Placement (Center Top) -->
        <div class="logo">
          <img src="https://goelectronix.com/logo2.png" alt="GoElectronix Logo">
        </div>

        <div class="brand">GoElectronix Warranty Portal</div>

      </div>

      <div class="content">

        <h2>Portal Activity Reminder</h2>

        <p>Hello <strong>{{ $retailer_name }}</strong>,</p>

        <p>Our system shows that your warranty portal has not been used for the last 
        <strong>{{ $inactive_days }} days</strong>
            </p>

        <div class="info">
          Please start registering warranties through the portal so your customers can receive proper warranty
          protection and service support.
        </div>

        <div class="highlight">
          GoElectronix provides <strong>screen damage / extended warranty services</strong> as a value‑added service for
          retailers and their customers, helping enhance customer trust and after‑sales protection.
        </div>

        <p>Using the portal regularly helps you:</p>

        <ul>
          <li>Register extended warranties instantly</li>
          <li>Offer value‑added warranty services to customers</li>
          <li>Track warranty status easily</li>
          <li>Maintain service and warranty history</li>
        </ul>

        <p>If you need any help with login or warranty registration, please contact our support team.</p>

        <p>
          <strong>Support:</strong> +91 8828272570
        </p>

        <p style="margin-top:20px">
          Regards,<br>
          <strong>Team GoElectronix Warranty Support</strong>
        </p>

      </div>

      <div class="footer">

        <p><strong>GoElectronix Technologies Pvt. Ltd.</strong></p>

        <p>
          Corporate Office: Unit No. 403, 4th Floor, Ellora Olearise,<br>
          Plot No. A‑786, TTC Industrial Area MIDC,<br>
          Kopar Khairane, Navi Mumbai, Maharashtra – 400709, India
        </p>

        <p>
          Email: support@goelectronix.com | Website: www.goelectronix.com
        </p>

        <p style="margin-top:10px">
          CIN: U74110MH2020PTC341758
        </p>

        <p style="margin-top:12px;font-size:11px;color:#9ca3af">
          This is an automated service communication regarding GoElectronix warranty services and portal activity.
        </p>

      </div>

    </div>
  </div>

</body>

</html>