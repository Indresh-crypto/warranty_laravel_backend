<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<title>Customer Product Coverage Certificate</title>

<style>
@page {
  size: A4;
  margin: 0;
}

* { box-sizing: border-box; }

body {
  margin: 0;
  font-family: "DejaVu Sans", Arial, sans-serif;
  font-size: 9pt;
  color: #0f172a;
  background: #f5f7fb;
}

/* ===== PAGE ===== */
.page {
  width: 210mm;
  height: 297mm;
  position: relative;
  background: #fff;
  page-break-after: always;
}
.page:last-child { page-break-after: auto; }

/* ===== FRAME ===== */
.frame{
  padding-bottom:16mm;
}
/* ===== HEADER ===== */
.header table {
  width: 100%;
  border-collapse: collapse;
}
.header td { vertical-align: middle; }

.logoBox {
  width: 26mm;
  height: 26mm;
}
.logoBox img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* ===== TITLES ===== */
.title {
  background: #1d4ed8;
  color: #fff;
  text-align: center;
  padding: 3mm;
  font-size: 12pt;
  font-weight: 800;
  border-radius: 6px;
  margin: 4mm 0;
}

/* ===== SECTIONS ===== */
.section {
  border-left: 4px solid #1d4ed8;
  background: #f8fafc;
  border-radius: 6px;
  padding: 3mm 4mm;
  margin-bottom: 4mm;
}
.section.red {
  border-left-color: #ef4444;
  background: #fff5f5;
}
.section h2 {
  font-size: 9.5pt;
  margin-bottom: 2mm;
}

/* ===== GRID ===== */
.grid {
  width: 100%;
}
.grid td {
  width: 50%;
  padding: 2mm 0;
  vertical-align: top;
}

/* ===== FOOTER ===== */
.frame{
  position:absolute;
  left:10mm;
  right:10mm;
  top:2mm;
  bottom:10mm;

  border:2px solid var(--blue);
  border-radius:10px;
  padding:10mm;
  padding-bottom:26mm; /* footer-safe */
  background:#fff;
}

.footer{
  position:absolute;
  left:0;
  right:0;
  bottom:10;

  border-top:2px solid var(--red);
  padding:3mm 2mm 2mm;

  font-size:7.8pt;
  color:var(--muted);
}

.footer .name{
  font-weight:800;
  color:var(--ink);
}

.footer .row{
  margin-top:1.2mm;
  line-height:1.35;
}
</style>
</head>

<body>

<!-- ========================= PAGE 1 ========================= -->
<div class="page">
  <div class="frame">

    <!-- Header -->
    <div class="header">
      <table>
        <tr>
          <td class="logoWrap">
            <div class="logoBox">
            
              <!-- Place for Company Logo (provide url/base64 in $companyLogoUrl) -->
             <img src="{{ public_path('clogo.png') }}">
            </div>
          </td>

          <td style="padding-left:4mm;">
            <div class="company">GoelectroniX Technologies Pvt. Ltd.</div>
            <div class="tagline">Trusted Warranty Partner</div>
          </td>

          <td class="meta">
            <div>Certificate ID: <b>{{ $certificateId }}</b></div>
            <div>Issued On: <b>{{ $issuedOn }}</b></div>
          </td>
        </tr>
      </table>
    </div>

    <div class="title">Customer Product Coverage Certificate</div>

    <!-- Info Bar -->
    <div class="info-bar">
      <table>
        <tr>
          <td><b>Certificate ID:</b> {{ $certificateId }}</td>
          <td class="right"><b>Coverage:</b> {{ $startDate }} → {{ $endDate }}</td>
        </tr>
      </table>
    </div>

    <!-- Customer -->
    <div class="section">
      <h2>Customer Details</h2>
      <table class="grid">
        <tr>
          <td>
            <div class="field">
              <div class="label">Name</div>
              <div class="value">{{ $customerName }}</div>
            </div>
          </td>
          <td>
            <div class="field">
              <div class="label">Phone</div>
              <div class="value">{{ $customerPhone }}</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Product -->
    <div class="section red">
      <h2>Product Details</h2>
      <table class="grid">
        <tr>
          <td>
            <div class="field">
              <div class="label">Product</div>
              <div class="value">{{ $brand }} {{ $model }}</div>
            </div>
          </td>
          <td>
            <div class="field">
              <div class="label">IMEI / Serial</div>
              <div class="value">{{ $imei1 }} / {{ $serial ?? '—' }}</div>
            </div>
          </td>
        </tr>

        <!-- keep placeholders untouched; left as-is (commented) -->
        <!--
        <tr>
          <td>
            <div class="field">
              <div class="label">Category</div>
              <div class="value">{{ $category }}</div>
            </div>
          </td>
          <td></td>
        </tr>
        -->

        <tr>
          <td>
            <div class="field">
              <div class="label">Purchase Date</div>
              <div class="value">{{ $purchaseDate }}</div>
            </div>
          </td>
          <td>
            <div class="field">
              <div class="label">Coverage Plan</div>
              <div class="value">{{ $planName }} ({{ $planSummary }})</div>
            </div>
          </td>
        </tr>

        <tr>
          <td colspan="2">
            <div class="field">
              <div class="label">Claims / Limit</div>
              <div class="value">{{ $maxClaims }} / ₹{{ $coverageLimit }}</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Retailer -->
    <div class="section">
      <h2>Retailer Details</h2>
      <table class="grid">
        <tr>
          <td>
            <div class="field">
              <div class="label">Retailer Name</div>
              <div class="value">{{ $retailerName }}</div>
            </div>
          </td>
          <td>
            <div class="field">
              <div class="label">Retailer Code</div>
              <div class="value">{{ $retailerCode }}</div>
            </div>
          </td>
        </tr>
        <tr>
          <td colspan="2">
            <div class="field">
              <div class="label">Address</div>
              <div class="value">{{ $retailerAddress }}</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Notes -->
    <div class="notes">
      <h3>Important</h3>
      <ul>
        <li>This certificate confirms active coverage for the mentioned product.</li>
        <li>Claims must be initiated within the coverage period.</li>
        <li>Refer to our website for detailed Terms &amp; Conditions.</li>
      </ul>
    </div>
    <hr/>
    <!-- Footer (Fixed values as requested) -->
    <div class="footer">
      <div class="name">Goelectronix Technologies private limited</div>
      <div class="row">Unit No. 403, 4th Floor, Ellora Olearise Plot No. A-786, TTC Industrial Area MIDC, Kopar Khairane, Navi Mumbai, Maharashtra – 400709, India</div>
      <div class="row">CIN :- U74110MH2020PTC341758 | email : hello@goelectronix.com</div>
    </div>

  </div>
