<?php
require 'vendor/autoload.php';
require 'db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use NumberToWords\NumberToWords;

/**
 * ===== CONFIG: company info =====
 */
const EXPORTER_NAME    = 'Arts & Crafts';
const EXPORTER_ADDR    = 'Gr Floor Shop No 12, KH No. 226, Gauransh Homes Sector 16B, Noida, UTTAR PRADESH, 201301';
const EXPORTER_GSTIN   = '90ACCPCO8945Q1ZM';
const EXPORTER_IEC     = '0517517370';
const INVOICE_CURRENCY = 'USD';

// Download Setting
function forceDownload($filePath)
{
  if (!file_exists($filePath)) {
    http_response_code(404);
    echo "File not found!";
    exit;
  }

  header('Content-Description: File Transfer');
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($filePath));
  readfile($filePath);
  exit;
}

if (isset($_GET['download'])) {
  $file = basename($_GET['download']); // prevent path traversal
  $filePath = __DIR__ . "/files/" . $file;
  forceDownload($filePath);
}

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
  'phone'     => 'Phone',

  'payment_transaction' => 'payment Transaction',
  'reference'  => 'Reference',
  'invoice_terms' => 'Invoice Terms',
  'lut_number' => 'Lut Number',
  'currency'   => 'Currency',
  'url'        => 'URL',
  'sku'        => 'SKU',

  // line items
  'desc'      => 'Description',
  'hsn'       => 'HSN',
  'qty'       => 'Qty',
  'unit'      => 'Unit',
  'rate'      => 'FOB',                      // or 'Rate'
  'net'       => 'Weight Nt',             // or 'Net Weight'
  'gross'     => 'Weight Gr',                   // or 'Gross Weight'
  'tracking'  => 'Tracking',   // or 'Tracking'
];

