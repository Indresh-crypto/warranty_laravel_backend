@component('mail::message')

<div style="text-align:center;margin-bottom:20px;">
    <img src="https://zoho.goelectronix.in/storage/logo.png" width="140">
</div>

# Hello {{ $company->contact_person }},

We hope you are doing well.

Our system shows that **no mobile devices were registered for warranty today** from your retailer account.

To ensure uninterrupted services, and retailer performance tracking, please make sure to **register all devices sold today** in the GoElectronix Retailer Panel.

---

## 📱 Why Device Registration Is Important
Device registration helps you:
- Activate customer warranty
- Receive retailer incentives
- Maintain active retailer status
- Improve retailer performance ranking
- Access claim & service support

---

@component('mail::button', ['url' => $loginUrl])
Login & Register Devices
@endcomponent

---

## ⚠️ Important Note
If devices are not registered regularly:
- Warranty activation may be delayed
- Retailer account may become inactive
- Lead allocation may be reduced

If you have already registered today's devices, please ignore this email.

---

Thank you for being a valued partner with **GoElectronix**.

Regards,<br>
**GoElectronix Retailer Support Team**

<div style="text-align:center;margin-top:30px;font-size:12px;color:#888;">
GoElectronix Technologies Pvt. Ltd.<br>
This is an automated system email.
</div>

@endcomponent