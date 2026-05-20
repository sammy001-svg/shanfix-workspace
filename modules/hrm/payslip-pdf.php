<?php
/**
 * HRM Payslip PDF Download
 * GET: payslip-pdf.php?id=PAYROLL_ID
 */
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';

requireLogin();
$user  = currentUser();
$orgId = (int)$user['org_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Payslip not found'); }

// Fetch payroll record (scoped to org)
$stmt = $pdo->prepare("
    SELECT p.*, e.employee_no, e.first_name, e.last_name, e.position, e.id_number,
           e.bank_name, e.bank_account, d.name AS dept_name
    FROM hrm_payroll p
    JOIN hrm_employees e ON p.employee_id = e.id
    LEFT JOIN hrm_departments d ON e.department_id = d.id
    WHERE p.id = ? AND p.org_id = ?
");
$stmt->execute([$id, $orgId]);
$payroll = $stmt->fetch();

if (!$payroll) { http_response_code(404); exit('Payslip not found'); }

// Fetch org info
$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch();

$employee = [
    'employee_no' => $payroll['employee_no'],
    'name'        => $payroll['first_name'] . ' ' . $payroll['last_name'],
    'position'    => $payroll['position'],
    'id_number'   => $payroll['id_number'],
    'department'  => $payroll['dept_name'],
    'bank_name'   => $payroll['bank_name'],
    'bank_account'=> $payroll['bank_account'],
];

generatePayslipPDF($payroll, $employee, $org ?: []);
