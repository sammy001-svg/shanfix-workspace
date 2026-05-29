<?php
/**
 * OrbitDesk API — Invoices Endpoint
 *
 * GET    /api/v1/invoices.php              List invoices (paginated)
 * GET    /api/v1/invoices.php?id=X         Single invoice
 * POST   /api/v1/invoices.php              Create invoice
 * POST   /api/v1/invoices.php (_method=PATCH, status=paid) Mark as paid
 *
 * Query params (GET list):
 *   ?status=draft|sent|paid|overdue|cancelled
 *   ?page=1&per_page=20
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth_helper.php';

apiSetHeaders();

$auth   = apiRequireAuth();
$orgId  = $auth['org_id'];

$method = strtoupper($_SERVER['REQUEST_METHOD']);

$body  = (string)file_get_contents('php://input');
$input = json_decode($body, true) ?? [];
if (empty($input) && $method === 'POST') {
    parse_str($body, $input);
}

$override = strtoupper(trim($input['_method'] ?? $_GET['_method'] ?? ''));
if ($method === 'POST' && in_array($override, ['PATCH', 'PUT', 'DELETE'], true)) {
    $method = $override;
}

// ── Generate invoice number ───────────────────────────────────────────────────
function generateInvoiceNumber(PDO $pdo, int $orgId): string
{
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE org_id = ?");
        $stmt->execute([$orgId]);
        $count = (int)$stmt->fetchColumn() + 1;
    } catch (Throwable $e) {
        $count = rand(1000, 9999);
    }
    return 'INV-' . date('Ym') . '-' . str_pad((string)$count, 5, '0', STR_PAD_LEFT);
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET — List or single
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Single invoice
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND org_id = ? LIMIT 1");
            $stmt->execute([$id, $orgId]);
            $invoice = $stmt->fetch();
        } catch (Throwable $e) {
            apiError('Database error.', 500);
        }

        if (!$invoice) {
            apiError('Invoice not found.', 404);
        }

        apiJson(['success' => true, 'data' => $invoice]);
    }

    // List
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 20)));
    $status  = trim($_GET['status'] ?? '');

    $where  = ['org_id = :org_id'];
    $params = [':org_id' => $orgId];

    $validStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
    if ($status !== '' && in_array($status, $validStatuses, true)) {
        $where[]          = 'status = :status';
        $params[':status'] = $status;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);
    $countSql    = "SELECT COUNT(*) FROM invoices $whereClause";
    $dataSql     = "SELECT * FROM invoices $whereClause ORDER BY id DESC";

    try {
        $cStmt = $pdo->prepare($countSql);
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $dStmt  = $pdo->prepare("$dataSql LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $dStmt->bindValue($k, $v);
        }
        $dStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $dStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $dStmt->execute();
        $invoices = $dStmt->fetchAll();
    } catch (Throwable $e) {
        apiError('Database error.', 500);
    }

    apiJson([
        'success'  => true,
        'data'     => $invoices,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST — Create invoice
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $amount  = (float)($input['amount']   ?? 0);
    $tax     = (float)($input['tax']      ?? 0);
    $total   = (float)($input['total']    ?? ($amount + $tax));
    $notes   = trim($input['notes']       ?? '');
    $dueDate = trim($input['due_date']    ?? date('Y-m-d', strtotime('+30 days')));
    $status  = trim($input['status']      ?? 'draft');

    if ($amount <= 0) {
        apiError('amount must be greater than zero.', 422);
    }

    $validStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
    if (!in_array($status, $validStatuses, true)) {
        $status = 'draft';
    }

    // Validate due_date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
    }

    $invoiceNumber = generateInvoiceNumber($pdo, $orgId);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (org_id, invoice_number, amount, tax, total, notes, due_date, status)
             VALUES
                (:org_id, :invoice_number, :amount, :tax, :total, :notes, :due_date, :status)"
        );
        $stmt->execute([
            ':org_id'         => $orgId,
            ':invoice_number' => $invoiceNumber,
            ':amount'         => $amount,
            ':tax'            => $tax,
            ':total'          => $total,
            ':notes'          => $notes,
            ':due_date'       => $dueDate,
            ':status'         => $status,
        ]);
        $newId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        apiError('Failed to create invoice.', 500);
    }

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$newId]);
    apiJson(['success' => true, 'data' => $stmt->fetch()], 201);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PATCH — Mark as paid (or update status)
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'PATCH' || $method === 'PUT') {
    $id     = (int)($input['id'] ?? $_GET['id'] ?? 0);
    $status = strtolower(trim($input['status'] ?? ''));

    if ($id <= 0) {
        apiError('id is required.', 422);
    }

    // Verify ownership
    try {
        $check = $pdo->prepare("SELECT id, status FROM invoices WHERE id = ? AND org_id = ? LIMIT 1");
        $check->execute([$id, $orgId]);
        $existing = $check->fetch();
    } catch (Throwable $e) {
        apiError('Database error.', 500);
    }

    if (!$existing) {
        apiError('Invoice not found.', 404);
    }

    $validStatuses = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
    if ($status !== '' && !in_array($status, $validStatuses, true)) {
        apiError('Invalid status value.', 422);
    }

    // Build update
    $sets   = [];
    $params = [':id' => $id, ':org_id' => $orgId];

    if ($status !== '') {
        $sets[]           = 'status = :status';
        $params[':status'] = $status;
        if ($status === 'paid') {
            $sets[]          = 'paid_at = NOW()';
        }
    }

    // Allow updating other fields too
    foreach (['amount', 'tax', 'total', 'notes', 'due_date'] as $field) {
        if (isset($input[$field]) && $input[$field] !== '') {
            $sets[]           = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($sets)) {
        apiError('No fields to update.', 422);
    }

    try {
        $sql = "UPDATE invoices SET " . implode(', ', $sets) . " WHERE id = :id AND org_id = :org_id";
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        apiError('Failed to update invoice.', 500);
    }

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    apiJson(['success' => true, 'data' => $stmt->fetch()]);
}

apiError('Method not allowed.', 405);
