<?php
/**
 * OrbitDesk Workspace — PDF Generator
 * Uses FPDF (place fpdf.php in /vendor/fpdf/fpdf.php)
 * Download: http://www.fpdf.org/en/download.php
 * No Composer needed — single file library.
 */

// Auto-load FPDF if available
$fpdfPath = __DIR__ . '/../vendor/fpdf/fpdf.php';
if (file_exists($fpdfPath)) {
    require_once $fpdfPath;
}

class OrbitDeskPDF
{
    private bool $fpdfAvailable;
    private string $brandNavy  = '#0B2D4E';
    private string $brandGreen = '#1A8A4E';

    public function __construct()
    {
        $this->fpdfAvailable = class_exists('FPDF');
    }

    /** Generate Invoice PDF */
    public function invoice(array $invoice, array $org, array $items = []): void
    {
        if (!$this->fpdfAvailable) {
            $this->htmlInvoiceFallback($invoice, $org, $items);
            return;
        }

        /** @var FPDF $pdf */
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(true, 20);

        // Header bar
        $pdf->SetFillColor(11, 45, 78);
        $pdf->Rect(0, 0, 210, 32, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetXY(15, 8);
        $pdf->Cell(0, 10, APP_NAME, 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(15, 18);
        $pdf->Cell(0, 6, APP_TAGLINE, 0, 1);

        // Invoice title (right side)
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(130, 8);
        $pdf->Cell(70, 10, 'INVOICE', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetXY(130, 18);
        $pdf->Cell(70, 6, $invoice['invoice_number'] ?? '', 0, 0, 'R');

        $pdf->SetY(40);
        $pdf->SetTextColor(50, 50, 50);

        // Billed To section
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(26, 138, 78);
        $pdf->Cell(0, 6, 'BILLED TO', 0, 1);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, $org['name'] ?? '', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(0, 5, $org['email'] ?? '', 0, 1);
        $pdf->Cell(0, 5, $org['phone'] ?? '', 0, 1);
        $pdf->Cell(0, 5, $org['city'] ?? '', 0, 1);

        // Invoice details (right)
        $detailY = 40;
        $detailX = 130;
        $issueDateStr = !empty($invoice['issue_date'])
            ? date('d M Y', strtotime($invoice['issue_date']))
            : date('d M Y', strtotime($invoice['created_at'] ?? 'now'));
        $dueDateStr = !empty($invoice['due_date'])
            ? date('d M Y', strtotime($invoice['due_date']))
            : '—';
        $detailRows = [
            ['Invoice #:',  $invoice['invoice_number']    ?? ''],
            ['Issue Date:', $issueDateStr],
            ['Due Date:',   $dueDateStr],
            ['Status:',     strtoupper($invoice['status'] ?? 'SENT')],
        ];
        foreach ($detailRows as $row) {
            $pdf->SetXY($detailX, $detailY);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(30, 5, $row[0], 0, 0);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->Cell(50, 5, $row[1], 0, 1);
            $detailY += 5;
        }

        $pdf->SetY(90);
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, 90, 195, 90);
        $pdf->SetY(95);

        // Items table header
        $pdf->SetFillColor(11, 45, 78);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(85, 8, 'Description', 1, 0, 'L', true);
        $pdf->Cell(25, 8, 'Qty', 1, 0, 'C', true);
        $pdf->Cell(35, 8, 'Unit Price', 1, 0, 'R', true);
        $pdf->Cell(35, 8, 'Amount', 1, 1, 'R', true);

        // Items
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('Arial', '', 9);
        $fill = false;
        if (!empty($items)) {
            foreach ($items as $item) {
                $pdf->SetFillColor(248, 250, 252);
                $pdf->Cell(85, 7, $item['description'] ?? 'Subscription', 1, 0, 'L', $fill);
                $pdf->Cell(25, 7, $item['qty'] ?? '1', 1, 0, 'C', $fill);
                $pdf->Cell(35, 7, 'KES ' . number_format($item['price'] ?? 0, 2), 1, 0, 'R', $fill);
                $pdf->Cell(35, 7, 'KES ' . number_format(($item['qty']??1)*($item['price']??0), 2), 1, 1, 'R', $fill);
                $fill = !$fill;
            }
        } else {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->Cell(85, 7, 'Subscription Services — ' . date('F Y'), 1, 0, 'L', true);
            $pdf->Cell(25, 7, '1', 1, 0, 'C', true);
            $pdf->Cell(35, 7, 'KES ' . number_format($invoice['amount'] ?? 0, 2), 1, 0, 'R', true);
            $pdf->Cell(35, 7, 'KES ' . number_format($invoice['amount'] ?? 0, 2), 1, 1, 'R', true);
        }

        // Totals
        $totalsX = 130;
        $totalsY = $pdf->GetY() + 5;
        $pdf->SetXY($totalsX, $totalsY);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(30, 6, 'Subtotal:', 0, 0, 'R');
        $pdf->Cell(35, 6, 'KES ' . number_format($invoice['amount'] ?? 0, 2), 0, 1, 'R');
        $pdf->SetX($totalsX);
        $pdf->Cell(30, 6, 'VAT (16%):', 0, 0, 'R');
        $pdf->Cell(35, 6, 'KES ' . number_format($invoice['tax'] ?? 0, 2), 0, 1, 'R');

        $pdf->SetX($totalsX);
        $pdf->SetFillColor(26, 138, 78);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(65, 9, '  TOTAL DUE: KES ' . number_format($invoice['total'] ?? 0, 2), 0, 1, 'R', true);
        $pdf->SetTextColor(50, 50, 50);

        // Payment info (from system settings)
        $pmtCfg = getSettings([
            'mpesa_paybill', 'mpesa_account_ref',
            'bank_name', 'bank_account', 'bank_branch',
            'support_email',
        ]);
        $pmtLines = [];
        if (!empty($pmtCfg['mpesa_paybill'])) {
            $acctRef = !empty($pmtCfg['mpesa_account_ref']) ? $pmtCfg['mpesa_account_ref'] : ($invoice['invoice_number'] ?? '');
            $pmtLines[] = 'M-Pesa Paybill: ' . $pmtCfg['mpesa_paybill'] . ', Account: ' . $acctRef;
        }
        if (!empty($pmtCfg['bank_name'])) {
            $bankLine = 'Bank: ' . $pmtCfg['bank_name'];
            if (!empty($pmtCfg['bank_account'])) $bankLine .= ', A/C: ' . $pmtCfg['bank_account'];
            if (!empty($pmtCfg['bank_branch']))  $bankLine .= ', Branch: ' . $pmtCfg['bank_branch'];
            $pmtLines[] = $bankLine;
        }
        if (!empty($pmtCfg['support_email'])) {
            $pmtLines[] = 'Queries: ' . $pmtCfg['support_email'];
        }
        if (!empty($pmtLines) && ($invoice['status'] ?? '') !== 'paid') {
            $pdf->SetY($pdf->GetY() + 10);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(26, 138, 78);
            $pdf->Cell(0, 6, 'PAYMENT INFORMATION', 0, 1);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->MultiCell(0, 5, implode("\n", $pmtLines));
        }

        if (!empty($invoice['notes'])) {
            $pdf->SetY($pdf->GetY() + 3);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(0, 6, 'NOTES', 0, 1);
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 5, $invoice['notes']);
        }

        // Footer
        $pdf->SetY(-25);
        $pdf->SetFillColor(11, 45, 78);
        $pdf->Rect(0, $pdf->GetY(), 210, 25, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(0, 8, APP_NAME . ' | ' . APP_URL . ' | Thank you for your business!', 0, 0, 'C');

        $pdf->Output('D', 'Invoice-' . ($invoice['invoice_number'] ?? 'download') . '.pdf');
        exit;
    }

    /** Generate Payslip PDF */
    public function payslip(array $payroll, array $employee, array $org): void
    {
        if (!$this->fpdfAvailable) {
            $this->htmlFallback('payslip');
            return;
        }

        /** @var FPDF $pdf */
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();

        // Header
        $pdf->SetFillColor(11, 45, 78);
        $pdf->Rect(0, 0, 210, 28, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetXY(15, 7);
        $pdf->Cell(100, 8, APP_NAME, 0, 0);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(80, 8, 'PAYSLIP', 0, 1, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(15, 16);
        $pdf->Cell(100, 5, $org['name'] ?? '', 0, 0);
        $pdf->Cell(80, 5, 'Period: ' . ($payroll['period'] ?? ''), 0, 1, 'R');

        $pdf->SetY(35);
        $pdf->SetTextColor(50, 50, 50);

        // Employee info
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(95, 6, 'Employee Information', 0, 0);
        $pdf->Cell(95, 6, 'Employment Details', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pairs = [
            ['Name', ($employee['first_name']??'') . ' ' . ($employee['last_name']??''), 'Emp. No.', $employee['employee_no']??''],
            ['ID Number', $employee['id_number']??'', 'Department', $employee['dept_name']??''],
            ['Position', $employee['position']??'', 'Payment Date', $payroll['payment_date']??''],
        ];
        foreach ($pairs as $p) {
            $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(25,5,$p[0].':',0,0); $pdf->SetFont('Arial','',8); $pdf->Cell(70,5,$p[1],0,0);
            $pdf->SetFont('Arial', 'B', 8); $pdf->Cell(25,5,$p[2].':',0,0); $pdf->SetFont('Arial','',8); $pdf->Cell(70,5,$p[3],0,1);
        }

        $pdf->SetY($pdf->GetY() + 5);

        // Earnings table
        $pdf->SetFillColor(11, 45, 78);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(95, 7, 'EARNINGS', 1, 0, 'C', true);
        $pdf->Cell(95, 7, 'DEDUCTIONS', 1, 1, 'C', true);

        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('Arial', '', 9);

        $earnings   = [['Basic Salary', $payroll['basic_salary']??0],['Allowances', $payroll['allowances']??0],['Overtime', $payroll['overtime']??0]];
        $deductions = [['PAYE Tax', $payroll['paye']??0],['NHIF', $payroll['nhif']??0],['NSSF', $payroll['nssf']??0],['Other', $payroll['other_deductions']??0]];

        $rows = max(count($earnings), count($deductions));
        for ($i = 0; $i < $rows; $i++) {
            $e  = $earnings[$i]   ?? ['—', ''];
            $d  = $deductions[$i] ?? ['—', ''];
            $eAmt = $e[1] ? 'KES ' . number_format((float)$e[1], 2) : '';
            $dAmt = $d[1] ? 'KES ' . number_format((float)$d[1], 2) : '';
            $fill = ($i % 2 === 0);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->Cell(65, 6, $e[0], 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, $eAmt, 1, 0, 'R', $fill);
            $pdf->Cell(65, 6, $d[0], 1, 0, 'L', $fill);
            $pdf->Cell(30, 6, $dAmt, 1, 1, 'R', $fill);
        }

        // Totals
        $pdf->SetFillColor(240, 249, 244);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(65, 8, 'Gross Salary', 1, 0, 'L', true);
        $pdf->Cell(30, 8, 'KES ' . number_format($payroll['gross_salary']??0, 2), 1, 0, 'R', true);
        $pdf->Cell(65, 8, 'Total Deductions', 1, 0, 'L', true);
        $pdf->Cell(30, 8, 'KES ' . number_format($payroll['total_deductions']??0, 2), 1, 1, 'R', true);

        // Net pay
        $pdf->SetY($pdf->GetY() + 3);
        $pdf->SetFillColor(26, 138, 78);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->Cell(0, 10, '  NET PAY: KES ' . number_format($payroll['net_salary']??0, 2), 0, 1, 'R', true);
        $pdf->SetTextColor(50, 50, 50);

        // Signature lines
        $pdf->SetY($pdf->GetY() + 15);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(95, 5, '____________________', 0, 0, 'C');
        $pdf->Cell(95, 5, '____________________', 0, 1, 'C');
        $pdf->Cell(95, 5, 'Employee Signature', 0, 0, 'C');
        $pdf->Cell(95, 5, 'Authorized Signatory', 0, 1, 'C');

        // Footer
        $pdf->SetY(-20);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(0, 5, 'This is a computer-generated payslip and does not require a physical signature. Generated by ' . APP_NAME, 0, 0, 'C');

        $pdf->Output('D', 'Payslip-' . ($employee['employee_no'] ?? 'EMP') . '-' . ($payroll['period'] ?? date('Ym')) . '.pdf');
        exit;
    }

    /** Generate a generic module report PDF */
    public function report(
        string $title,
        string $subtitle,
        array  $summary,
        array  $cols,
        array  $rows,
        string $filename = 'report.pdf',
        array  $color    = [11, 45, 78]
    ): void {
        if (!$this->fpdfAvailable) {
            $this->htmlFallback('report');
            return;
        }

        [$cr, $cg, $cb] = $color;

        /** @var FPDF $pdf */
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(true, 18);

        // ── Header bar ─────────────────────────────────────────
        $pdf->SetFillColor($cr, $cg, $cb);
        $pdf->Rect(0, 0, 210, 28, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetXY(12, 6);
        $pdf->Cell(120, 9, $title, 0, 0);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(12, 16);
        $pdf->Cell(186, 5, APP_NAME . '  |  ' . $subtitle, 0, 1, 'L');

        // Page number (top-right of header)
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY(150, 10);
        $pdf->Cell(48, 5, 'Generated: ' . date('d M Y, H:i'), 0, 0, 'R');

        $pdf->SetY(34);
        $pdf->SetTextColor(50, 50, 50);

        // ── Summary stats row ──────────────────────────────────
        if (!empty($summary)) {
            $pdf->SetFont('Arial', '', 8);
            $statW = 180.0 / count($summary);
            $pdf->SetX(12);
            foreach ($summary as $stat) {
                $pdf->SetFillColor($cr, $cg, $cb);
                $pdf->SetDrawColor($cr, $cg, $cb);
                $pdf->SetTextColor($cr, $cg, $cb);
                $pdf->SetFont('Arial', 'B', 13);
                $pdf->Cell($statW, 8, $stat['value'], 0, 0, 'C');
            }
            $pdf->Ln(8);
            $pdf->SetX(12);
            foreach ($summary as $stat) {
                $pdf->SetFont('Arial', '', 7);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell($statW, 5, strtoupper($stat['label']), 0, 0, 'C');
            }
            $pdf->Ln(8);
            // Thin separator
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
            $pdf->Ln(4);
        }

        // ── Table header ────────────────────────────────────────
        $pdf->SetFillColor($cr, $cg, $cb);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetX(12);
        foreach ($cols as $col) {
            $pdf->Cell($col['width'], 7, $col['label'], 0, 0, $col['align'] ?? 'L', true);
        }
        $pdf->Ln(7);

        // ── Table rows ──────────────────────────────────────────
        $pdf->SetFont('Arial', '', 8);
        $alt = false;
        foreach ($rows as $row) {
            // Auto-break handled by FPDF; set x each row
            $h = 6;
            if ($pdf->GetY() + $h > 279) {
                $pdf->AddPage();
                // Repeat header on new page
                $pdf->SetFillColor($cr, $cg, $cb);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetX(12);
                foreach ($cols as $col) {
                    $pdf->Cell($col['width'], 7, $col['label'], 0, 0, $col['align'] ?? 'L', true);
                }
                $pdf->Ln(7);
                $pdf->SetFont('Arial', '', 8);
            }

            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->SetX(12);
            foreach ($cols as $ci => $col) {
                $pdf->Cell($col['width'], $h, (string)($row[$ci] ?? ''), 0, 0, $col['align'] ?? 'L', $alt);
            }
            $pdf->Ln($h);
            $alt = !$alt;

            // Row separator line
            $pdf->SetDrawColor(230, 230, 230);
            $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
        }

        if (empty($rows)) {
            $pdf->SetX(12);
            $pdf->SetTextColor(160, 160, 160);
            $pdf->Cell(186, 8, 'No data available for this report.', 0, 1, 'C');
        }

        // ── Footer ──────────────────────────────────────────────
        $pdf->SetY(-14);
        $pdf->SetFillColor($cr, $cg, $cb);
        $pdf->Rect(0, $pdf->GetY(), 210, 14, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', '', 7);
        $pdf->Cell(0, 8, APP_NAME . ' | ' . APP_URL . ' | Confidential', 0, 0, 'C');

        $pdf->Output('D', $filename);
        exit;
    }

    /** Invoice HTML fallback — uses the shared HTML renderer (no FPDF needed) */
    private function htmlInvoiceFallback(array $invoice, array $org, array $items): void
    {
        $cfg = function_exists('getSettings') ? getSettings([
            'invoice_tax_rate', 'invoice_footer', 'invoice_notes',
            'mpesa_paybill', 'mpesa_shortcode', 'mpesa_account_ref',
            'bank_name', 'bank_account', 'bank_branch',
            'company_address', 'support_email',
        ]) : [];
        $invoiceBackUrl   = APP_URL . '/client/billing.php';
        $invoiceAdminMode = false;
        require __DIR__ . '/invoice-html.php';
    }

    /** HTML fallback for payslip/report when FPDF is not installed */
    private function htmlFallback(string $type): void
    {
        header('Content-Type: text/html');
        echo '<div style="font-family:Arial;max-width:700px;margin:40px auto;padding:24px;border:1px solid #e2e8f0;border-radius:8px">
            <div style="background:#0B2D4E;color:white;padding:16px 20px;border-radius:6px;margin-bottom:16px">
              <strong>' . APP_NAME . ' — ' . strtoupper($type) . '</strong>
            </div>
            <p style="color:#e74c3c;margin-bottom:8px">&#9888; FPDF library not installed.</p>
            <p style="color:#475569;font-size:.9rem">
              Download <a href="http://www.fpdf.org/en/download.php">fpdf.php</a>
              and place it at <code>/vendor/fpdf/fpdf.php</code>.
            </p>
        </div>';
        exit;
    }
}

// ── Global PDF helper ────────────────────────────────────────────
function generateInvoicePDF(array $invoice, array $org, array $items = []): void
{
    new OrbitDeskPDF()->invoice($invoice, $org, $items);
}

function generatePayslipPDF(array $payroll, array $employee, array $org): void
{
    new OrbitDeskPDF()->payslip($payroll, $employee, $org);
}

/**
 * Generic module report PDF.
 *
 * @param string $title     Page title, e.g. "Car Yard — Sales Report"
 * @param string $subtitle  Sub-line, e.g. "Generated 19 May 2026"
 * @param array  $summary   [['label'=>'Total Sales','value'=>'KES 250,000'], ...]
 * @param array  $cols      [['label'=>'Date','width'=>30,'align'=>'L'], ...]
 * @param array  $rows      Array of row-arrays matching $cols order
 * @param string $filename  Downloaded filename
 * @param array  $color     [R,G,B] brand accent colour, defaults to navy
 */
function generateModuleReportPDF(
    string $title,
    string $subtitle,
    array  $summary,
    array  $cols,
    array  $rows,
    string $filename  = 'report.pdf',
    array  $color     = [11, 45, 78]
): void {
    new OrbitDeskPDF()->report($title, $subtitle, $summary, $cols, $rows, $filename, $color);
}