/** ===== helpers ===== */
$toFloat = function ($v) {
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

$fmtMoney = function ($n) {
  return number_format((float)$n, 2, '.', ',');
};
$fmtQty = function ($n) {
  // show as integer if whole, else up to 2 decimals
  $f = (float)$n;
  return fmod($f, 1.0) == 0.0 ? number_format($f, 0) : number_format($f, 2, '.', ',');
};

$excelDateToPhp = function ($v) {
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
  foreach ($line as $v) {
    if (trim((string)$v) !== '') {
      $allEmpty = false;
      break;
    }
  }
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

// Base name for identity.
$baseName = pathinfo($fileName, PATHINFO_FILENAME);

// TCPDF
require_once 'vendor/tecnickcom/tcpdf/tcpdf.php';

$generated = [];

// ===== INVOICE =====
$invoicePdf = new TCPDF('L', 'mm', 'A4');
$invoicePdf->SetCreator('customer_system');
$invoicePdf->SetAuthor(EXPORTER_NAME);
$invoicePdf->SetTitle('Combined Invoice');
$invoicePdf->SetMargins(8, 8, 8);
$invoicePdf->SetAutoPageBreak(true, 10);

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
  $phone        = $f[$COLUMNS['phone']]     ?? '';
  $rawDate      = $f[$COLUMNS['date']]      ?? '';
  $invoice      = $f[$COLUMNS['invoice']]   ?? '';
  $date         = $f[$COLUMNS['date']]      ?? '';
  $reference    = $f[$COLUMNS['reference']] ?? '';
  $payment_transaction = $f[$COLUMNS['payment_transaction']]      ?? '';
  $invoice_terms = $f[$COLUMNS['invoice_terms']] ?? '';
  $currency     = $f[$COLUMNS['currency']]  ?? '';
  $desc         = $f[$COLUMNS['desc']]      ?? '';
  $hsn          = $f[$COLUMNS['hsn']]       ?? '';
  $netWt        = $f[$COLUMNS['net']]       ?? '';
  $grossW       = $f[$COLUMNS['gross']]     ?? '';
  $qty          = $f[$COLUMNS['qty']]       ?? '';
  $rate         = $f[$COLUMNS['rate']]      ?? '';
  $unit         = $f[$COLUMNS['unit']]      ?? '';
  $tracking     = $f[$COLUMNS['tracking']]  ?? '';
  $amount       = $qty * $rate;
  $exchage_rate = 82.20;
  $fob_inr      = $amount * $exchage_rate;
  $freight_inr  = 0.00;
  $insurance_inr  = 0.00;
  $taxable_value  = $fob_inr + $freight_inr + $insurance_inr;

  // Number into String
  $numberToWords = new NumberToWords();

  // build a number transformer for English
  $numberTransformer = $numberToWords->getNumberTransformer('en');

  // output: Five thousand seven hundred twenty-five
  $amountWords = ucfirst($numberTransformer->toWords($taxable_value)) . " Rupees Only";

  $invoiceDate  = $excelDateToPhp($rawDate);

  $consigneeBlock = trim(implode(', ', array_filter([
    $customerName,
    $addr1,
    $addr2,
    trim($city . ' ' . $zip),
    $state,
    $country
  ])));

  $invoicePdf->AddPage();
  $invoicePdf->SetFont('times', '', 10);

  $invHtml = '
    <style>
      div {
        font-size:12px; 
        font-family: Times; 
        padding: 0px; 
        margin: 0px; 
        display: inline-block;
        line-height: 1;
      }
      table, th, td {
        border: 1px solid black;
        border-collapse: collapse;
      }
    </style>

    <table cellpadding="2">
      <tr>
        <td style="height: 45px;" colspan="17" valign="middle">
          <br>
          <br>
          Arts & Crafts
        </td>
      </tr>
      <tr>
        <th style="height: 35px; text-align: center; padding-top: 10px;" colspan="17">
          <br>
          EXPORT INVOICE
        </th>
      </tr>
      <tr>
        <th style="height: 30px; text-align: center;" colspan="17">
          <br>
          SUPPLY MEANT FOR EXPORT UNDER LUT WITHOUT PAYMENT OF INTEGRATED TAX (IGST)
        </th>
      </tr>
      <tr>
        <td colspan="8" style="height: 45px;">
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
        <td colspan="6" style="">
          Invoice No: ' . $invoice . '
          <br>
          Invoice Date: ' . $date . '
          <br>
          Ref/Order No.: ' . $reference . '
          <br>
          Transaction ID: ' . $payment_transaction . '
          <br>
          Invoice Terms: ' . $invoice_terms . '
          <br>
          Currency: ' . $currency . '
        </td>
        <td colspan="3" style=""></td>
      </tr>

      <tr>
        <td colspan="8" rowspan="3" style="height: 90px;">
          Consignee
          <br>
          ' . $customerName . '
          <br>
          ' . $addr1 . '
          <br>
          ' . $addr2 . '
          <br>
          ' . $city . '
          <br>
          ' . $zip . '
          <br>
          ' . $country . '
          <br>
          ' . $phone . '
        </td>
        <td colspan="11" style="height: 30px;">
          Mode of IGST Payment: Export under LUT/Bond
          <br>
          LUT (if any): undefined
        </td>
      </tr>
      <tr>
        <td colspan="11" style="height: 40px;">
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
        <td colspan="5.5" style="height: 20px;">
          Buyer: Same as Consignee
          <br>
          Country of Final Destination: Australia
        </td>
        <td colspan="5.5">
          Country of Origin: India
          <br>
          Shipping Port Code: INDELS
        </td>
      </tr>
      <tr>
        <td colspan="1" rowspan="2" style="height: 30px;">
          <br>S.NO
        </td>
        <td colspan="1" rowspan="2">
          <br>Descritption 
        </td>
        <td colspan="1" rowspan="2">
          <br>HSN
        </td>
        <td colspan="1" rowspan="2">
          <br>Net Wt.(gms)
        </td>
        <td colspan="1" rowspan="2">
          <br>Gross Wt.(gms)
        </td>
        <td colspan="1" rowspan="2">
          <br>Qty
        </td>
        <td colspan="1" rowspan="2">
          <br>Rate Per Unit
        </td>
        <td colspan="1" rowspan="2">
          <br>Amount (USD)
        </td>
        <td colspan="1" rowspan="2">
          <br>Exchange Rate
        </td>
        <td colspan="1" rowspan="2">
          <br>FOB Value (in INR)
        </td>
        <td colspan="1" rowspan="2">
          <br>Freight (in INR)
        </td>
        <td colspan="1" rowspan="2">
          <br>Insurance (in INR)
        </td>
        <td colspan="1" rowspan="2">
          <br>Taxable Value
        </td>
        <td colspan="2" rowspan="1" style="height: 15px;">
          <br>IGST
        </td>
        <td colspan="2" rowspan="1" style="height: 15px;">
          <br>Cess
        </td>
      </tr>
      <tr>
        <td colspan="1" style="height: 15px;">
          %
        </td>
        <td colspan="1">
          Amount
        </td>
        <td colspan="1">
          %
        </td>
        <td colspan="1">
          Amount
        </td>
      </tr>
      <tr>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>1
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $desc . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $hsn . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $netWt . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $grossW . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $qty . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $rate . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $qty * $rate . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>82.20
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $fob_inr . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $freight_inr . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $insurance_inr . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>' . $taxable_value . '
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>0.00
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>0.00
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>0.00
        </td>
        <td colspan="1" style="height: 30px; text-align: center">
          <br>0.00
        </td>
      </tr>
      <tr>
        <td colspan="9" style="height: 15px; text-align: right">
          Total
        </td>
        <td colspan="1" style="text-align: center">
          <br>' . $fob_inr . '
        </td>
        <td colspan="1" style="text-align: center">
          <br>' . $freight_inr . '
        </td>
        <td colspan="1" style="text-align: center">
          <br>' . $insurance_inr . '
        </td>
        <td colspan="1" style="text-align: center">
          <br>' . $taxable_value . '
        </td>
        <td colspan="1" style="text-align: center"></td>
        <td colspan="1" style="text-align: center">
          <br>0.00
        </td>
        <td colspan="1" style="text-align: center"></td>
        <td colspan="1" style="text-align: center">
          <br>0.00
        </td>
      </tr>
      <tr>
        <td colspan="9" rowspan="2" style="height: 108px;">
          Amount in Words:
          <br>
          ' . $amountWords . '
          <hr>
          Declaration
        </td>
        <td colspan="8" style="height: 45px;">
          Total FOB Value (INR):
            ' . $fob_inr . '
          <br>
          Total CIF Value (INR):
            ' . $fob_inr . '
          <br>
          Total Taxes (INR):
            0.00
          <br>
          Total Amount (INR):
            ' . $fob_inr . '
        </td>
      </tr>
      <tr>
        <td colspan="8" style="height: 30px;">
          Authorized Signatory
        </td>
      </tr>
    </table>
';

  $invoicePdf->writeHTML($invHtml, true, false, true, false, '');

  $style = array(
    'border' => 1,
    'vpadding' => 'auto',
    'hpadding' => 'auto',
    'fgcolor' => array(0, 0, 0),
    'bgcolor' => false, // transparent
    'module_width' => 1, // width of a single module in points
    'module_height' => 1 // height of a single module in points
  );

  // Content of QR code (can be invoice number, customer + invoice, etc.)
  $qrContent = "Invoice No: $invoice\nDate: $date\nCustomer: $customerName";

  // Print QR code at x=250, y=20 (adjust coordinates as needed)
  $invoicePdf->write2DBarcode($qrContent, 'QRCODE,H', 244, 49, 24, 24, $style, 'N');
}



// Save invoice
$safeInvNo   = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$invoiceNo);
$invoiceOut  = "files/invoice_{$baseName}.pdf";
$invoicePdf->Output(__DIR__ . '/' . $invoiceOut, 'F');

