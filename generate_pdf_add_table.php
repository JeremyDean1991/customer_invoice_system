<?php
require 'vendor/autoload.php';
require 'db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * ===== CONFIG: company info =====
 */
const EXPORTER_NAME    = 'Arts & Crafts';
const EXPORTER_ADDR    = 'Gr Floor Shop No 12, KH No. 226, Gauransh Homes Sector 16B, Noida, UTTAR PRADESH, 201301';
const EXPORTER_GSTIN   = '90ACCPCO8945Q1ZM';
const EXPORTER_IEC     = '0517517370';
const INVOICE_CURRENCY = 'USD';

/**
 * ===== CONFIG: Excel column map (edit here if your headers differ) =====
 * Keys are logical names, values are the exact column headers in the Excel file.
 */
$COLUMNS = [
  'invoice'   => 'Invoice',
  'date'      => 'Date',                     // Excel may have serial date or text date
  'cust_name' => 'Customer name',
  'addr1'     => 'Address 01',
  'addr2'     => 'Address 02',
  'city'      => 'City',
  'state'     => 'State',
  'zip'       => 'Zip Code',
  'country'   => 'Country',

  // line items
  'desc'      => 'Description',
  'hsn'       => 'HSN',
  'qty'       => 'Qty',
  'unit'      => 'Unit',
  'rate'      => 'FOB',                      // or 'Rate'
  'net'       => 'Gr Weight Nt',             // or 'Net Weight'
  'gross'     => 'Weight',                   // or 'Gross Weight'
  'tracking'  => 'Postal tracking number',   // or 'Tracking'
];

/** ===== helpers ===== */
$toFloat = function($v) {
    // normalize common number formats (e.g., "1,234.50" or "1 234,50")
    if ($v === null) return 0.0;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    // if it contains comma as decimal, swap
    if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : 0.0;
};

$fmtMoney = function($n) {
    return number_format((float)$n, 2, '.', ',');
};
$fmtQty = function($n) {
    // show as integer if whole, else up to 2 decimals
    $f = (float)$n;
    return fmod($f, 1.0) == 0.0 ? number_format($f, 0) : number_format($f, 2, '.', ',');
};

$excelDateToPhp = function($v) {
    // If excel serial (>= 25569), convert; else return as string or today
    // 25569 = 1970-01-01 in Excel 1900 system
    if (is_numeric($v)) {
        $ts = ((int)$v - 25569) * 86400;
        if ($ts > 0) return date('d-m-Y', $ts);
    }
    $s = trim((string)$v);
    if ($s === '') return date('d-m-Y');
    // try parsing a few common formats
    $t = strtotime($s);
    return $t ? date('d-m-Y', $t) : $s;
};

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit('No file provided.');
}

$fileName = $_GET['file'];
$filePath = __DIR__ . "/files/" . $fileName;

if (!is_file($filePath)) {
    http_response_code(404);
    exit('File not found: ' . htmlspecialchars($filePath));
}

try {
    $spreadsheet = IOFactory::load($filePath);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Excel load error: ' . $e->getMessage());
}

$sheet = $spreadsheet->getActiveSheet();
$highestRow = $sheet->getHighestRow();
$highestCol = $sheet->getHighestColumn();

// Read header row as field names
$headersRow = $sheet->rangeToArray("A1:{$highestCol}1", null, true, true, true)[1];
$headers = [];
foreach ($headersRow as $col => $name) {
    $name = trim((string)$name);
    if ($name !== '') $headers[$col] = $name;
}
if (!$headers) exit('No headers found in the first row of the Excel.');

// Build rows as associative arrays keyed by header name
$rows = [];
for ($r = 2; $r <= $highestRow; $r++) {
    $line = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}", null, true, true, true)[$r];

    // Skip completely empty lines
    $allEmpty = true;
    foreach ($line as $v) { if (trim((string)$v) !== '') { $allEmpty = false; break; } }
    if ($allEmpty) continue;

    $assoc = [];
    foreach ($headers as $col => $hName) {
        $assoc[$hName] = isset($line[$col]) ? trim((string)$line[$col]) : '';
    }
    $rows[] = $assoc;
}
if (!$rows) exit('No data rows found beneath the header.');

