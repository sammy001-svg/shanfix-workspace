<?php
/**
 * modules/school/import-export.php
 * Bulk CSV import and export for Students, Parents, Teachers, Subjects, Library.
 *
 * Download actions (export/template) are handled before any HTML output.
 */
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

$pageTitle  = 'Import / Export';
$allEntities = ['students','parents','teachers','subjects','library'];

// ── CSV column definitions ────────────────────────────────────────
function ie_headers(string $entity): array {
    return [
        'students' => [
            'admission_no','first_name','last_name','gender','date_of_birth',
            'class','parent_name','parent_phone','parent_email',
            'nationality','address','emergency_contact','emergency_phone',
            'medical_notes','status','admitted_on',
        ],
        'parents' => [
            'first_name','last_name','relationship','phone','email',
            'national_id','occupation','address','status','student_admission_no',
        ],
        'teachers' => [
            'employee_id','first_name','last_name','gender','date_of_birth',
            'nationality','email','phone','qualification','specialization',
            'contract_type','join_date','address','status',
        ],
        'subjects' => [
            'code','name','department','description','is_elective','pass_mark','status',
        ],
        'library' => [
            'isbn','title','author','publisher','category','edition',
            'year','total_copies','shelf_location','status',
        ],
    ][$entity] ?? [];
}

// ── Sample row (one example per entity for template) ─────────────
function ie_sample(string $entity): array {
    return [
        'students' => [
            'STU-001','Jane','Doe','female','2012-05-14',
            'Grade 7A','John Doe','+254700000001','john.doe@example.com',
            'Kenyan','123 Main St, Nairobi','Mary Doe','+254700000002',
            '','active','2023-01-10',
        ],
        'parents' => [
            'Alice','Mwangi','mother','+254711000001','alice.mwangi@example.com',
            'ID123456','Accountant','456 Oak Rd, Nairobi','active','STU-001',
        ],
        'teachers' => [
            'TCH-001','Peter','Kamau','male','1985-03-20',
            'Kenyan','peter.kamau@school.ac.ke','+254722000001',
            'B.Ed (Science)','Mathematics','permanent','2019-09-01',
            '789 Cedar Ln, Nairobi','active',
        ],
        'subjects' => [
            'MATH101','Mathematics','Sciences','Core mathematics curriculum','no','50.00','active',
        ],
        'library' => [
            '9780143105428','The Art of Learning','Josh Waitzkin','Free Press',
            'Self-Help','1st','2007','3','Shelf B-4','available',
        ],
    ][$entity] ?? [];
}

// ════════════════════════════════════════════════════════════════
//  DOWNLOAD HANDLER — must run before any HTML
// ════════════════════════════════════════════════════════════════
$dlAction = $_GET['action'] ?? '';
$dlEntity = $_GET['entity'] ?? '';

