@component('mail::message')

<img src="https://zoho.goelectronix.in/storage/logo.png"
     width="100"
     height="100"
     style="display:block;margin:auto;margin-bottom:20px;" />

# Welcome to GoElectronix 🎉

Hello **{{ $lead->name }}**, 👋  

Thank you for registering with  
**GoElectronix Technologies Pvt. Ltd.**

Your account has been successfully created.  
Please use the credentials below to log in.

---

### 🔐 Login Credentials

**Email Address**
<div style="background:#eef5ff;border:2px dashed #b3cde0;border-radius:10px;
padding:15px;text-align:center;margin:15px 0;font-size:16px;font-weight:700;
color:#0056b3;">
    {{ $lead->email }}
</div>

**Temporary Password**
<div style="background:#eef5ff;border:2px dashed #b3cde0;border-radius:10px;
padding:15px;text-align:center;margin:15px 0;font-size:16px;font-weight:700;
color:#0056b3;">
    {{ $password }}
</div>

> ⚠️ **For security reasons, we strongly recommend changing your password after your first login.**

---

### 📋 Registered Details

- **Name:** {{ $lead->name }}
- **Email:** {{ $lead->email }}
- **Mobile:** {{ $lead->phone }}

---

If you face any issues while logging in, feel free to contact our support team.

Thanks & regards,  
**GoElectronix Team**

---

**Corporate Office**  
GoElectronix Technologies Pvt. Ltd.  
Unit No. 403, 4th Floor, Ellora Olearise, Plot No. A-786,  
TTC Industrial Area, MIDC, Kopar Khairane,  
Navi Mumbai, Maharashtra – 400709, India  

📧 hello@goelectronix.com  
🌐 www.goelectronix.com  

@endcomponent