/** group by invoice number */
$groups = [];
$colInvoice = $COLUMNS['invoice'];
foreach ($rows as $row) {
    $inv = $row[$colInvoice] ?? '';
    if ($inv === '') $inv = 'NO-INVOICE';
    if (!isset($groups[$inv])) $groups[$inv] = [];
    $groups[$inv][] = $row;
}

// TCPDF
require_once 'vendor/tecnickcom/tcpdf/tcpdf.php';
$generated = [];

foreach ($groups as $invoiceNo => $items) {

    // Header/customer data (from first row)
    $f            = $items[0];
    $customerName = $f[$COLUMNS['cust_name']] ?? '';
    $addr1        = $f[$COLUMNS['addr1']]     ?? '';
    $addr2        = $f[$COLUMNS['addr2']]     ?? '';
    $city         = $f[$COLUMNS['city']]      ?? '';
    $state        = $f[$COLUMNS['state']]     ?? '';
    $zip          = $f[$COLUMNS['zip']]       ?? '';
    $country      = $f[$COLUMNS['country']]   ?? '';
    $rawDate      = $f[$COLUMNS['date']]      ?? '';

    $invoiceDate  = $excelDateToPhp($rawDate);

    $consigneeBlock = trim(implode(', ', array_filter([
        $customerName, $addr1, $addr2,
        trim($city . ' ' . $zip), $state, $country
    ])));

    // ===== INVOICE =====
    $pdf = new TCPDF('L', 'mm', 'A4');
    $pdf->SetCreator('customer_system');
    $pdf->SetAuthor(EXPORTER_NAME);
    $pdf->SetTitle('Invoice ' . $invoiceNo);
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);

    $invHtml = '
    <style>
      div{font-size:12px; font-family: Times; padding: 0px; margin: 0px; display: inline-block;  line-height: 1;}
      td {border:0.6px solid #000; padding: 0; margin: 0;}
      tr {border:0.6px solid #000; padding: 0; margin: 0;}
      tr {border:0.6px solid #000; padding: 0; margin: 0;}
      
    </style>

    <table width="100%" cellspacing="0" cellpadding="0">
      <tr>
        <td style="height: 50px;">
          <div>
            Arts & Crafts
          </div>
        </td>
      </tr>
      <tr>
        <td style="height: 30px; text-align: center;">
           <div style="font-weight: bold; text-align:center;" >
            EXPORT INVOICE
          </div>
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;">
          <div>
            SUPPLY MEANT FOR EXPORT UNDER LUT WITHOUT PAYMENT OF INTEGRATED TAX(IGST)
          </div>
        </td>
      </tr>
      <tr>
        <td style="height: 70px; padding: 2px; width: 50%;">
          
            Exporter
            <br>
          
          
            Arts & Crafts
          
            <br>
            Gr Floor Shop No 12, KH No. 226, Gauransh Homes Sector 16B,
          
            <br>
            Noida, UTTAR PRADESH, 201301
          
            <br>
            Noida
          
            <br>
            201301
          
        </td>
        <td style="height: 70px; padding: 5px; width: 35%;">
          
            Invoice No: P001181
            <br>
          
            Invoice Date: 06-02-2024
            <br>
          
            Ref/Order No.: 503-7147797-6577441
            <br>
          
            Transaction ID: 503-7147797-6577441
          
            <br>
            Invoice Terms: Advance Payment
          
            <br>
            Currency: USD
          
        </td>
        <td style="height: 70px; padding: 5px; width: 15%;">
          
        </td>
      </tr>
      <tr>
        <td style="height: 100px; padding: 2px; width: 50%;">
          
            Consignee
          
            <br>
            Margaret Rooney
          
            <br>
            71 Marmora Tce
          
            <br>
            North Haven
          
            <br>
            North Haven
          
            <br>
             5018
          
            <br>
             Australia
          
            <br>
             403024398
          
        </td>
        <td style="height: 100px; padding: 5px; width: 50%;">
          <table width="100%">
            <tr>
              <td style="height: 20px; ">
                
                  Mode of IGST Payment: Export under LUT/Bond
                
                  <br>
                  LUT (if any): undefined
                
              </td>
            </tr>
            <tr>
              <td style="height: 40px; ">
                
                  Exporter’s Ref/IEC: 0517517370
                
                  <br>
                  PAN:
                
                  <br>
                  GSTIN: 90ACCPCO8945Q1ZM 
                
                  <br>
                  State: Uttar Pradesh
                
              </td>
            </tr>
            <tr>
              <td style="height: 20px; width: 50%; ">
                
                  Buyer: Same as Consignee
                
                  <br>
                  Country of Final Destination: Australia
                
              </td>
              <td style="height: 20px; width: 50%;">
                
                  Country of Origin: India
                
                  <br>
                  Shipping Port Code: INDELS
                
              </td>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="height: 20px; width: 5%">
          
            S.NO
          
        </td>
        <td style="height: 20px; width: 8%">
          
            Descritption 
          
        </td>
        <td style="height: 20px; width: 7%">
          
            HSN
          
        </td>
        <td style="height: 20px; width: 5%">
          
            Net Wt.(gms)
          
        </td>
        <td style="height: 20px; width: 5%">
          
            Gross Wt.(gms)
          
        </td>
        <td style="height: 20px; width: 7%">
          
            Qty
          
        </td>
        <td style="height: 20px; width: 5%">
          
            Rate Per Unit
            
        </td>
        <td style="height: 20px; width: 8%">
          
            Amount (USD)
          
        </td>
        <td style="height: 20px; width: 8%">
          
            Exchange Rate
          
        </td>
        <td style="height: 20px; width: 5%">
          
            FOB Value (in INR)
          
        </td>
        <td style="height: 20px; width: 5%">
          
            Freight (in INR)
          
        </td>
        <td style="height: 20px; width: 7%">
          
            Insurance (in INR)
          
        </td>
        <td style="height: 20px; width: 5%">
          
            Taxable Value
          
        </td>
        <td style="height: 20px; width: 10%">
          <table cellspacing="0" cellpadding="0">
            <tr>
              <td>
                
                  IGST
                
              </td>
            </tr>
            <tr>
              <td style="width: 40%;">
                
                  %
                
              </td>
              <td style="width: 60%;">
                
                  Amount
                
              </td>
            </tr>
          </table>
        </td>
        <td style="height: 20px; width: 10%">
          <table style="height: 20px">
            <tr>
              <td>
                
                  Cess
                
              </td>
            </tr>
            <tr>
              <td style="width: 40%;">
                
                  %
                
              </td>
              <td style="width: 60%;">
                
                  Amount
                
              </td>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="height: 20px; width: 5%;">
          
            1
          
        </td>
        <td style="height: 20px; width: 8%;">
          
            Cotton Thread
          
        </td>
        <td style="height: 20px; width: 7%;">
          
            52071000
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            200
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            200
          
        </td>
        <td style="height: 20px; width: 7%;">
          
            1 RIECES
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            20.00
            
        </td>
        <td style="height: 20px; width: 8%;">
          
            20.00
          
        </td>
        <td style="height: 20px; width: 8%;">
          
            82.20
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            1644.00
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            0.00
          
        </td>
        <td style="height: 20px; width: 7%;">
          
            0.00
          
        </td>
        <td style="height: 20px; width: 5%;">
          
            1644.00
          
        </td>
        <td style="height: 20px; width: 4%;">
          
            0.00
            
        </td>
        <td style="height: 20px; width: 6%;">
          
            0.00
            
        </td>
        <td style="height: 20px; width: 4%;">
          
            0.00
            
        </td>
        <td style="height: 20px; width: 6%;">
          
            0.00
            
        </td>
      </tr>
      <tr>
        <td style="width: 58%; height: 20px;">
          
            Total
          
        </td>
        <td style="width: 5%; height: 20px;">
          
            1644.00
          
        </td>
        <td style="width: 5%; height: 20px;">
          
            0.00
          
        </td>
        <td style="width: 7%; height: 20px;">
          
            0.00
          
        </td>
        <td style="width: 5%; height: 20px;">
          
            1644.00
          
        </td>
        <td style="width: 4%; height: 20px;">
        </td>
        <td style="width: 6%; height: 20px;">
          
            0.00
          
        </td>
        <td style="width: 4%; height: 20px;">
        </td>
        <td style="width: 6%; height: 20px;">
          
            0.00
          
        </td>
      </tr>
      <tr>
        <td style="height: 100px; width: 58%;">
          <table style="height: 100px;">
            <tr >
              <td style="height: 40px;">
                
                  Amount in Words:
                
                  <br>
                  One Thousand Six Hundred Forty Four Rupees Only
                
              </td>
            </tr>
            <tr>
              <td style="height: 60px;">
                
                  Declaration
                
              </td>
            </tr>
          </table>
        </td>
        <td style="height: 100px; width: 42%;">
          <table style="height: 100px;">
            <tr >
              <td style="height: 60px;">
                
                  Total FOB Value (INR):
                
                  <br>
                  Total CIF Value (INR):
                
                <br>
                  Total Taxes (INR):
                
                <br>
                  Total Amount (INR):
                
              </td>
            </tr>
            <tr>
              <td style="height: 40px;">
                
                  Authorized Signatory
                
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>';

    

    $pdf->writeHTML($invHtml, true, false, true, false, '');

    // Save invoice
    $safeInvNo   = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$invoiceNo);
    $invoiceOut  = "files/invoice_{$safeInvNo}.pdf";
    $pdf->Output(__DIR__ . '/' . $invoiceOut, 'F');

    // ===== PBE =====
    $pdf2 = new TCPDF('L', 'mm', 'A4');
    $pdf2->SetCreator('customer_system');
    $pdf2->SetAuthor(EXPORTER_NAME);
    $pdf2->SetTitle('PBE ' . $invoiceNo);
    $pdf2->SetMargins(8, 8, 8);
    $pdf2->SetAutoPageBreak(true, 10);
    $pdf2->AddPage();
    $pdf2->SetFont('helvetica', '', 9);

    $pbeHtml = '
    <style>
      td {border:0.6px solid #000; padding: 0; margin: 0; font-family: Arial;}
    </style>

    <table>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          FORM-II
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          (see regulation 4)
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          Postal Bill of Export - I (PBE - I)
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          (For export of goods through E-Commerce)
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          (To be submitted in duplicate)
        </td>
      </tr>
      <tr>
        <td style="height: 10px; text-align: center;"> 
          
        </td>
      </tr>
    </table>
    <table>
      <tr>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 9%; font-size: 8px;"> 
          Bill of Export No. <br> & Date.
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 15%; font-size: 8px;"> 
          Foreign Post Office Code
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 15%; font-size: 8px;"> 
          Name of Exporter
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 10%; font-size: 8px;"> 
          Address of Exporter
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 8%; font-size: 8px;"> 
          IEC
        </td>
        <td rowspan="4" colspan="2" style="height: 25px; text-align: center; width: 7%; font-size: 8px;"> 
          State Code
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 11%; font-size: 8px;"> 
          GSTIN of as <br>applicable
        </td>
        <td rowspan="3" colspan="2" style="height: 25px; text-align: center; width: 10%; font-size: 8px;"> 
          AD code (if <br>applicable)
        </td>
        <td rowspan="1" colspan="2" style="height: 25px; text-align: center; width: 15%; font-size: 8px;"> 
            Details of Customs Broker
        </td>
      </tr>
      <tr>
      	<td rowspan="1" colspan="1" style="height: 25px; text-align: center; width: 6.3%; font-size: 8px;"> 
            License <br>No
        </td>
        <td rowspan="1" colspan="1" style="height: 25px; text-align: center; width: 8.7%; font-size: 8px;"> 
          Name and Address
        </td>
      </tr>
    </table>
    <table>
      <tr>
        <td style="height: 10px; text-align: center; width: 9%; font-size: 8px;"> 
          27.02.2024
        </td>
        <td style="height: 12px; text-align: center; width: 15%; font-size: 8px;"> 
          725
        </td>
        <td style="height: 12px; text-align: center; width: 15%; font-size: 8px;"> 
          SUN.B. INTERNATIONAL
        </td>
        <td style="height: 12px; text-align: center; width: 10%; font-size: 8px;"> 
          B-5, SECTOR-3, DSIIDC, BAWANA <br>
          INDUSTRIAL ARIA NEW DELHI-  <br>
          110039 
        </td>
        <td style="height: 12px; text-align: center; width: 8%; font-size: 8px;"> 
          5000016143
        </td>
        <td style="height: 12px; text-align: center; width: 7%; font-size: 8px;"> 
          7
        </td>
        <td style="height: 12px; text-align: center; width: 7%; font-size: 8px;"> 
          07AAIPB4716E1Z5
        </td>
        <td style="height: 12px; text-align: center; width: 7%; font-size: 8px;"> 
          07AAIPB4716E1Z5
        </td>
        <td style="height: 12px; text-align: center; width: 7%; font-size: 8px;"> 
          510005
        </td>
        <td style="height: 12px; text-align: center; width: 6.3%; font-size: 8px;"> 
            NA.
        </td>
        <td style="height: 12px; text-align: center; width: 8.7%; font-size: 8px;"> 
            NA.
        </td>
      </tr>
      <tr>
        <td style="height: 12px; text-align: center; width: 91.3%; font-size: 8px;"> 
          Declarations
        </td>
        <td style="height: 12px; text-align: center; width: 8.7%; font-size: 8px;"> 
          Yes/No as
        </td>
      </tr>
      <tr>
        <td style="height: 12px;  width: 9%; font-size: 8px; "> 
          1
        </td>
        <td style="height: 12px;  width: 82.3%; font-size: 8px; "> 
          We declare that we intend to claim rewards under Merchandise Exports from India Scheme (MEIS) (for export through Chennai / Mumbai / Delhi FPO Only)
        </td>
        <td style="height: 12px;  width: 8.7%; font-size: 8px; text-align: center;"> 
          No
        </td>
      </tr>
      <tr>
        <td style="height: 12px;  width: 9%; font-size: 8px; "> 
          2
        </td>
        <td style="height: 12px;  width: 82.3%; font-size: 8px;"> 
          We declare that we intend to zero rate our exports under Section 16 of IGST Act.
        </td>
        <td style="height: 12px;  width: 8.7%; font-size: 8px; text-align: center;"> 
          Yes
        </td>
      </tr>
      <tr>
        <td style="height: 12px;  width: 9%; font-size: 8px; "> 
          3
        </td>
        <td style="height: 12px;  width: 82.3%; font-size: 8px;"> 
          We declare that the goods are exempted under CGST/SGST/UTGST/IGST Acts.
        </td>
        <td style="height: 12px;  width: 8.7%; font-size: 8px; text-align: center;"> 
          No
        </td>
      </tr>
      <tr>
        <td style="height: 40px; font-size: 8px; width: 100%;">
          We hereby declare that the contents of this postal bill of export are true and correct in every respect
          <br>
          <br>
          (Signature of the Exporter/Authorise agent)
        </td>
      </tr>
      <tr>
        <td style="height: 40px; font-size: 8px; width: 100%;">
          Examination order and report
          <br>
          <div style="text-align: center;">
            Let Export Order: Signature of officer of Customs along with stamp and date:
          </div>
        </td>
      </tr>
      <tr>
        <td style="height: 12px;  width: 9%; font-size: 8px; text-align: center"> 
        </td>
        <td style="height: 12px;  width: 91%; font-size: 8px; text-align: center"> 
          Details of Parcel
        </td>
      </tr>
    </table>
    <table style="width:100%">
      <tr>
        <td rowspan="3" style="width: 5%; font-size: 8px; text-align: center">S.No.</td>
        <td rowspan="1" colspan="4" style="font-size: 8px; width: 30%; text-align: center;">Consignee Details</td>
        <td rowspan="1" colspan="4" style="font-size: 8px; width: 20%; text-align: center;">Product Details</td>
        <td rowspan="1" colspan="4" style="font-size: 8px; width: 15%; text-align: center;">Details of Parcel</td>
        <td rowspan="1" colspan="4" style="font-size: 8px; width: 30%; text-align: center;">E-commerce particulars</td>
      </tr>
      <tr>
      <td rowspan="2" colspan="2" style="font-size: 8px; width: 20%; text-align: center;">Name & Address</td>
        <td rowspan="2" colspan="2" style="font-size: 8px; width: 10%; text-align: center;">Country Of <br> Destination</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">Description</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 4%; text-align: center;">CTM</td>
        <td rowspan="1" colspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">Quantity</td>
        <td rowspan="2" colspan="2" style="font-size: 8px; width: 8%; text-align: center;">Invoice Number and Date</td>
        <td rowspan="1" colspan="2" style="font-size: 8px; width: 7%; text-align: center;">Weight</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 7%; text-align: center;">URL(Name)</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 10%; text-align: center;">Payment transaction ID (12)</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 5%; text-align: center;">SKU No.</td>
        <td rowspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">Postal tracking number (14)</td>
      </tr>
      <tr>
      <td rowspan="1" style="font-size: 8px; width: 3%; text-align: center;">Unit</td>
        <td rowspan="1" style="font-size: 8px; width: 5%; text-align: center;">Number</td>
        <td style="font-size: 8px; width: 4%; text-align: center;">Gross</td>
        <td style="font-size: 8px; width: 3%; text-align: center;">Net</td>
      </tr>
      <tr>
        <td  style="width: 5%; font-size: 8px; text-align: center; width: 5%;">1</td>
        <td  style="font-size: 8px; text-align: center; width: 20%;">AHUVA HOSHEN RVIVIM 131 ROSH HAAYIN 486210</td>
        <td style="font-size: 8px; width: 10%; text-align: center;">ISRAEL</td>
        <td style="font-size: 8px; width: 8%; text-align: center;">1 cotton water glass</td>
        <td style="font-size: 8px; width: 4%; text-align: center;">741833</td>
        <td style="font-size: 8px; width: 3%; text-align: center;">PESCTS</td>
        <td style="font-size: 8px; width: 5%; text-align: center;">1</td>
        <td style="font-size: 8px; width: 8%; text-align: center;">PO01929 <br> 06/02/2024 </td>
        <td style="font-size: 8px; width: 4%; text-align: center;">620</td>
        <td style="font-size: 8px; width: 3%; text-align: center;">620</td>
        <td  style="font-size: 8px; width: 7%; text-align: center;">www.amazon.com</td>
        <td  style="font-size: 8px; width: 10%; text-align: center;">249-7420141 <br>-783024</td>
        <td  style="font-size: 8px; width: 5%; text-align: center;">YT_PWGB-CLPZ</td>
        <td style="font-size: 8px; width: 8%; text-align: center;">
          LP15951943W
        </td>
      </tr>
    </table>
    <br>
    <br>
    <br><table>
      <tr>
        <td rowspan="1" colspan="4" style="width: 25%; font-size: 8px; text-align: center">Assessable value under section 14 of the Customs Act</td>
        <td rowspan="1" colspan="4" style="width: 20%; font-size: 8px; text-align: center">Details of Tax invoice or commercial invoice <br>
    (whichever applicable)</td>
        <td rowspan="1" colspan="9" style="width: 40%; font-size: 8px; text-align: center">Details of duty/tax</td>
        <td rowspan="2" colspan="2" style="width: 15%; font-size: 8px; text-align: center">Total</td>
      </tr>
      <tr>
        <td rowspan="3" colspan="1" style="width: 7%; font-size: 8px; text-align: center">FOB (15)</td>
        <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Currency <br>(16)</td>
        <td rowspan="3" colspan="1" style="width: 8%; font-size: 8px; text-align: center">Exchange Rate (17)</td>
        <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Amount in <br>INR (18)</td>
        <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">H.S. Code (19)</td>
        <td rowspan="1" colspan="2" style="width: 10%; font-size: 8px; text-align: center">Invoice details</td>
        <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Value (22)</td>
        <td rowspan="1" colspan="4" style="width: 17%; font-size: 8px; text-align: center">Customs duties</td>
        <td rowspan="1" colspan="5" style="width: 23%; font-size: 8px; text-align: center">GST details</td>
      </tr>
      <tr>
        <td rowspan="2" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Invoice no. & <br>date. (20)</td>
        <td rowspan="2" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Sl.No. of <br>item in <br>invoice<br> (21)</td>
        <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">Export duty</td>
        <td rowspan="1" colspan="2" style="width: 9%; font-size: 8px; text-align: center">Cess</td>
        <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">IGST (if applicable)</td>
        <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">Compensation cess (if <br> applicable)</td>
        <td rowspan="2" colspan="1" style="width: 7%; font-size: 8px; text-align: center">
          LUT/bond details <br>(if applicable) (31)
        </td>
        <td rowspan="2" colspan="1" style="width: 8%; font-size: 8px; text-align: center">
          duties <br>(32)
        </td>
        <td rowspan="2" colspan="1" style="width: 7%; font-size: 8px; text-align: center">
          cess (33)
        </td>
        </tr>
        <tr>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate(23)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount(24)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate(25)</td>
          <td rowspan="1" colspan="1" style="width: 5%; font-size: 8px; text-align: center">amount(26)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate(27)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount(28)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate(29)</td>
          <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount(30)</td>
        </tr>
        <tr>
          <td style="width: 7%; font-size: 8px; text-align: center">53</td>
          <td style="width: 5%; font-size: 8px; text-align: center">USD</td>
          <td style="width: 8%; font-size: 8px; text-align: center">82.90</td>
          <td style="width: 5%; font-size: 8px; text-align: center">4394</td>
          <td style="width: 5%; font-size: 8px; text-align: center">82055930</td>
          <td style="width: 10%; font-size: 8px; text-align: center">SBI/6965/23-24 DATED <br>25.02.2024</td>
          <td style="width: 5%; font-size: 8px; text-align: center"></td>
          <td style="width: 8%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 6%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 7%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 8%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 4%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 7%; font-size: 8px; text-align: center">N/A</td>
          <td style="width: 8%; font-size: 8px; text-align: center"></td>
          <td style="width: 7%; font-size: 8px; text-align: center"></td>
        </tr>
    </table>
    ';

    $pdf2->writeHTML($pbeHtml, true, false, true, false, '');

    $pbeOut = "files/pbe_{$safeInvNo}.pdf";
    $pdf2->Output(__DIR__ . '/' . $pbeOut, 'F');

    // remember for success page
    $generated[] = ['invoice' => $invoiceOut, 'pbe' => $pbeOut, 'no' => $invoiceNo];

    // Update DB with last generated pair for this upload
    $stmt = $conn->prepare("UPDATE records SET invoice_pdf=?, pbe_pdf=?, status='approved' WHERE excel_file=?");
    $stmt->bind_param("sss", $invoiceOut, $pbeOut, $fileName);
    $stmt->execute();
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>PDFs Generated</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="alert alert-success">
    <h4 class="alert-heading mb-2">✅ PDFs generated successfully!</h4>
    <p class="mb-0">Below are the downloads per invoice number:</p>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <?php if (!$generated): ?>
        <div class="alert alert-warning mb-0">No PDFs created (no invoice groups found).</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Invoice No</th>
                <th>Invoice PDF</th>
                <th>PBE PDF</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($generated as $g): ?>
              <tr>
                <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['no']); ?></span></td>
                <td><a class="btn btn-outline-success btn-sm" target="_blank" href="<?php echo htmlspecialchars($g['invoice']); ?>">Download Invoice</a></td>
                <td><a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?php echo htmlspecialchars($g['pbe']); ?>">Download PBE</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <a class="btn btn-link mt-2" href="records.php">View Records</a>
    </div>
  </div>
</div>
</body>
</html>
