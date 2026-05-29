<?php
/**
 * OrbitDesk API — CRM Contacts Endpoint
 *
 * GET    /api/v1/contacts.php            List contacts (paginated)
 * GET    /api/v1/contacts.php?id=X       Single contact
 * POST   /api/v1/contacts.php            Create contact
 * POST   /api/v1/contacts.php (_method=PUT)    Update contact
 * POST   /api/v1/contacts.php (_method=DELETE) Delete contact
 *
 * Query params (GET list):
 *   ?type=lead|contact|customer|partner
 *   ?search=keyword
 *   ?page=1&per_page=20
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/_auth_helper.php';

apiSetHeaders();

$auth   = apiRequireAuth();
$orgId  = $auth['org_id'];

$method = strtoupper($_SERVER['REQUEST_METHOD']);

// Support method override via _method field (POST body or query string)
$body  = (string)file_get_contents('php://input');
$input = json_decode($body, true) ?? [];
if (empty($input) && $method === 'POST') {
    parse_str($body, $input);
}

$override = strtoupper(trim($input['_method'] ?? $_GET['_method'] ?? ''));
if ($method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
    $method = $override;
}

// ── Helper: validate contact fields ──────────────────────────────────────────
function contactFields(array $input, bool $require = true): array
{
    $allowed = ['lead', 'contact', 'customer', 'partner'];
    $type    = in_array($input['type'] ?? '', $allowed) ? $input['type'] : 'contact';

    $fields = [
        'first_name'   => trim($input['first_name']   ?? ''),
        'last_name'    => trim($input['last_name']    ?? ''),
        'email'        => strtolower(trim($input['email'] ?? '')),
        'phone'        => trim($input['phone']        ?? ''),
        'type'         => $type,
        'company'      => trim($input['company_name'] ?? $input['company'] ?? ''),
        'position'     => trim($input['position']     ?? ''),
        'notes'        => trim($input['notes']        ?? ''),
        'status'       => 'active',
    ];

    if ($require && $fields['first_name'] === '' && $fields['last_name'] === '') {
        apiError('first_name or last_name is required.', 422);
    }

    if ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        apiError('Invalid email address.', 422);
    }

    return $fields;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET — List or single
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Single record
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM crm_contacts WHERE id = ? AND org_id = ? LIMIT 1"
            );
            $stmt->execute([$id, $orgId]);
            $contact = $stmt->fetch();
        } catch (Throwable $e) {
            apiError('Database error.', 500);
        }

        if (!$contact) {
            apiError('Contact not found.', 404);
        }

        apiJson(['success' => true, 'data' => $contact]);
    }

    // List with optional filters
    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 20)));
    $type    = trim($_GET['type']   ?? '');
    $search  = trim($_GET['search'] ?? '');

    $where  = ['c.org_id = :org_id'];
    $params = [':org_id' => $orgId];

    if ($type !== '') {
        $where[]          = 'c.type = :type';
        $params[':type']  = $type;
    }

    if ($search !== '') {
        $where[]             = "(c.first_name LIKE :s OR c.last_name LIKE :s
                                 OR c.email LIKE :s OR c.phone LIKE :s
                                 OR c.company LIKE :s)";
        $params[':s'] = '%' . $search . '%';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM crm_contacts c $whereClause";
    $dataSql  = "SELECT c.* FROM crm_contacts c $whereClause ORDER BY c.id DESC";

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
        $contacts = $dStmt->fetchAll();
    } catch (Throwable $e) {
        apiError('Database error.', 500);
    }

    apiJson([
        'success'  => true,
        'data'     => $contacts,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// POST — Create contact
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $f = contactFields($input);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO crm_contacts
                (org_id, first_name, last_name, email, phone, type, company, position, notes, status)
             VALUES
                (:org_id, :first_name, :last_name, :email, :phone, :type, :company, :position, :notes, :status)"
        );
        $stmt->execute(array_merge([':org_id' => $orgId], array_combine(
            array_map(fn($k) => ":$k", array_keys($f)),
            array_values($f)
        )));
        $newId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        apiError('Failed to create contact.', 500);
    }

    // Return created record
    $stmt = $pdo->prepare("SELECT * FROM crm_contacts WHERE id = ? LIMIT 1");
    $stmt->execute([$newId]);
    $created = $stmt->fetch();

    apiJson(['success' => true, 'data' => $created], 201);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PUT/PATCH — Update contact
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        apiError('id is required for update.', 422);
    }

    // Verify ownership
    try {
        $check = $pdo->prepare("SELECT id FROM crm_contacts WHERE id = ? AND org_id = ? LIMIT 1");
        $check->execute([$id, $orgId]);
        if (!$check->fetch()) {
            apiError('Contact not found.', 404);
        }
    } catch (Throwable $e) {
        apiError('Database error.', 500);
    }

    $f = contactFields($input, false);
    // Remove empty strings from update (don't blank out existing values unless intended)
    $sets   = [];
    $params = [':id' => $id, ':org_id' => $orgId];
    foreach ($f as $col => $val) {
        if ($val !== '') {
            $sets[]       = "$col = :$col";
            $params[":$col"] = $val;
        }
    }

    if (empty($sets)) {
        apiError('No fields to update.', 422);
    }

    try {
        $sql = "UPDATE crm_contacts SET " . implode(', ', $sets) . " WHERE id = :id AND org_id = :org_id";
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        apiError('Failed to update contact.', 500);
    }

    $stmt = $pdo->prepare("SELECT * FROM crm_contacts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    apiJson(['success' => true, 'data' => $stmt->fetch()]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DELETE
// ═══════════════════════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        apiError('id is required for delete.', 422);
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM crm_contacts WHERE id = ? AND org_id = ? LIMIT 1");
        $stmt->execute([$id, $orgId]);
        if ($stmt->rowCount() === 0) {
            apiError('Contact not found.', 404);
        }
    } catch (Throwable $e) {
        apiError('Failed to delete contact.', 500);
    }

    apiJson(['success' => true, 'message' => 'Contact deleted.']);
}

apiError('Method not allowed.', 405);
