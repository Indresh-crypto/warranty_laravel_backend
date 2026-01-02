<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Customer Product Coverage Certificate</title>
<style>
@page { size: A4; margin: 0; }

body {
  margin: 0;
  font-family: 'DejaVu Sans', sans-serif;
  background: #f5f7fb;
  color: #1e293b;
  font-size: 9pt;
}

.page {
  width: 160mm;
  height: 230mm;
  margin: 10mm auto;
  background: #fff;
  border: 2px solid #1d4ed8;
  border-radius: 8px;
  box-sizing: border-box;
  padding: 8mm 10mm;
  position: relative;
}

/* Header */
.header {
  text-align: center;
  margin-bottom: 4mm;
}
.logo {
  font-size: 18pt;
  font-weight: 800;
  color: #ef4444;
  border: 2px solid #1d4ed8;
  border-radius: 50%;
  width: 18mm;
  height: 18mm;
  line-height: 18mm;
  display: inline-block;
}
.company { font-size: 11pt; font-weight: 700; color: #1d4ed8; margin-top: 2mm; }
.tagline { font-size: 8pt; color: #64748b; }

/* Title */
.title {
  text-align: center;
  background: #1d4ed8;
  color: #fff;
  padding: 2mm 0;
  font-weight: 700;
  font-size: 12pt;
  border-radius: 4px;
  margin: 3mm 0 5mm;
}

/* Info Bar */
.info-bar {
  display: flex;
  justify-content: space-between;
  background: #f0f6ff;
  border-left: 3px solid #1d4ed8;
  border-radius: 5px;
  padding: 2mm 4mm;
  margin-bottom: 4mm;
}
.info-bar span { font-weight: bold; color: #475569; }

/* Sections */
.section {
  border-left: 3px solid #1d4ed8;
  border-radius: 5px;
  padding: 3mm 4mm;
  background: #f8fafc;
  margin-bottom: 3.5mm;
}
.section.red { border-left-color: #ef4444; background: #fff5f5; }

.section h2 {
  font-size: 9.5pt;
  font-weight: 700;
  color: #1d4ed8;
  margin: 0 0 2mm;
}
.section.red h2 { color: #ef4444; }

.grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 2mm 5mm;
}
.field .label { color: #64748b; font-size: 8pt; }
.field .value { font-size: 9pt; font-weight: 600; color: #1e293b; }

/* Notes */
.notes {
  background: #f0f6ff;
  border-left: 3px solid #1d4ed8;
  border-radius: 5px;
  padding: 2.5mm 4mm;
  font-size: 8pt;
  color: #475569;
  margin-top: 3mm;
}
.notes h3 {
  font-size: 9pt;
  color: #1d4ed8;
  margin: 0 0 1.5mm;
}
.notes ul {
  margin: 0;
  padding-left: 14px;
}

/* Footer */
.footer {
  border-top: 2px solid #ef4444;
  margin-top: 4mm;
  padding-top: 2mm;
  display: flex;
  justify-content: space-between;
  font-size: 7.8pt;
  color: #475569;
}
.signatures { text-align: right; }
.sig-line {
  width: 45mm;
  height: 1px;
  background: #1d4ed8;
  margin-bottom: 1.5mm;
}
.sig-line.red { background: #ef4444; }
.cap { font-size: 7pt; color: #64748b; }

/* QR */
.qr {
  position: absolute;
  right: 10mm;
  bottom: 12mm;
  text-align: center;
}
.qr img {
  width: 65px;
  height: 65px;
  border: 1px solid #1d4ed8;
  border-radius: 6px;
  padding: 2px;
}
.qr small {
  display: block;
  color: #64748b;
  font-size: 7pt;
  margin-top: 1.5mm;
}
</style>
</head>
<body>
<div class="page">
  <!-- Header -->
  <div class="header">
    <div class="company">GoelectroniX Technologies Pvt. Ltd.</div>
    <div class="tagline">Trusted Warranty Partner</div>
  </div>


  <div class="title">Customer Product Coverage Certificate</div>

  <!-- Info Bar -->
  <div class="info-bar">
    <div><span>Certificate ID:</span> {{ $certificateId }}</div>
    <div><span>Coverage:</span> {{ $startDate }} → {{ $endDate }}</div>
  </div>

  <!-- Customer -->
  <div class="section">
    <h2>Customer Details</h2>
    <div class="grid">
      <div class="field"><div class="label">Name</div><div class="value">{{ $customerName }}</div></div>
      <div class="field"><div class="label">Phone</div><div class="value">{{ $customerPhone }}</div></div>
    </div>
  </div>

  <!-- Product -->
  <div class="section red">
    <h2>Product Details</h2>
    <div class="grid">
      <div class="field"><div class="label">Product</div><div class="value">{{ $brand }} {{ $model }}</div></div>
   <!--   <div class="field"><div class="label">Category</div><div class="value">{{ $category }}</div></div> !-->
      <div class="field"><div class="label">IMEI / Serial</div><div class="value">{{ $imei1 }} / {{ $serial ?? '—' }}</div></div>
      <div class="field"><div class="label">Purchase Date</div><div class="value">{{ $purchaseDate }}</div></div>
      <div class="field"><div class="label">Coverage Plan</div><div class="value">{{ $planName }} ({{ $planSummary }})</div></div>
      <div class="field"><div class="label">Claims / Limit</div><div class="value">{{ $maxClaims }} / ₹{{ $coverageLimit }}</div></div>
    </div>
  </div>

  <!-- Retailer -->
  <div class="section">
    <h2>Retailer Details</h2>
    <div class="grid">
      <div class="field"><div class="label">Retailer Name</div><div class="value">{{ $retailerName }}</div></div>
      <div class="field"><div class="label">Retailer Code</div><div class="value">{{ $retailerCode }}</div></div>
      <div class="field" style="grid-column:1 / span 2;">
        <div class="label">Address</div>
        <div class="value">{{ $retailerAddress }}</div>
      </div>
    </div>
  </div>

  <!-- Notes -->
  <div class="notes">
    <h3>Important</h3>
    <ul>
      <li>This certificate confirms active coverage for the mentioned product.</li>
      <li>Claims must be initiated within the coverage period.</li>
      <li>Refer to our website for detailed Terms & Conditions.</li>
    </ul>
  </div>

  <!-- Footer -->
  <div class="footer">
    <div>
      <div>Support: +91 93720 11028</div>
      <div>Terms: https://goelectronix.in/terms/warranty</div>
      <div>Issued on: {{ $issuedOn }}</div>
    </div>
   
  </div>

  <!-- QR -->
</div>
</body>
</html>