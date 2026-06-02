@component('mail::message')

<img src="https://zoho.goelectronix.in/storage/logo.png"
     width="100"
     height="100"
     style="display:block;margin:auto;margin-bottom:20px;" />

# 🎉 Congratulations, {{ $company->contact_person }}!

We’re excited to inform you that your onboarding with  
**GoElectronix Technologies Pvt. Ltd.** has been **successfully completed**.

---

### 📋 Registered Details

- **Name:** {{ $company->business_name }}
- **Email:** {{ $company->contact_email }}
- **Mobile:** {{ $company->contact_phone ?? '—' }}

---

### 🔑 Login Credentials

- **Login URL:** {{ $signinUrl }}
- **Email:** {{ $company->contact_email }}
- **Password:** **{{ $password }}**

---

@component('mail::button', ['url' => $signinUrl])
Login to Dashboard
@endcomponent

> ⚠️ Please change your password after first login.

---

### 🤝 What’s Next?

- Access your dashboard  
- Manage warranties & devices  
- Track claims and reports  

---

Thanks & regards,  
**GoElectronix Team**

@endcomponent