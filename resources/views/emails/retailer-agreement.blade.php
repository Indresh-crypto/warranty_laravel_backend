<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Warranty Mitra ARP Agreement</title>
<style>
  body{margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#333}
  .container{max-width:600px;margin:auto;background:#ffffff}
  .header{background:#ffffff;padding:25px;text-align:center;border-bottom:3px solid #0b5ed7}
  .logo{max-width:180px}
  .content{padding:30px}
  h1{font-size:22px;margin-top:0;color:#0b5ed7}
  p{line-height:1.6;font-size:15px}
  .info-box{background:#f1f5ff;border-left:4px solid #0b5ed7;padding:15px;margin:20px 0}
  .btn{display:inline-block;background:#0b5ed7;color:#fff;text-decoration:none;padding:12px 20px;border-radius:4px;margin-top:10px}
  .footer{background:#0b5ed7;color:#ffffff;padding:20px;text-align:center;font-size:13px}
  .company{font-weight:bold;font-size:14px}
  @media(max-width:600px){
    .content{padding:20px}
    h1{font-size:20px}
  }
</style>
</head>
<body>

<div class="container">
    

<div class="header">
<img src="https://zoho.goelectronix.in/storage/logo.png" alt="Warranty Mitra logo" class="logo" />
</div>

<div class="content">

<h1>Retailer Agreement Confirmation</h1>

<p>Hello <strong>{{ $Retailer_Name }}</strong>,</p>

<p>Welcome to <strong>Warranty Mitra</strong></p>

<p>We are pleased to confirm that your retailer account has been successfully onboarded into the Warranty Mitra partner network.</p>

<div class="info-box">
<strong>Retailer Details</strong><br/><br/>
Retailer Code: {{ $Retailer_Code }}<br/>
Retailer Name: {{ $Retailer_Name }}<br/>
Retailer Phone: {{ $Retailer_Phone }}<br/>
Onboarded Date: {{ $Onboard_Date }}
</div>

<p>You can login to the Warranty Mitra Partner Portal using the link below:</p>

<a class="btn" href="{{ config('app.url_front') }}">Login to Partner Portal</a>
<p style="margin-top:25px">If you need any assistance, our support team is always available to help you.</p>

<p>
Support Phone: +918828272570<br/>
Support Email: info@warrantymitra.com
</p>

<p>We look forward to a successful partnership.</p>

<p>
Best Regards,<br/>
<strong>Warranty Mitra Team</strong>
</p>

</div>

<div class="footer">
<div class="company">GoElectronix Technologies Private Limited</div>
Corporate Office: Unit No. 403, 4th Floor, Ellora Olearise,<br/>
Plot No. A-786, TTC Industrial Area, Navi Mumbai, Maharashtra, India<br/><br/>
www.goelectronix.com
</div>

</div>

</body>
</html>