<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Warranty Certificate | Goelectronix</title>
  <style>
    /* ---------- A4 PRINT SETUP ---------- */
    @page { size: A4; margin: 14mm; }
    html, body { height: 100%; }
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Noto Sans", "Helvetica Neue", sans-serif;
      color:#0f172a;
      background:#f3f6ff;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .sheet{
      width: 210mm;
      min-height: 297mm;
      margin: 0 auto;
      padding: 14mm;
      box-sizing: border-box;
      background: #fff;
      position: relative;
      overflow:hidden;
      border: 1px solid #e5e7eb;
    }

    /* ---------- BRAND WATERMARK / PATTERN ---------- */
    .wm{
      position:absolute; inset:-40mm;
      background:
        radial-gradient(800px 500px at 20% 10%, rgba(37,99,235,.14), transparent 60%),
        radial-gradient(800px 520px at 85% 20%, rgba(34,197,94,.12), transparent 55%),
        radial-gradient(900px 520px at 75% 85%, rgba(245,158,11,.10), transparent 60%),
        linear-gradient(135deg, rgba(2,6,23,.02), rgba(2,6,23,0));
      z-index:0;
      pointer-events:none;
    }
    .wm:after{
      content:"GOELECTRONIX";
      position:absolute;
      right:-30mm;
      bottom: 35mm;
      font-weight:900;
      letter-spacing: 10px;
      font-size: 64px;
      color: rgba(2,6,23,.04);
      transform: rotate(-12deg);
      white-space:nowrap;
    }

    /* ---------- HEADER ---------- */
    .header{
      position: relative;
      z-index:1;
      border: 1px solid rgba(37,99,235,.22);
      background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(255,255,255,.92));
      border-radius: 14px;
      padding: 12px 12px;
    }
    .headRow{
      display:flex;
      gap:12px;
      align-items:flex-start;
      justify-content:space-between;
    }
    .brand{
      display:flex;
      gap:12px;
      align-items:center;
      min-width: 60%;
    }
    .logo{
      width:56px;
      height:56px;
      border-radius: 14px;
      border: 1px solid #e5e7eb;
      background:#fff;
      display:grid;
      place-items:center;
      overflow:hidden;
      flex: 0 0 auto;
      box-shadow: 0 14px 28px rgba(37,99,235,.14);
    }
    .logo img{max-width:100%; max-height:100%; display:block;}
    .brand h1{
      margin:0;
      font-size: 16.5px;
      letter-spacing:.2px;
      line-height:1.15;
    }
    .meta{
      margin-top:4px;
      font-size: 11px;
      color:#475569;
      line-height:1.35;
    }

    .docBox{
      text-align:right;
      min-width: 34%;
    }
    .stamp{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 7px 10px;
      border-radius: 999px;
      background: rgba(37,99,235,.12);
      border: 1px solid rgba(37,99,235,.25);
      color:#0b2b6b;
      font-weight: 800;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
    }
    .docMeta{
      margin-top:8px;
      font-size: 11px;
      color:#475569;
      line-height:1.45;
    }

    /* ---------- TITLE STRIP ---------- */
    .titleStrip{
      position: relative;
      z-index:1;
      margin-top: 10px;
      border-radius: 14px;
      border: 1px solid #e5e7eb;
      background: linear-gradient(135deg, rgba(255,255,255,.98), rgba(37,99,235,.04));
      padding: 12px;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
    }
    .titleStrip h2{
      margin:0;
      font-size: 18px;
      letter-spacing:.2px;
    }
    .subtitle{
      margin:4px 0 0 0;
      font-size: 11.5px;
      color:#64748b;
      line-height:1.35;
      max-width: 120mm;
    }
    .statusPills{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      justify-content:flex-end;
      align-items:flex-start;
      min-width: 60mm;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:6px 9px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      font-size: 10.5px;
      font-weight: 800;
      white-space:nowrap;
      background:#fff;
      color:#0f172a;
      text-transform: uppercase;
      letter-spacing:.4px;
    }
    .pill.blue{ background: rgba(37,99,235,.10); border-color: rgba(37,99,235,.25); color:#0b2b6b;}
    .pill.green{ background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.25); color:#065f46;}
    .pill.orange{ background: rgba(245,158,11,.10); border-color: rgba(245,158,11,.28); color:#7a4b00;}

    /* ---------- BODY GRID ---------- */
    .grid{
      position: relative;
      z-index:1;
      margin-top: 10px;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:10px;
    }
    .card{
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      background: rgba(255,255,255,.92);
      padding: 10px 10px 8px;
    }
    .card h3{
      margin:0 0 8px 0;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
      color:#0b2b6b;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .dot{
      width:9px; height:9px; border-radius:999px;
      background:#2563eb;
      box-shadow: 0 0 0 4px rgba(37,99,235,.14);
    }

    .table{
      width:100%;
      border-collapse: separate;
      border-spacing: 0;
      overflow:hidden;
      border-radius: 12px;
      border: 1px solid #e5e7eb;
      background: #f8fafc;
    }
    .table th, .table td{
      padding: 8px 10px;
      font-size: 11.5px;
      border-bottom: 1px solid #e5e7eb;
      vertical-align: top;
    }
    .table tr:last-child th, .table tr:last-child td{ border-bottom:none; }
    .table th{
      width: 42%;
      text-align:left;
      background: rgba(2,6,23,.03);
      color:#0f2a58;
      font-weight: 800;
    }
    .val{ font-weight: 700; color:#0f172a; }
    .muted{ color:#64748b; font-weight: 600; }

    /* ---------- SUMMARY ---------- */
    .summary{
      grid-column: 1 / -1;
      border: 1px solid rgba(37,99,235,.25);
      background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(255,255,255,.95));
      border-radius: 14px;
      padding: 10px 10px;
      display:flex;
      justify-content:space-between;
      gap:10px;
      align-items:stretch;
    }
    .sumLeft{flex:1}
    .sumTitle{
      margin:0;
      font-size: 12px;
      font-weight: 900;
      letter-spacing:.45px;
      text-transform: uppercase;
      color:#0b2b6b;
    }
    .sumText{
      margin:6px 0 0 0;
      font-size: 11.5px;
      color:#475569;
      line-height:1.4;
    }
    .sumRight{
      min-width: 72mm;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:8px;
      align-content:start;
    }
    .kpi{
      background:#fff;
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 12px;
      padding: 8px 10px;
    }
    .kpi .kLabel{
      font-size: 10px;
      color:#64748b;
      font-weight: 800;
      letter-spacing:.4px;
      text-transform: uppercase;
    }
    .kpi .kVal{
      margin-top:5px;
      font-size: 13px;
      font-weight: 950;
      color:#0f172a;
    }

    /* ---------- PRODUCT SECTIONS ---------- */
    .section{
      border:1px solid rgba(2,6,23,.08);
      border-radius: 12px;
      overflow:hidden;
      margin-top:10px;
      background:#f8fafc;
    }
    .section:first-child{margin-top:0}
    .sectionHead{
      display:flex;
      justify-content:space-between;
      gap:10px;
      padding:8px 10px;
      background: rgba(2,6,23,.03);
      border-bottom: 1px solid rgba(2,6,23,.08);
      align-items:center;
    }
    .sectionTitle{
      margin:0;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
      color:#0f2a58;
      font-weight: 900;
    }
    .sectionTag{
      font-size: 10px;
      font-weight: 900;
      letter-spacing:.4px;
      text-transform: uppercase;
      padding: 5px 8px;
      border-radius: 999px;
      border: 1px solid rgba(37,99,235,.25);
      background: rgba(37,99,235,.10);
      color:#0b2b6b;
      white-space:nowrap;
    }
    .sectionTable{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
    }
    .sectionTable th, .sectionTable td{
      padding: 8px 10px;
      font-size: 11.5px;
      border-bottom: 1px solid rgba(2,6,23,.08);
      vertical-align: top;
    }
    .sectionTable tr:last-child th, .sectionTable tr:last-child td{border-bottom:none}
    .sectionTable th{
      width:42%;
      text-align:left;
      color:#0f2a58;
      font-weight: 800;
    }

    /* ---------- PLAN COVERAGES ---------- */
    .covers{
      grid-column: 1 / -1;
      border: 1px solid rgba(34,197,94,.25);
      background: linear-gradient(135deg, rgba(34,197,94,.08), rgba(255,255,255,.95));
      border-radius: 14px;
      padding: 10px;
    }
    .coversTitle{
      margin:0 0 6px 0;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
      color:#065f46;
      font-weight: 900;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .coversIcon{
      width:22px;height:22px;border-radius:8px;
      background: rgba(34,197,94,.14);
      border: 1px solid rgba(34,197,94,.30);
      display:grid;
      place-items:center;
      font-weight:900;
      color:#065f46;
      font-size:12px;
    }
    .chips{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:8px;
    }
    .chip{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:7px 10px;
      border-radius:999px;
      background:#fff;
      border: 1px solid rgba(2,6,23,.10);
      font-size: 11px;
      font-weight: 800;
      color:#0f172a;
    }
    .tick{
      width:18px;height:18px;border-radius:6px;
      background: rgba(34,197,94,.14);
      border: 1px solid rgba(34,197,94,.30);
      display:grid;
      place-items:center;
      font-size:12px;
      color:#065f46;
      line-height:1;
    }
    .coversNote{
      margin:8px 0 0 0;
      font-size: 11.5px;
      color:#475569;
      line-height:1.4;
    }

    /* ---------- CLAIM AVAILABILITY ---------- */
    .claim{
      grid-column: 1 / -1;
      border: 1px solid rgba(245,158,11,.30);
      background: linear-gradient(135deg, rgba(245,158,11,.10), rgba(255,255,255,.95));
      border-radius: 14px;
      padding: 10px;
      display:flex;
      justify-content:space-between;
      gap:10px;
      align-items:flex-start;
    }
    .claimLeft{flex:1}
    .claimTitle{
      margin:0;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
      color:#7a4b00;
      font-weight: 900;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .claimIcon{
      width:22px;height:22px;border-radius:8px;
      background: rgba(245,158,11,.14);
      border: 1px solid rgba(245,158,11,.30);
      display:grid;
      place-items:center;
      font-weight:900;
      color:#7a4b00;
      font-size:12px;
    }
    .claimText{
      margin:6px 0 0 0;
      font-size: 11.5px;
      color:#475569;
      line-height:1.4;
    }
    .claimRight{
      min-width: 72mm;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:8px;
      align-content:start;
    }
    .mini{
      background:#fff;
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 12px;
      padding: 8px 10px;
    }
    .mini .mLabel{
      font-size: 10px;
      color:#64748b;
      font-weight: 800;
      letter-spacing:.4px;
      text-transform: uppercase;
    }
    .mini .mVal{
      margin-top:5px;
      font-size: 13px;
      font-weight: 950;
      color:#0f172a;
    }

    /* ---------- TERMS & CLAIM USAGE ---------- */
    .tc{
      grid-column: 1 / -1;
      border: 1px dashed rgba(37,99,235,.35);
      background: rgba(239,246,255,.55);
      border-radius: 14px;
      padding: 10px;
    }
    .tcTitle{
      margin:0 0 6px 0;
      font-size: 11px;
      letter-spacing:.5px;
      text-transform: uppercase;
      color:#0b2b6b;
      font-weight: 900;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .tcIcon{
      width:22px;height:22px;border-radius:8px;
      background: rgba(37,99,235,.14);
      border: 1px solid rgba(37,99,235,.35);
      display:grid;
      place-items:center;
      font-weight:900;
      color:#0b2b6b;
      font-size:12px;
    }
    .bul{
      margin:0;
      padding-left: 16px;
      color:#334155;
      font-size: 11.5px;
      line-height: 1.45;
    }

    /* ---------- FOOTER ---------- */
    .footer{
      position:absolute;
      left: 14mm;
      right: 14mm;
      bottom: 14mm;
      z-index:1;
      border-top: 1px dashed rgba(100,116,139,.45);
      padding-top: 10px;
      display:flex;
      justify-content:space-between;
      gap:10px;
      align-items:flex-end;
    }

    @media screen and (max-width: 900px){
      .sheet{ width:auto; min-height:auto; border:none; border-radius:0; }
      .grid{ grid-template-columns:1fr; }
      .footer{ position:relative; left:auto; right:auto; bottom:auto; padding: 12px 0 0; }
    }
  </style>
</head>

<body>
  <div class="sheet">
    <div class="wm"></div>

    <!-- HEADER -->
   <div class="header">
      <div class="headRow">
        <div class="brand">
          <div class="logo">
            <img src="YOUR_LOGO_URL_OR_PATH.png" alt="Goelectronix Logo">
          </div>
          <div>
            <h1>Goelectronix Technologies Private Limited</h1>
            <div class="meta">
              <div><strong>CIN:</strong> GE/CIN/XXXXXXXXXXXX</div>
              <div><strong>Company Address:</strong> Address Line 1, Area, City, State, PIN, India</div>
            </div>
          </div>
        </div>

        <div class="docBox">
          <div class="stamp">Warranty Certificate</div>
          <div class="docMeta">
            <div><strong>Certificate No:</strong> {{ $certificateId }}</div>
            <div><strong>Issue Date:</strong> {{ $issuedOn }}</div>
            <div><strong>Status:</strong> Active</div>
          </div>
        </div>
      </div>
    </div>

   <!-- TITLE -->
    <div class="titleStrip">
      <div>
        <h2>Product Warranty & Device Protection Certificate</h2>
        <p class="subtitle">
          This certificate confirms warranty/device protection coverage for the below device as per selected plan terms and eligibility checks.
          Please keep this document for future service/claim reference.
        </p>
      </div>
      <div class="statusPills">
        <div class="pill blue">Coverage Active</div>
        <div class="pill green">Verified</div>
        <div class="pill orange">Retail Only</div>
      </div>
    </div>

    <!-- SUMMARY -->
    <div class="summary">
      <div class="sumLeft">
        <p class="sumTitle">Plan Summary</p>
        <p class="sumText">
          {!! $planSummary ?? 'Coverage and benefits are strictly applicable as per selected plan coverages.' !!}
        </p>
      </div>

      <div class="sumRight">
        <div class="kpi"><div class="kLabel">Device Price</div><div class="kVal">₹ {{ $coverageLimit ?? '00,000' }}</div></div>
        <div class="kpi"><div class="kLabel">Warranty Price</div><div class="kVal">₹ 0,000</div></div>
        <div class="kpi"><div class="kLabel">Start Date</div><div class="kVal">{{ $startDate }}</div></div>
        <div class="kpi"><div class="kLabel">End Date</div><div class="kVal">{{ $endDate }}</div></div>
      </div>
    </div>

    <div class="grid">
      <!-- Retailer -->
      <div class="card">
        <h3><span class="dot"></span> Retailer Details</h3>
        <table class="table">
          <tr><th>Retailer Name</th><td class="val">{{ $retailerName }}</td></tr>
          <tr><th>Retailer Code</th><td class="val">{{ $retailerCode }}</td></tr>
          <tr><th>Retailer Address</th><td class="val">{{ $retailerAddress }}</td></tr>
          <tr><th>Invoice No.</th><td class="val">INV-000000</td></tr>
          <tr><th>Purchase Date</th><td class="val">{{ $purchaseDate }}</td></tr>
        </table>
      </div>

      <!-- Customer -->
      <div class="card">
        <h3><span class="dot"></span> Customer Details</h3>
        <table class="table">
          <tr><th>Customer Name</th><td class="val">{{ $customerName }}</td></tr>
          <tr><th>Customer ID</th><td class="val">CUST-000987</td></tr>
          <tr><th>Customer Address</th><td class="val">Customer Address Line 1, City, State, PIN</td></tr>
          <tr><th>Customer Mobile</th><td class="val">{{ $customerPhone }}</td></tr>
          <tr><th>Customer Email</th><td class="val">customer@email.com</td></tr>
        </table>
      </div>

      <!-- Product / Device -->
      <div class="card" style="grid-column: 1 / -1;">
        <h3><span class="dot"></span> Product & Warranty Details</h3>

        <div class="section">
          <div class="sectionHead">
            <p class="sectionTitle">Device Details</p>
            <span class="sectionTag">Device</span>
          </div>
          <table class="sectionTable">
            <tr><th>Category</th><td class="val">{{ $category }}</td></tr>
            <tr><th>Brand</th><td class="val">{{ $brand }}</td></tr>
            <tr><th>Model</th><td class="val">{{ $model }}</td></tr>
            <tr><th>Identification No.</th><td class="val">{{ $imei1 ?? $serial }}</td></tr>
            <tr><th>Device Price</th><td class="val">₹ {{ $coverageLimit ?? '00,000' }}</td></tr>
          </table>
        </div>

        <div class="section">
          <div class="sectionHead">
            <p class="sectionTitle">Warranty Plan</p>
            <span class="sectionTag">Plan</span>
          </div>
          <table class="sectionTable">
            <tr><th>Plan Name</th><td class="val">{{ $planName }}</td></tr>
            <tr><th>Plan Code</th><td class="val">PLAN-XXXX</td></tr>
            <tr><th>Warranty Type</th><td class="val">Extended Warranty / Screen Protection / Total Protection</td></tr>
            <tr><th>Warranty Price</th><td class="val">₹ 0,000</td></tr>
            <tr><th>Upto Device Value</th><td class="val">₹ {{ $coverageLimit ?? '00,000' }}</td></tr>
          </table>
        </div>

        <div class="section">
          <div class="sectionHead">
            <p class="sectionTitle">Coverage Period</p>
            <span class="sectionTag">Dates</span>
          </div>
          <table class="sectionTable">
            <tr><th>Coverage Start Date</th><td class="val">{{ $startDate }}</td></tr>
            <tr><th>Coverage End Date</th><td class="val">{{ $endDate }}</td></tr>
            <tr><th>Coverage Duration</th><td class="val">12 / 18 / 24 Months</td></tr>
          </table>
        </div>
      </div>

      <!-- CLAIM -->
      <div class="claim">
        <div class="claimLeft">
          <p class="claimTitle"><span class="claimIcon">★</span> Claim Availability</p>
          <p class="claimText">
            Claims/service requests are available during the active coverage period.
          </p>
        </div>
        <div class="claimRight">
          <div class="mini"><div class="mLabel">Claims Available</div><div class="mVal">Yes</div></div>
          <div class="mini"><div class="mLabel">Max Claims</div><div class="mVal">{{ $maxClaims }}</div></div>
          <div class="mini"><div class="mLabel">Claim Type</div><div class="mVal">As per Coverage</div></div>
          <div class="mini"><div class="mLabel">Upto Device Value</div><div class="mVal">₹ {{ $coverageLimit ?? '00,000' }}</div></div>
        </div>
      </div>

      <!-- TERMS -->
      <div class="tc">
        <div class="tcTitle">Warranty Terms & Claim Usage (Quick)</div>
        <ul class="bul">
          <li>Coverage depends strictly as per selected plan.</li>
          <li>No refund after activation.</li>
          <li>Registered device only (IMEI/Serial must match).</li>
          <li>Invoice + Certificate mandatory.</li>
          <li>Valid only between start and end date.</li>
          <li>Verification mandatory.</li>
          <li>Common exclusions apply.</li>
          <li>Customer must keep data backup.</li>
          <li>Full T&C: goelectronix.com</li>
        </ul>
      </div>
    </div>
    <div class="footer"></div>
  </div>
</body>
</html>