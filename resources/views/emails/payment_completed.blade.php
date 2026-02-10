@component('mail::message')

# ✅ Payment Received Successfully

Hello **{{ $device->customer->name ?? 'Customer' }}**, 👋

We have successfully received your payment and your warranty is now **active**.

---

### 🔐 Warranty Details
- **Warranty Code:** {{ $device->w_code }}
- **Device:** {{ $device->brand_name }} {{ $device->model }}
- **IMEI / Serial:** {{ $device->imei1 }}
- **Invoice ID:** {{ $device->invoice_id }}
- **Payment Date:** {{ optional($device->paid_at)->format('d M Y') }}

---

@component('mail::button', ['url' => $device->certificate_link ?? '#'])
View Warranty Certificate
@endcomponent

Thank you for choosing GoElectronix 💙

---

Regards,  
**GoElectronix Team**

@endcomponent