if (in_array($dlAction, ['export','template']) && in_array($dlEntity, $allEntities)) {
    $isTemplate = ($dlAction === 'template');
    $suffix     = $isTemplate ? 'template' : 'export_'.date('Ymd_His');
    $filename   = $dlEntity . '_' . $suffix . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM — keeps Excel happy
    fputcsv($out, ie_headers($dlEntity));

    if ($isTemplate) {
        $sample = ie_sample($dlEntity);
        if ($sample) fputcsv($out, $sample);
    } else {
        // Stream live data rows
        switch ($dlEntity) {

            case 'students':
                $s = $pdo->prepare(
                    "SELECT st.admission_no, st.first_name, st.last_name, st.gender,
                            st.dob, COALESCE(c.name,'') AS class_name, st.parent_name,
                            st.parent_phone, st.parent_email, st.nationality,
                            st.address, st.emergency_contact, st.emergency_phone,
                            COALESCE(st.medical_conditions,''), st.status,
                            st.admitted_on
                     FROM sch_students st
                     LEFT JOIN sch_classes c ON c.id = st.class_id
                     WHERE st.org_id=? ORDER BY c.name, st.last_name, st.first_name"
                );
                $s->execute([$orgId]);
                while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
                break;

            case 'parents':
                $s = $pdo->prepare(
                    "SELECT p.first_name, p.last_name, p.relationship, p.phone, p.email,
                            COALESCE(p.national_id,''), COALESCE(p.occupation,''),
                            COALESCE(p.address,''), p.status,
                            COALESCE(GROUP_CONCAT(st.admission_no ORDER BY st.admission_no SEPARATOR ','),'') AS admno
                     FROM sch_parents p
                     LEFT JOIN sch_student_parents sp ON sp.parent_id = p.id
                     LEFT JOIN sch_students st ON st.id = sp.student_id AND st.org_id=p.org_id
                     WHERE p.org_id=?
                     GROUP BY p.id
                     ORDER BY p.last_name, p.first_name"
                );
                $s->execute([$orgId]);
                while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
                break;

            case 'teachers':
                $s = $pdo->prepare(
                    "SELECT COALESCE(employee_id,''), first_name, last_name,
                            COALESCE(gender,''), COALESCE(dob,''), COALESCE(nationality,''),
                            COALESCE(email,''), COALESCE(phone,''), COALESCE(qualification,''),
                            COALESCE(specialization,''), COALESCE(contract_type,''),
                            COALESCE(join_date,''), COALESCE(address,''), status
                     FROM sch_teachers WHERE org_id=?
                     ORDER BY last_name, first_name"
                );
                $s->execute([$orgId]);
                while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
                break;

            case 'subjects':
                $s = $pdo->prepare(
                    "SELECT COALESCE(code,''), name, COALESCE(department,''),
                            COALESCE(description,''),
                            IF(is_elective=1,'yes','no'),
                            pass_mark, status
                     FROM sch_subjects WHERE org_id=?
                     ORDER BY department, name"
                );
                $s->execute([$orgId]);
                while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
                break;

            case 'library':
                $s = $pdo->prepare(
                    "SELECT COALESCE(isbn,''), title, COALESCE(author,''),
                            COALESCE(publisher,''), COALESCE(category,''),
                            COALESCE(edition,''), COALESCE(year,''),
                            total_copies, COALESCE(shelf,''), status
                     FROM sch_books WHERE org_id=? ORDER BY title"
                );
                $s->execute([$orgId]);
                while ($r = $s->fetch(PDO::FETCH_NUM)) fputcsv($out, $r);
                break;
        }
    }
    fclose($out);
    exit;
}