// === 2. PBE one table ===
class MyPDF extends TCPDF
{
  public $total_num = 0;
  public $total_FOB = 0;
  public $totalTaxes = 0;
  public $totalAmount = 0;
  
  // Page footer
  public function Footer()
  {
    // Position 25 mm from bottom
    $this->SetY(-15);

    // Draw a line (X1, Y, X2, Y)
    $this->Line($this->lMargin, $this->GetY(), $this->getPageWidth() - $this->rMargin, $this->GetY());

    // Move a little below the line for text
    $this->Ln(3);

    $this->SetFont('helvetica', '', 9);

    // Split footer into 2 columns
    $pageWidth = $this->getPageWidth() - $this->lMargin - $this->rMargin;
    $leftWidth  = $pageWidth * 0.75; // 2/3 of total width
    $rightWidth = $pageWidth * 0.25; // 1/3 of total width

    // Left block: totals
    $summary = '
      Total no. of Parcels: ' . number_format($this->total_num, 0) . ', Total no. of Invoices: ' . number_format($this->total_num, 0) . ', Total Value of FOB: INR ' . number_format($this->total_FOB, 2) . ', Total invoice value(CIF) in INR: ' . number_format($this->total_FOB, 2) . '';
    $this->MultiCell($leftWidth, 0, $summary, 0, 'L', false, 0);

    // Right block: doc info
    $info = "" . $this->getAliasNumPage() . "/" . $this->getAliasNbPages();
    $this->MultiCell($rightWidth, 0, $info, 0, 'R', false, 1);
  }
}

