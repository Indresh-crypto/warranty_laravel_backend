@component('mail::message')

<img src="https://zoho.goelectronix.in/storage/logo.png"
     width="100" height="100"
     style="display:block;margin:auto;margin-bottom:20px;" />

# Email Verification

Hello **{{ $company->contact_person }}**, 👋

To complete your registration for **{{ $company->business_name }}**, please use the verification code below:

<div style="background:#eef5ff;border:2px dashed #b3cde0;border-radius:10px;
padding:20px;text-align:center;margin:25px 0;font-size:32px;font-weight:900;
letter-spacing:10px;color:#0056b3;">
    {{ $otp }}
</div>

<p style="font-size:15px;text-align:center;">
This code is valid for <strong>10 minutes</strong>.
</p>

---

### Registered Details
**Email:** {{ $company->contact_email }}  
**Mobile:** {{ $company->contact_phone }}

---

⚠️ **Important:** We will never ask for this OTP.  
Do not share it with anyone.

Thanks & Regards,  
**The Goelectronix Team**

---

Corporate Office: GoElectronix Technologies Pvt. Ltd. 
Unit No. 403, 4th Floor, Ellora Olearise Plot No. A-786, 
TTC Industrial Area MIDC, Kopar Khairane, Navi Mumbai, Maharashtra – 400709, India

Email: hello@goelectronix.com | Web: www.goelectronix.com  

@endcomponent