// ════════════════════════════════════════════════════════════════
//  IMPORT HANDLER
// ════════════════════════════════════════════════════════════════
$importResult = null;
$activeTab    = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'students');
if (!in_array($activeTab, $allEntities)) $activeTab = 'students';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    denyIfReadOnly($moduleSlug);

    $entity    = $_POST['entity'] ?? '';
    $activeTab = in_array($entity, $allEntities) ? $entity : 'students';

    $importResult = [
        'entity'  => $entity,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors'  => [],
        'total'   => 0,
    ];

    $uploadedFile = $_FILES['csv_file']['tmp_name'] ?? '';
    if (!$uploadedFile || !is_uploaded_file($uploadedFile)) {
        $importResult['errors'][] = 'No file uploaded. Please choose a CSV file.';
    } else {
        $handle = fopen($uploadedFile, 'r');
        if (!$handle) {
            $importResult['errors'][] = 'Could not read the uploaded file.';
        } else {
            // Strip BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") fseek($handle, 0);

            $rawHeaders = fgetcsv($handle);
            if (!$rawHeaders) {
                $importResult['errors'][] = 'CSV file appears to be empty or invalid.';
            } else {
                // Normalise headers: lowercase + trim + strip non-ascii
                $fileHeaders = array_map(fn($h) => strtolower(trim(preg_replace('/[^\w]/', '_', $h))), $rawHeaders);
                $expected    = array_map(fn($h) => strtolower(trim(preg_replace('/[^\w]/', '_', $h))), ie_headers($entity));

                // Build column index map: expected_column => file column index
                $colMap = [];
                foreach ($expected as $col) {
                    $idx = array_search($col, $fileHeaders);
                    $colMap[$col] = ($idx !== false) ? $idx : null;
                }

                $getCol = function(array $row, string $col) use ($colMap): string {
                    $idx = $colMap[$col] ?? null;
                    return ($idx !== null && isset($row[$idx])) ? trim($row[$idx]) : '';
                };

                // ── Preload lookups ───────────────────────────────
                $classMap    = []; // lower(name) → id
                $studentAdmMap = []; // admission_no → id
                $teacherEmpMap = []; // employee_id → id

                if ($entity === 'students') {
                    $rs = $pdo->prepare("SELECT id, LOWER(TRIM(name)) AS n FROM sch_classes WHERE org_id=?");
                    $rs->execute([$orgId]);
                    foreach ($rs->fetchAll() as $c) $classMap[$c['n']] = $c['id'];
                }
                if ($entity === 'parents') {
                    $rs = $pdo->prepare("SELECT id, admission_no FROM sch_students WHERE org_id=?");
                    $rs->execute([$orgId]);
                    foreach ($rs->fetchAll() as $st) $studentAdmMap[$st['admission_no']] = $st['id'];
                }

                // ── Process rows ──────────────────────────────────
                $rowNum = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNum++;
                    $importResult['total']++;

                    // Skip entirely blank rows
                    if (empty(array_filter($row, fn($v) => trim($v) !== ''))) {
                        $importResult['skipped']++;
                        continue;
                    }

                    $g = fn(string $col) => $getCol($row, $col);

                    try {
                        switch ($entity) {

                            // ── STUDENTS ──────────────────────────
                            case 'students': {
                                $admNo     = $g('admission_no');
                                $firstName = $g('first_name');
                                $lastName  = $g('last_name');
                                if (!$firstName || !$lastName) {
                                    $importResult['errors'][] = "Row $rowNum: first_name and last_name are required.";
                                    $importResult['skipped']++;
                                    continue 2;
                                }
                                $gender    = in_array(strtolower($g('gender')), ['male','female']) ? strtolower($g('gender')) : 'male';
                                $dob       = $g('date_of_birth') ?: null;
                                $className = strtolower(trim($g('class')));
                                $classId   = ($className && isset($classMap[$className])) ? $classMap[$className] : null;
                                $parentName  = $g('parent_name');
                                $parentPhone = $g('parent_phone');
                                $parentEmail = $g('parent_email');
                                $nationality = $g('nationality');
                                $address     = $g('address');
                                $emergName   = $g('emergency_contact');
                                $emergPhone  = $g('emergency_phone');
                                $medNotes    = $g('medical_notes');
                                $rawStatus   = strtolower($g('status'));
                                $status      = in_array($rawStatus, ['active','inactive','graduated','transferred']) ? $rawStatus : 'active';
                                $admittedOn  = $g('admitted_on') ?: date('Y-m-d');

                                if ($admNo) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_students WHERE admission_no=? AND org_id=? LIMIT 1");
                                    $chk->execute([$admNo, $orgId]);
                                    $existId = $chk->fetchColumn();
                                } else {
                                    $existId = false;
                                }

                                if ($existId) {
                                    $pdo->prepare(
                                        "UPDATE sch_students
                                         SET first_name=?,last_name=?,gender=?,dob=?,class_id=?,
                                             parent_name=?,parent_phone=?,parent_email=?,nationality=?,
                                             address=?,emergency_contact=?,emergency_phone=?,
                                             medical_conditions=?,status=?
                                         WHERE id=? AND org_id=?"
                                    )->execute([
                                        $firstName,$lastName,$gender,$dob,$classId,
                                        $parentName,$parentPhone,$parentEmail,$nationality,
                                        $address,$emergName,$emergPhone,$medNotes,$status,
                                        $existId,$orgId,
                                    ]);
                                    $importResult['updated']++;
                                } else {
                                    if (!$admNo) {
                                        // Auto-generate admission number
                                        $admNo = 'STU-' . strtoupper(uniqid());
                                    }
                                    $pdo->prepare(
                                        "INSERT INTO sch_students
                                         (org_id,admission_no,first_name,last_name,gender,dob,class_id,
                                          parent_name,parent_phone,parent_email,nationality,address,
                                          emergency_contact,emergency_phone,medical_conditions,status,admitted_on)
                                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                                    )->execute([
                                        $orgId,$admNo,$firstName,$lastName,$gender,$dob,$classId,
                                        $parentName,$parentPhone,$parentEmail,$nationality,$address,
                                        $emergName,$emergPhone,$medNotes,$status,$admittedOn,
                                    ]);
                                    $importResult['created']++;
                                }
                                if ($classId === null && $className) {
                                    $importResult['errors'][] = "Row $rowNum: Class \"$className\" not found — student saved without class.";
                                }
                                break;
                            }

                            // ── PARENTS ───────────────────────────
                            case 'parents': {
                                $firstName = $g('first_name');
                                $lastName  = $g('last_name');
                                $phone     = $g('phone');
                                if (!$firstName || !$lastName) {
                                    $importResult['errors'][] = "Row $rowNum: first_name and last_name are required.";
                                    $importResult['skipped']++;
                                    continue 2;
                                }
                                $relationship = $g('relationship') ?: 'parent';
                                $email        = $g('email') ?: null;
                                $nationalId   = $g('national_id') ?: null;
                                $occupation   = $g('occupation');
                                $address      = $g('address');
                                $rawStatus    = strtolower($g('status'));
                                $status       = in_array($rawStatus, ['active','inactive']) ? $rawStatus : 'active';
                                $stuAdmNos    = array_filter(array_map('trim', explode(',', $g('student_admission_no'))));

                                // Match by email, then phone
                                $existId = null;
                                if ($email) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_parents WHERE email=? AND org_id=? LIMIT 1");
                                    $chk->execute([$email, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }
                                if (!$existId && $phone) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_parents WHERE phone=? AND org_id=? LIMIT 1");
                                    $chk->execute([$phone, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }

                                if ($existId) {
                                    $pdo->prepare(
                                        "UPDATE sch_parents
                                         SET first_name=?,last_name=?,relationship=?,phone=?,email=?,
                                             national_id=?,occupation=?,address=?,status=?
                                         WHERE id=? AND org_id=?"
                                    )->execute([
                                        $firstName,$lastName,$relationship,$phone,$email,
                                        $nationalId,$occupation,$address,$status,$existId,$orgId,
                                    ]);
                                    $parentId = $existId;
                                    $importResult['updated']++;
                                } else {
                                    $pdo->prepare(
                                        "INSERT INTO sch_parents
                                         (org_id,first_name,last_name,relationship,phone,email,
                                          national_id,occupation,address,status)
                                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                                    )->execute([
                                        $orgId,$firstName,$lastName,$relationship,$phone,$email,
                                        $nationalId,$occupation,$address,$status,
                                    ]);
                                    $parentId = (int)$pdo->lastInsertId();
                                    $importResult['created']++;
                                }

                                // Link to students
                                foreach ($stuAdmNos as $admNo) {
                                    $stuId = $studentAdmMap[$admNo] ?? null;
                                    if ($stuId) {
                                        $pdo->prepare(
                                            "INSERT IGNORE INTO sch_student_parents (student_id,parent_id,is_primary)
                                             VALUES (?,?,0)"
                                        )->execute([$stuId, $parentId]);
                                    } else {
                                        $importResult['errors'][] = "Row $rowNum: Student \"$admNo\" not found for parent link.";
                                    }
                                }
                                break;
                            }

                            // ── TEACHERS ──────────────────────────
                            case 'teachers': {
                                $firstName = $g('first_name');
                                $lastName  = $g('last_name');
                                if (!$firstName || !$lastName) {
                                    $importResult['errors'][] = "Row $rowNum: first_name and last_name are required.";
                                    $importResult['skipped']++;
                                    continue 2;
                                }
                                $empId       = $g('employee_id') ?: null;
                                $gender      = in_array(strtolower($g('gender')), ['male','female']) ? strtolower($g('gender')) : null;
                                $dob         = $g('date_of_birth') ?: null;
                                $nationality = $g('nationality');
                                $email       = $g('email') ?: null;
                                $phone       = $g('phone');
                                $qual        = $g('qualification');
                                $special     = $g('specialization');
                                $contract    = $g('contract_type') ?: 'permanent';
                                $joinDate    = $g('join_date') ?: null;
                                $address     = $g('address');
                                $rawStatus   = strtolower($g('status'));
                                $status      = in_array($rawStatus, ['active','inactive','on_leave']) ? $rawStatus : 'active';

                                // Match by employee_id then email
                                $existId = null;
                                if ($empId) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_teachers WHERE employee_id=? AND org_id=? LIMIT 1");
                                    $chk->execute([$empId, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }
                                if (!$existId && $email) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_teachers WHERE email=? AND org_id=? LIMIT 1");
                                    $chk->execute([$email, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }

                                if ($existId) {
                                    $pdo->prepare(
                                        "UPDATE sch_teachers
                                         SET employee_id=COALESCE(?,employee_id),first_name=?,last_name=?,
                                             gender=?,dob=?,nationality=?,email=COALESCE(?,email),
                                             phone=?,qualification=?,specialization=?,
                                             contract_type=?,join_date=?,address=?,status=?
                                         WHERE id=? AND org_id=?"
                                    )->execute([
                                        $empId,$firstName,$lastName,$gender,$dob,$nationality,
                                        $email,$phone,$qual,$special,$contract,$joinDate,$address,
                                        $status,$existId,$orgId,
                                    ]);
                                    $importResult['updated']++;
                                } else {
                                    $pdo->prepare(
                                        "INSERT INTO sch_teachers
                                         (org_id,employee_id,first_name,last_name,gender,dob,nationality,
                                          email,phone,qualification,specialization,contract_type,join_date,
                                          address,status)
                                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                                    )->execute([
                                        $orgId,$empId,$firstName,$lastName,$gender,$dob,$nationality,
                                        $email,$phone,$qual,$special,$contract,$joinDate,$address,$status,
                                    ]);
                                    $importResult['created']++;
                                }
                                break;
                            }

                            // ── SUBJECTS ──────────────────────────
                            case 'subjects': {
                                $name = $g('name');
                                if (!$name) {
                                    $importResult['errors'][] = "Row $rowNum: name is required.";
                                    $importResult['skipped']++;
                                    continue 2;
                                }
                                $code      = $g('code') ?: null;
                                $dept      = $g('department');
                                $desc      = $g('description');
                                $elective  = in_array(strtolower($g('is_elective')), ['yes','1','true']) ? 1 : 0;
                                $passMark  = is_numeric($g('pass_mark')) ? (float)$g('pass_mark') : 50.0;
                                $rawStatus = strtolower($g('status'));
                                $status    = in_array($rawStatus, ['active','inactive']) ? $rawStatus : 'active';

                                // Match by code, else by name
                                $existId = null;
                                if ($code) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_subjects WHERE code=? AND org_id=? LIMIT 1");
                                    $chk->execute([$code, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }
                                if (!$existId) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_subjects WHERE name=? AND org_id=? LIMIT 1");
                                    $chk->execute([$name, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }

                                if ($existId) {
                                    $pdo->prepare(
                                        "UPDATE sch_subjects
                                         SET code=?,name=?,department=?,description=?,
                                             is_elective=?,pass_mark=?,status=?
                                         WHERE id=? AND org_id=?"
                                    )->execute([$code,$name,$dept,$desc,$elective,$passMark,$status,$existId,$orgId]);
                                    $importResult['updated']++;
                                } else {
                                    $pdo->prepare(
                                        "INSERT INTO sch_subjects
                                         (org_id,code,name,department,description,is_elective,pass_mark,status)
                                         VALUES (?,?,?,?,?,?,?,?)"
                                    )->execute([$orgId,$code,$name,$dept,$desc,$elective,$passMark,$status]);
                                    $importResult['created']++;
                                }
                                break;
                            }

                            // ── LIBRARY ───────────────────────────
                            case 'library': {
                                $title  = $g('title');
                                $author = $g('author');
                                if (!$title) {
                                    $importResult['errors'][] = "Row $rowNum: title is required.";
                                    $importResult['skipped']++;
                                    continue 2;
                                }
                                $isbn      = $g('isbn') ?: null;
                                $publisher = $g('publisher');
                                $category  = $g('category');
                                $edition   = $g('edition');
                                $year      = is_numeric($g('year')) ? (int)$g('year') : null;
                                $copies    = is_numeric($g('total_copies')) ? (int)$g('total_copies') : 1;
                                $shelf     = $g('shelf_location');
                                $rawStatus = strtolower($g('status'));
                                $status    = in_array($rawStatus, ['available','unavailable','damaged','lost']) ? $rawStatus : 'available';

                                // Match by ISBN, else by title+author
                                $existId = null;
                                if ($isbn) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_books WHERE isbn=? AND org_id=? LIMIT 1");
                                    $chk->execute([$isbn, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }
                                if (!$existId && $author) {
                                    $chk = $pdo->prepare("SELECT id FROM sch_books WHERE title=? AND author=? AND org_id=? LIMIT 1");
                                    $chk->execute([$title, $author, $orgId]);
                                    $existId = $chk->fetchColumn() ?: null;
                                }

                                if ($existId) {
                                    $pdo->prepare(
                                        "UPDATE sch_books
                                         SET isbn=COALESCE(?,isbn),title=?,author=?,publisher=?,
                                             category=?,edition=?,year=?,total_copies=?,shelf=?,status=?
                                         WHERE id=? AND org_id=?"
                                    )->execute([
                                        $isbn,$title,$author,$publisher,$category,
                                        $edition,$year,$copies,$shelf,$status,$existId,$orgId,
                                    ]);
                                    // Recalc available if copies changed
                                    $pdo->prepare(
                                        "UPDATE sch_books b SET b.available =
                                         b.total_copies - COALESCE((
                                             SELECT COUNT(*) FROM sch_book_loans l
                                             WHERE l.book_id=b.id AND l.status='issued'
                                         ),0) WHERE b.id=?"
                                    )->execute([$existId]);
                                    $importResult['updated']++;
                                } else {
                                    $pdo->prepare(
                                        "INSERT INTO sch_books
                                         (org_id,isbn,title,author,publisher,category,edition,
                                          year,total_copies,available,shelf,status)
                                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                                    )->execute([
                                        $orgId,$isbn,$title,$author,$publisher,$category,
                                        $edition,$year,$copies,$copies,$shelf,$status,
                                    ]);
                                    $importResult['created']++;
                                }
                                break;
                            }
                        }
                    } catch (Throwable $ex) {
                        $importResult['errors'][] = "Row $rowNum: " . $ex->getMessage();
                        $importResult['skipped']++;
                    }
                } // end while

                fclose($handle);
            }
        }
    }
}

// ════════════════════════════════════════════════════════════════
//  PAGE RENDER
// ════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../../includes/header-module.php';

// Quick entity counts for badges
$counts = [];
foreach ([
    'students' => "SELECT COUNT(*) FROM sch_students WHERE org_id=?",
    'parents'  => "SELECT COUNT(*) FROM sch_parents WHERE org_id=?",
    'teachers' => "SELECT COUNT(*) FROM sch_teachers WHERE org_id=?",
    'subjects' => "SELECT COUNT(*) FROM sch_subjects WHERE org_id=?",
    'library'  => "SELECT COUNT(*) FROM sch_books WHERE org_id=?",
] as $ent => $sql) {
    try {
        $s = $pdo->prepare($sql); $s->execute([$orgId]);
        $counts[$ent] = (int)$s->fetchColumn();
    } catch (Throwable $e) { $counts[$ent] = 0; }
}

$entityMeta = [
    'students' => ['label'=>'Students',  'icon'=>'fas fa-user-graduate', 'color'=>'#1A8A4E'],
    'parents'  => ['label'=>'Parents',   'icon'=>'fas fa-users',          'color'=>'#0B2D4E'],
    'teachers' => ['label'=>'Teachers',  'icon'=>'fas fa-chalkboard-teacher','color'=>'#6366f1'],
    'subjects' => ['label'=>'Subjects',  'icon'=>'fas fa-book',           'color'=>'#f59e0b'],
    'library'  => ['label'=>'Library',   'icon'=>'fas fa-book-reader',    'color'=>'#06b6d4'],
];
?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0">
      <i class="fas fa-file-import me-2" style="color:#1A8A4E"></i>Import &amp; Export
    </h5>
    <div class="text-muted small mt-1">
      Transfer data in and out using CSV files compatible with Excel, Google Sheets, and Numbers.
    </div>
  </div>
</div>

<!-- Import result banner -->
<?php if ($importResult): ?>
<div class="card border-0 shadow-sm mb-4"
     style="border-left:4px solid <?= empty($importResult['errors']) && $importResult['skipped']===0 ? '#1A8A4E' : (($importResult['created']>0||$importResult['updated']>0) ? '#f59e0b' : '#e74c3c') ?>!important">
  <div class="card-body">
    <h6 class="fw-bold mb-3">
      <i class="fas fa-clipboard-check me-2"></i>
      Import Complete — <?= ucfirst($importResult['entity']) ?>
    </h6>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="text-center p-2 rounded" style="background:#f0fdf4">
          <div class="fw-bold" style="font-size:1.6rem;color:#1A8A4E"><?= $importResult['created'] ?></div>
          <div class="text-muted small">Created</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-center p-2 rounded" style="background:#eff6ff">
          <div class="fw-bold" style="font-size:1.6rem;color:#1d4ed8"><?= $importResult['updated'] ?></div>
          <div class="text-muted small">Updated</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-center p-2 rounded" style="background:#fefce8">
          <div class="fw-bold" style="font-size:1.6rem;color:#92400e"><?= $importResult['skipped'] ?></div>
          <div class="text-muted small">Skipped</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-center p-2 rounded" style="background:#fef2f2">
          <div class="fw-bold" style="font-size:1.6rem;color:#e74c3c"><?= count($importResult['errors']) ?></div>
          <div class="text-muted small">Warnings</div>
        </div>
      </div>
    </div>
    <?php if (!empty($importResult['errors'])): ?>
    <details>
      <summary class="small fw-semibold text-warning cursor-pointer" style="cursor:pointer">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <?= count($importResult['errors']) ?> warning<?= count($importResult['errors'])!==1?'s':'' ?> — click to expand
      </summary>
      <ul class="mt-2 mb-0 small text-muted ps-3" style="max-height:200px;overflow-y:auto">
        <?php foreach ($importResult['errors'] as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Entity tabs -->
<ul class="nav nav-tabs mb-4" id="ieTabs">
  <?php foreach ($allEntities as $ent):
    $meta = $entityMeta[$ent];
  ?>
  <li class="nav-item">
    <a href="?tab=<?= $ent ?>"
       class="nav-link d-flex align-items-center gap-2 <?= $activeTab===$ent?'active':'' ?>">
      <i class="<?= $meta['icon'] ?>" style="color:<?= $activeTab===$ent ? $meta['color'] : '#6c757d' ?>"></i>
      <?= $meta['label'] ?>
      <span class="badge rounded-pill <?= $activeTab===$ent?'bg-success':'bg-secondary' ?> bg-opacity-25
            <?= $activeTab===$ent?'text-success':'text-secondary' ?>" style="font-size:.6rem">
        <?= number_format($counts[$ent]) ?>
      </span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- Content for active tab -->
<?php
  $meta   = $entityMeta[$activeTab];
  $hdrs   = ie_headers($activeTab);
  $baseUrl = "import-export.php";

  $descriptions = [
    'students' => 'Import or export student enrolment records. When importing, students are matched by <strong>admission_no</strong>; omit it to create new records. Class is matched by name — ensure the class exists first.',
    'parents'  => 'Import or export parent/guardian records. Matched by <strong>email</strong> then <strong>phone</strong>. Use the <em>student_admission_no</em> column to link parents to students (comma-separate multiple admission numbers).',
    'teachers' => 'Import or export teacher profiles. Matched by <strong>employee_id</strong> then <strong>email</strong>.',
    'subjects' => 'Import or export the subject catalog. Matched by <strong>code</strong> first, then by <strong>name</strong>.',
    'library'  => 'Import or export the book catalog. Matched by <strong>ISBN</strong>. <em>available</em> copies are auto-calculated from total_copies minus active loans — do not import loan data here.',
  ];
?>

<div class="row g-4">
  <!-- Left: Export + Template -->
  <div class="col-lg-4">

    <!-- Export card -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-1">
          <i class="fas fa-file-export me-2" style="color:#1A8A4E"></i>Export
        </h6>
        <p class="text-muted small mb-3">
          Download all <?= $meta['label'] ?> (<?= number_format($counts[$activeTab]) ?> records) as a CSV file.
        </p>
        <a href="<?= $baseUrl ?>?action=export&entity=<?= $activeTab ?>"
           class="btn btn-success btn-sm w-100">
          <i class="fas fa-download me-1"></i>Download <?= $meta['label'] ?> CSV
        </a>
      </div>
    </div>

    <!-- Template card -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-1">
          <i class="fas fa-file-csv me-2 text-primary"></i>Import Template
        </h6>
        <p class="text-muted small mb-3">
          Download a blank CSV template with the correct headers and one example row.
        </p>
        <a href="<?= $baseUrl ?>?action=template&entity=<?= $activeTab ?>"
           class="btn btn-outline-primary btn-sm w-100">
          <i class="fas fa-download me-1"></i>Download Template
        </a>
      </div>
    </div>

    <!-- Column reference card -->
    <div class="card border-0 shadow-sm">
      <div class="card-header py-2">
        <span class="small fw-semibold">Required Column Headers</span>
      </div>
      <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($hdrs as $h): ?>
          <code class="small px-1 py-0 rounded" style="background:#f1f3f5;color:#495057;font-size:.72rem"><?= e($h) ?></code>
          <?php endforeach; ?>
        </div>
        <p class="text-muted mt-2 mb-0" style="font-size:.72rem">
          Column order doesn't matter — headers must match exactly (case-insensitive).
        </p>
      </div>
    </div>

  </div>

  <!-- Right: Import form -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header">
        <h6 class="mb-0 fw-bold">
          <i class="fas fa-file-import me-2" style="color:<?= $meta['color'] ?>"></i>
          Import <?= $meta['label'] ?>
        </h6>
      </div>
      <div class="card-body">
        <!-- Description -->
        <div class="alert border-0 mb-4" style="background:#f8f9fa">
          <i class="fas fa-info-circle me-2 text-primary"></i>
          <?= $descriptions[$activeTab] ?>
        </div>

        <!-- Upload form -->
        <form method="POST" enctype="multipart/form-data" id="importForm">
          <?= csrfField() ?>
          <input type="hidden" name="entity" value="<?= $activeTab ?>">

          <div class="mb-4">
            <label class="form-label fw-semibold">
              CSV File <span class="text-danger">*</span>
            </label>
            <div class="border-2 border-dashed rounded p-4 text-center"
                 id="dropZone"
                 style="border:2px dashed #dee2e6;cursor:pointer;transition:all .2s"
                 onclick="document.getElementById('csvInput').click()">
              <i class="fas fa-cloud-upload-alt fa-2x mb-2 d-block" style="color:#adb5bd"></i>
              <div class="fw-semibold text-dark small mb-1">Click to choose file or drag &amp; drop</div>
              <div class="text-muted" style="font-size:.78rem">CSV files only · Max 10 MB</div>
              <div id="fileNameDisplay" class="mt-2 text-success small fw-semibold"></div>
            </div>
            <input type="file" name="csv_file" id="csvInput" accept=".csv,text/csv" class="d-none" required>
          </div>

          <!-- Update behaviour toggle -->
          <div class="mb-4 p-3 rounded" style="background:#f0fdf4">
            <div class="fw-semibold small mb-2" style="color:#1A8A4E">
              <i class="fas fa-cog me-1"></i>Import Behaviour
            </div>
            <div class="d-flex flex-column gap-1 small text-muted">
              <span><i class="fas fa-plus-circle me-1 text-success"></i>New records → <strong>created</strong></span>
              <span><i class="fas fa-sync-alt me-1 text-primary"></i>Matching records → <strong>updated</strong> (by <?= ['students'=>'admission_no','parents'=>'email / phone','teachers'=>'employee_id / email','subjects'=>'code / name','library'=>'isbn'][$activeTab] ?>)</span>
              <span><i class="fas fa-exclamation-circle me-1 text-warning"></i>Rows missing required fields → <strong>skipped</strong></span>
            </div>
          </div>

          <!-- CSV preview (populated by JS) -->
          <div id="previewContainer" class="d-none mb-4">
            <div class="fw-semibold small mb-2"><i class="fas fa-table me-1"></i>File Preview (first 5 rows)</div>
            <div class="table-responsive rounded border">
              <table class="table table-sm table-bordered mb-0 small" id="previewTable">
                <thead class="table-light" id="previewHead"></thead>
                <tbody id="previewBody"></tbody>
              </table>
            </div>
          </div>

          <button type="submit" class="btn btn-success px-4" id="importBtn" disabled>
            <i class="fas fa-file-import me-1"></i>Import <?= $meta['label'] ?>
          </button>
          <span class="text-muted small ms-2">Review the preview above before importing.</span>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var input    = document.getElementById('csvInput');
  var nameDisp = document.getElementById('fileNameDisplay');
  var dropZone = document.getElementById('dropZone');
  var btn      = document.getElementById('importBtn');
  var prevBox  = document.getElementById('previewContainer');
  var prevHead = document.getElementById('previewHead');
  var prevBody = document.getElementById('previewBody');

  function handleFile(file) {
    if (!file) return;
    nameDisp.textContent = '📄 ' + file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    dropZone.style.borderColor = '#1A8A4E';
    btn.disabled = false;
    // CSV preview
    var reader = new FileReader();
    reader.onload = function(e) {
      var lines = e.target.result.split('\n').filter(function(l){ return l.trim(); }).slice(0, 6);
      if (!lines.length) return;
      prevHead.innerHTML = '';
      prevBody.innerHTML = '';
      var headerRow = parseCSVLine(lines[0]);
      var trH = '<tr>' + headerRow.map(function(h){ return '<th class="fw-semibold">' + esc(h) + '</th>'; }).join('') + '</tr>';
      prevHead.innerHTML = trH;
      for (var i = 1; i < lines.length; i++) {
        var cells = parseCSVLine(lines[i]);
        var tr = '<tr>' + cells.map(function(c){ return '<td class="text-muted">' + esc(c) + '</td>'; }).join('') + '</tr>';
        prevBody.innerHTML += tr;
      }
      prevBox.classList.remove('d-none');
    };
    reader.readAsText(file, 'UTF-8');
  }

  function parseCSVLine(line) {
    var result = [], cur = '', inQ = false;
    for (var i = 0; i < line.length; i++) {
      var c = line[i];
      if (c === '"') { inQ = !inQ; }
      else if (c === ',' && !inQ) { result.push(cur); cur = ''; }
      else { cur += c; }
    }
    result.push(cur);
    return result;
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  input.addEventListener('change', function() { handleFile(this.files[0]); });

  // Drag & drop
  dropZone.addEventListener('dragover', function(e){ e.preventDefault(); this.style.background='#f0fdf4'; });
  dropZone.addEventListener('dragleave', function(){ this.style.background=''; });
  dropZone.addEventListener('drop', function(e){
    e.preventDefault(); this.style.background='';
    var file = e.dataTransfer.files[0];
    if (file) { input.files = e.dataTransfer.files; handleFile(file); }
  });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