$pbePdf = new MyPDF('L', 'mm', 'A4');
$pbePdf->SetCreator('customer_system');
$pbePdf->SetAuthor(EXPORTER_NAME);
$pbePdf->SetTitle('Combined PBE');
$pbePdf->SetMargins(8, 8, 8);
$pbePdf->setPrintHeader(false);
$pbePdf->setCellHeightRatio(1.1);
$pbePdf->AddPage();
$pbePdf->SetFont('helvetica', '', 9);

$pbeHtml = '
<style>
  td {border:0.6px solid #000; padding: 0; margin: 0; font-family: Arial;}
  div {
    padding: 0px;
    margin: 0px;
  }
</style>

<div style="font-size: 16px; font-weight: bold; text-align: center;"> 
  FORM-II
</div>

<div style="font-size: 12px; text-align: center;"> 
  (see regulation 4)
</div>

<div style="font-size: 16px; font-weight: bold; text-align: center;"> 
  Postal Bill of Export - I (PBE - I)
</div>

<div style="font-size: 12px; text-align: center;"> 
  (For export of goods through E-Commerce)
  <br>(To be submitted in duplicate)
</div>

<br>
  
<br><table cellpadding="2">
  <tr>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 9%; font-size: 8px;"> 
      Bill of Export No. <br> & Date.
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 12%; font-size: 8px;"> 
      Foreign Post Office Code
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 12%; font-size: 8px;"> 
      Name of Exporter
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 16%; font-size: 8px;"> 
      Address of Exporter
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 8%; font-size: 8px;"> 
      IEC
    </td>
    <td rowspan="4" colspan="2" style="height: 20px; text-align: center; width: 7%; font-size: 8px;"> 
      State Code
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 11%; font-size: 8px;"> 
      GSTIN of as <br>applicable
    </td>
    <td rowspan="3" colspan="2" style="height: 20px; text-align: center; width: 10%; font-size: 8px;"> 
      AD code (if <br>applicable)
    </td>
    <td rowspan="1" colspan="2" style="height: 20px; text-align: center; width: 15%; font-size: 8px;"> 
        Details of Customs Broker
    </td>
  </tr>
  <tr>
    <td rowspan="1" colspan="1" style="height: 20px; text-align: center; width: 6.3%; font-size: 8px;"> 
        License <br>No
    </td>
    <td rowspan="1" colspan="1" style="height: 20px; text-align: center; width: 8.7%; font-size: 8px;"> 
      Name and Address
    </td>
  </tr>
</table>
<table cellpadding="2">
  <tr>
    <td style="height: 10px; text-align: center; width: 9%; font-size: 8px;">
    </td>
    <td style="height: 12px; text-align: center; width: 12%; font-size: 8px;"> 
      INDEL5
    </td>
    <td style="height: 12px; text-align: center; width: 12%; font-size: 8px;"> 
      SUN.B. INTERNATIONAL
    </td>
    <td style="height: 12px; text-align: center; width: 16%; font-size: 8px;"> 
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
    <td style="height: 12px; text-align: center; width: 11%; font-size: 8px;"> 
      07AAIPB4716E1Z5
    </td>
    <td style="height: 12px; text-align: center; width: 10%; font-size: 8px;"> 
      07AAIPB4716E1Z5
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
    <td style="height: 30px; font-size: 8px; width: 100%;">
      We hereby declare that the contents of this postal bill of export are true and correct in every respect
      <br>
      <br>
      (Signature of the Exporter/Authorise agent)
    </td>
  </tr>
  <tr>
    <td style="height: 30px; font-size: 8px; width: 100%;">
      Examination order and report
      <br>
      <div style="text-align: right;">
        Let Export Order: Signature of officer of Customs along with stamp and date:
      </div>
    </td>
  </tr>
  
</table>
<br>
<br>
<br><table style="width:100%" cellpadding="2">
  <thead>
  <tr>
    <td style="height: 26px; font-size: 10px; text-align: center; font-weight: bold">
      <br>
      Details of Parcel
    </td>
  </tr>
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
    <td rowspan="2" colspan="1" style="font-size: 8px; width: 4%; text-align: center;">CTH</td>
    <td rowspan="1" colspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">Quantity</td>
    <td rowspan="2" colspan="2" style="font-size: 8px; width: 8%; text-align: center;">Invoice Number and Date</td>
    <td rowspan="1" colspan="2" style="font-size: 8px; width: 7%; text-align: center;">Weight</td>
    <td rowspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">URL(Name)</td>
    <td rowspan="2" colspan="1" style="font-size: 8px; width: 9%; text-align: center;">Payment transaction ID</td>
    <td rowspan="2" colspan="1" style="font-size: 8px; width: 5%; text-align: center;">SKU No.</td>
    <td rowspan="2" colspan="1" style="font-size: 8px; width: 8%; text-align: center;">Postal tracking number</td>
  </tr>
  <tr>
  <td rowspan="1" style="font-size: 8px; width: 3%; text-align: center;">Unit</td>
    <td rowspan="1" style="font-size: 8px; width: 5%; text-align: center;">Number</td>
    <td style="font-size: 8px; width: 4%; text-align: center;">Gross</td>
    <td style="font-size: 8px; width: 3%; text-align: center;">Net</td>
  </tr>
  </thead>
  <tbody>
';

$num = 1;
foreach ($groups as $invoiceNo => $items) {
  $f = $items[0];
  $customerName = $f[$COLUMNS['cust_name']] ?? '';
  $desc = $f[$COLUMNS['desc']] ?? '';
  $addr1 = $f[$COLUMNS['addr1']] ?? '';
  $country = $f[$COLUMNS['country']] ?? '';
  $date = $f[$COLUMNS['date']] ?? '';
  $hsn = $f[$COLUMNS['hsn']] ?? '';
  $qty = $f[$COLUMNS['qty']] ?? '';
  $rate = $f[$COLUMNS['rate']] ?? '';
  $customerEmail = $f[$COLUMNS['url']] ?? '';
  $sku = $f[$COLUMNS['sku']] ?? '';
  $payment_transaction = $f[$COLUMNS['payment_transaction']] ?? '';
  $tracking = $f[$COLUMNS['tracking']] ?? '';
  $grossW = $f[$COLUMNS['gross']] ?? '';
  $netWt = $f[$COLUMNS['net']] ?? '';

  $amount = (float)$qty * (float)$rate;

  $pbeHtml .= '
    <tr>
      <td  style="width: 5%; font-size: 8px; text-align: center; width: 5%;">
        ' . $num++ . '
      </td>
      <td  style="font-size: 8px; text-align: center; width: 20%;">
        ' . $customerName . '
        <br>
        ' . $addr1 . '
      </td>
      <td style="font-size: 8px; width: 10%; text-align: center;">
        ' . $country . '
      </td>
      <td style="font-size: 8px; width: 8%; text-align: center;">
        ' . $desc . '
      </td>
      <td style="font-size: 8px; width: 4%; text-align: center;">
        ' . $hsn . '
      </td>
      <td style="font-size: 8px; width: 3%; text-align: center;">
        ' . $unit . '
      </td>
      <td style="font-size: 8px; width: 5%; text-align: center;">
        ' . $qty . '
      </td>
      <td style="font-size: 8px; width: 8%; text-align: center;">
        ' . $invoiceNo . '
        <br>
        ' . $date . '
      </td>
      <td style="font-size: 8px; width: 4%; text-align: center;">
        ' . $grossW . '
      </td>
      <td style="font-size: 8px; width: 3%; text-align: center;">
        ' . $netWt . '
      </td>
      <td  style="font-size: 8px; width: 8%; text-align: center;">
        ' . $customerEmail . '
      </td>
      <td  style="font-size: 8px; width: 9%; text-align: center;">
        ' . $payment_transaction . '
      </td>
      <td  style="font-size: 8px; width: 5%; text-align: center;">
        ' . $sku . '
      </td>
      <td style="font-size: 8px; width: 8%; text-align: center;">
        ' . $tracking . '
      </td>
    </tr>
  ';
}
$pbeHtml .=
  '
  </tbody>
</table>
<br>
<br>
<br>
<br>
<br>
<br>
<br><table cellpadding="2">
  <tr>
    <td rowspan="1" colspan="4" style="width: 25%; font-size: 8px; text-align: center">
      <br>
      <br>Assessable value under section 14 of the Customs Act
    </td>
    <td rowspan="1" colspan="4" style="width: 20%; font-size: 8px; text-align: center">Details of Tax invoice or commercial invoice <br>
    (whichever applicable)</td>
    <td rowspan="1" colspan="9" style="width: 40%; font-size: 8px; text-align: center">
      <br>
      <br>
      Details of duty/tax
    </td>
    <td rowspan="2" colspan="2" style="width: 15%; font-size: 8px; text-align: center">
      <br>
      <br>Total
    </td>
  </tr>
  <tr>
    <td rowspan="3" colspan="1" style="width: 7%; font-size: 8px; text-align: center">FOB</td>
    <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Currency</td>
    <td rowspan="3" colspan="1" style="width: 8%; font-size: 8px; text-align: center">Exchange Rate</td>
    <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Amount in <br>INR</td>
    <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">H.S. Code</td>
    <td rowspan="1" colspan="2" style="width: 10%; font-size: 8px; text-align: center">Invoice details</td>
    <td rowspan="3" colspan="1" style="width: 5%; font-size: 8px; text-align: center">Value</td>
    <td rowspan="1" colspan="4" style="width: 17%; font-size: 8px; text-align: center">Customs duties</td>
    <td rowspan="1" colspan="5" style="width: 23%; font-size: 8px; text-align: center">GST details</td>
  </tr>
  <tr>
    <td rowspan="2" colspan="1" style="width: 6%; font-size: 8px; text-align: center">Invoice no. & <br>date.</td>
    <td rowspan="2" colspan="1" style="width: 4%; font-size: 8px; text-align: center">Sl.No. of <br>item in <br>invoice<br></td>
    <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">Export duty</td>
    <td rowspan="1" colspan="2" style="width: 9%; font-size: 8px; text-align: center">Cess</td>
    <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">IGST (if applicable)</td>
    <td rowspan="1" colspan="2" style="width: 8%; font-size: 8px; text-align: center">Compensation cess (if <br> applicable)</td>
    <td rowspan="2" colspan="1" style="width: 7%; font-size: 8px; text-align: center">
      LUT/bond details <br>(if applicable)
    </td>
    <td rowspan="2" colspan="1" style="width: 8%; font-size: 8px; text-align: center">
      duties <br>
    </td>
    <td rowspan="2" colspan="1" style="width: 7%; font-size: 8px; text-align: center">
      cess 
    </td>
    </tr>
    <tr>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate</td>
      <td rowspan="1" colspan="1" style="width: 5%; font-size: 8px; text-align: center">amount</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">rate</td>
      <td rowspan="1" colspan="1" style="width: 4%; font-size: 8px; text-align: center">amount</td>
    </tr>
';

$total_num = 0;
$total_FOB = 0;
$total_CIF = 0;

foreach ($groups as $invoiceNo => $items) {
  $f = $items[0];
  $customerName = $f[$COLUMNS['cust_name']] ?? '';
  $desc = $f[$COLUMNS['desc']] ?? '';
  $addr1 = $f[$COLUMNS['addr1']] ?? '';
  $country = $f[$COLUMNS['country']] ?? '';
  $date = $f[$COLUMNS['date']] ?? '';
  $hsn = $f[$COLUMNS['hsn']] ?? '';
  $qty = $f[$COLUMNS['qty']] ?? '';
  $rate = $f[$COLUMNS['rate']] ?? '';
  $currency = $f[$COLUMNS['currency']] ?? '';
  $customerEmail = $f[$COLUMNS['url']] ?? '';
  $sku = $f[$COLUMNS['sku']] ?? '';
  $payment_transaction = $f[$COLUMNS['payment_transaction']] ?? '';
  $tracking = $f[$COLUMNS['tracking']] ?? '';
  $grossW = $f[$COLUMNS['gross']] ?? '';
  $netWt = $f[$COLUMNS['net']] ?? '';
  $exchage_rate = 82.20;

  $amount = (float)$qty * (float)$rate;

  $pbeHtml .= '
    <tr>
      <td style="width: 7%; font-size: 8px; text-align: center">
        ' . $rate . '
      </td>
      <td style="width: 5%; font-size: 8px; text-align: center">
        ' . $currency . '
      </td>
      <td style="width: 8%; font-size: 8px; text-align: center">
        ' . $exchage_rate . '
      </td>
      <td style="width: 5%; font-size: 8px; text-align: center">
        ' . $exchage_rate * $rate . '
      </td>
      <td style="width: 5%; font-size: 8px; text-align: center">
        ' . $hsn . '
      </td>
      <td style="width: 6%; font-size: 8px; text-align: center">
        ' . $invoiceNo . '
        <br>' . $invoiceDate . '
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        ' . $exchage_rate * $rate . '
      </td>
      <td style="width: 5%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 5%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 4%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 7%; font-size: 8px; text-align: center">
        AD090423002505G
      </td>
      <td style="width: 8%; font-size: 8px; text-align: center">
        0.00
      </td>
      <td style="width: 7%; font-size: 8px; text-align: center">
        0.00
      </td>
    </tr>
  ';

  $total_num++;
  $total_FOB += $exchage_rate * $rate;
}

$pbeHtml .=  '</table>';

$pbePdf->total_num = $total_num;
$pbePdf->total_FOB = $total_FOB;

$pbePdf->writeHTML($pbeHtml, true, true, true, false, '');
$pbeOut = "files/pbe_{$baseName}.pdf";
$pbePdf->Output(__DIR__ . '/' . $pbeOut, 'F');

// remember for success page
$generated[] = ['invoice' => $invoiceOut, 'pbe' => $pbeOut, 'no' => $baseName];

// Save to database
// $file_name  = $fileName ?? '';
// $customerEmail = $f['Email'] ?? '';
// $invoiceNo     = $f[$COLUMNS['invoice']] ?? '';

$stmt = $conn->prepare("
  INSERT INTO records (excel_file, invoice_pdf, pbe_pdf, status, file_name)
  VALUES (?, ?, ?, 'approved', ?)
");

$stmt->bind_param("ssss", $fileName, $invoiceOut, $pbeOut, $baseName);
$stmt->execute();
$stmt->close();
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
                  <th>File ID</th>
                  <th>Invoice PDF</th>
                  <th>PBE PDF</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($generated as $g): ?>
                  <tr>
                    <td><span class="badge bg-primary"><?php echo htmlspecialchars($g['no']); ?></span></td>
                    <td>
                      <a class="btn btn-outline-success btn-sm" target="_blank"
                        href="generate_pdf.php?download=<?php echo basename($g['invoice']); ?>">
                        Download Invoice
                      </a>
                    </td>
                    <td>
                      <a class="btn btn-outline-secondary btn-sm" target="_blank"
                        href="generate_pdf.php?download=<?php echo basename($g['pbe']); ?>">
                        Download PBE
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <a href="records.php" class="btn btn-primary">
          View Records
        </a>
        <a href="upload.php" class="btn btn-outline-primary mx-3">
          ← Back to Upload
        </a>
      </div>
    </div>
  </div>
</body>

</html>