@component('mail::message')

# 🧾 Invoice Generated

Your invoice has been successfully generated.

---

### 📄 Invoice Details
- **Invoice Number:** {{ $invoice['invoice_number'] ?? '-' }}
- **Invoice Date:** {{ $invoice['date'] ?? '-' }}
- **Total Amount:** ₹ {{ $invoice['total'] ?? '-' }}
- **Balance Due:** ₹ {{ $invoice['balance'] ?? '-' }}

---

@component('mail::button', ['url' => $invoiceUrl])
View Invoice
@endcomponent

Please review and complete the payment at the earliest to avoid service disruption.

---

Thanks & regards,  
**GoElectronix Team**

@endcomponent