@component('mail::message')

<img src="https://zoho.goelectronix.in/storage/logo.png"
     width="100"
     height="100"
     style="display:block;margin:auto;margin-bottom:20px;" />

# 🎉 Congratulations, {{ $lead->name }}!

We’re excited to inform you that your onboarding with  
**GoElectronix Technologies Pvt. Ltd.** has been **successfully completed**.

You are officially part of the GoElectronix partner ecosystem 🚀

---

### 📋 Registered Details

- **Name:** {{ $lead->name }}
- **Email:** {{ $lead->email }}
- **Mobile:** {{ $lead->phone ?? '—' }}

---

### 🔑 Login Access

You can now log in to your dashboard using your registered email:

@component('mail::button', ['url' => 'https://warrantynew.goelectronix.co.in'])
Login to Dashboard
@endcomponent

> 🔐 For security, we recommend keeping your login credentials confidential and updating your password periodically.

---

### 🤝 What’s Next?

- Access your dashboard
- Start managing warranties & devices
- Track claims and reports
- Connect with your assigned GoElectronix support team

If you need any help getting started, our support team is always here for you.

---

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