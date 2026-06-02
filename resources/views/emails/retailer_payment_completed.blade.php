@component('mail::message')

# ✅ Payment Received Successfully

Hello **{{ $company->business_name ?? $company->name ?? 'Customer' }}**, 👋

We have successfully received your payment.

---

## 💳 Payment Details

| **Payment ID** | {{ $paymentId }} |
| **Amount Paid** | ₹{{ number_format($amount, 2) }} |
| **Status** | ✅ Successful |
| **Date** | {{ now()->format('d M Y, h:i A') }} |

---

## 🏢 Details

| **Name** | {{ $company->business_name ?? '-' }} |
| **Email** | {{ $company->contact_email ?? $company->email ?? '-' }} |
| **Phone** | {{ $company->contact_phone ?? $company->phone ?? '-' }} |

---

@component('mail::button', ['url' => config('app.url_front')])
Login to Partner Portal
@endcomponent

---

Thanks for choosing **GoElectronix 💙**

Regards,  
**GoElectronix Team**

@endcomponent