</div>


<!-- ========================= PAGE 2 ========================= -->
<div class="page">
  <div class="frame">

    <!-- Header (repeat) -->
    <div class="header">
      <table>
        <tr>
          <td class="logoWrap">
            <div class="logoBox">
             <img src="{{ public_path('clogo.png') }}">
            </div>
          </td>

          <td style="padding-left:4mm;">
            <div class="company">GoelectroniX Technologies Pvt. Ltd.</div>
            <div class="tagline">Trusted Warranty Partner</div>
          </td>

          <td class="meta">
            <div>Certificate ID: <b>{{ $certificateId }}</b></div>
            <div>Coverage: <b>{{ $startDate }} → {{ $endDate }}</b></div>
          </td>
        </tr>
      </table>
    </div>

    <div class="title">Policy Terms & Claim Process</div>

    <div class="section">
      <h2>Terms & Conditions Summary</h2>
      <div class="terms">
        <p><span class="t">1.</span> This certificate confirms active coverage for the mentioned product under the selected plan.</p>
        <p><span class="t">2.</span> Claims must be initiated within the coverage period shown on the certificate.</p>
        <p><span class="t">3.</span> Coverage is subject to verification, inspection, and policy eligibility checks.</p>
        <p><span class="t">4.</span> Unsupported repair / tampering / intentional damage may lead to claim rejection (as per policy rules).</p>
        <p><span class="t">5.</span> For exclusions and full wording, please refer to the official Terms &amp; Conditions.</p>
      </div>
    </div>

    <div class="section red">
      <h2>How to Raise a Claim</h2>
      <div class="terms">
        <p><span class="t">A.</span> Keep your <b>Certificate ID</b> handy: <b>{{ $certificateId }}</b></p>
        <p><span class="t">B.</span> Contact support / retailer channel and share product IMEI/Serial for validation.</p>
        <p><span class="t">C.</span> After approval, next-step instructions will be shared as per policy process.</p>
      </div>
    </div>
    <hr/>
    <!-- Footer (Fixed values as requested) -->
    <div class="footer">
      <div class="name">Goelectronix Technologies private limited</div>
      <div class="row">Unit No. 403, 4th Floor, Ellora Olearise Plot No. A-786, TTC Industrial Area MIDC, Kopar Khairane, Navi Mumbai, Maharashtra – 400709, India</div>
      <div class="row">CIN :- U74110MH2020PTC341758 | email : hello@goelectronix.com</div>
    </div>

  </div>
</div>

</body>
</html>