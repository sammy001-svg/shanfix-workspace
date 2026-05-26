<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save_book'){
        $id=(int)($_POST['id']??0);
        $isbn=sanitize($_POST['isbn']??'');$title=sanitize($_POST['title']??'');$author=sanitize($_POST['author']??'');
        $publisher=sanitize($_POST['publisher']??'');$category=sanitize($_POST['category']??'');
        $edition=sanitize($_POST['edition']??'');$year=sanitize($_POST['year']??'');
        $copies=max(1,(int)($_POST['total_copies']??1));$shelf=sanitize($_POST['shelf']??'');
        $status=sanitize($_POST['status']??'active');
        if(!$title){setFlash('error','Book title is required.');redirect('library.php');}
        if($id){
            $pdo->prepare("UPDATE sch_books SET isbn=?,title=?,author=?,publisher=?,category=?,edition=?,year=?,total_copies=?,shelf=?,status=? WHERE id=? AND org_id=?")
               ->execute([$isbn,$title,$author,$publisher,$category,$edition,$year?:null,$copies,$shelf,$status,$id,$orgId]);
            setFlash('success','Book updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_books (org_id,isbn,title,author,publisher,category,edition,year,total_copies,available,shelf,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$isbn,$title,$author,$publisher,$category,$edition,$year?:null,$copies,$copies,$shelf,$status]);
            setFlash('success','Book added to catalog.');
        }
        redirect('library.php');
    }

    if($action==='delete_book'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_books WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Book removed.');redirect('library.php');
    }

    if($action==='issue_book'){
        $bookId=(int)($_POST['book_id']??0);$borrowerType=sanitize($_POST['borrower_type']??'student');
        $borrowerId=(int)($_POST['borrower_id']??0);$borrowerName=sanitize($_POST['borrower_name']??'');
        $issueDate=sanitize($_POST['issue_date']??date('Y-m-d'));$dueDate=sanitize($_POST['due_date']??'');$notes=sanitize($_POST['notes']??'');
        if(!$bookId||!$borrowerName||!$dueDate){setFlash('error','Book, borrower and due date are required.');redirect('library.php?tab=loans');}
        // Check availability
        $s=$pdo->prepare("SELECT available FROM sch_books WHERE id=? AND org_id=?");$s->execute([$bookId,$orgId]);$avail=$s->fetchColumn();
        if($avail<1){setFlash('error','No copies available.');redirect('library.php?tab=loans');}
        $pdo->prepare("INSERT INTO sch_book_loans (org_id,book_id,borrower_type,borrower_id,borrower_name,issue_date,due_date,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$orgId,$bookId,$borrowerType,$borrowerId,$borrowerName,$issueDate,$dueDate,$notes,$user['id']]);
        $pdo->prepare("UPDATE sch_books SET available=available-1 WHERE id=? AND org_id=? AND available>0")->execute([$bookId,$orgId]);
        setFlash('success','Book issued successfully.');redirect('library.php?tab=loans');
    }

    if($action==='return_book'){
        $loanId=(int)($_POST['loan_id']??0);$returnDate=sanitize($_POST['return_date']??date('Y-m-d'));$fine=(float)($_POST['fine_amount']??0);$finePaid=(int)($_POST['fine_paid']??0);
        $s=$pdo->prepare("SELECT * FROM sch_book_loans WHERE id=? AND org_id=?");$s->execute([$loanId,$orgId]);$loan=$s->fetch();
        if(!$loan||$loan['status']==='returned'){setFlash('error','Invalid loan.');redirect('library.php?tab=loans');}
        $pdo->prepare("UPDATE sch_book_loans SET return_date=?,fine_amount=?,fine_paid=?,status='returned' WHERE id=? AND org_id=?")->execute([$returnDate,$fine,$finePaid,$loanId,$orgId]);
        $pdo->prepare("UPDATE sch_books SET available=available+1 WHERE id=? AND org_id=?")->execute([$loan['book_id'],$orgId]);
        setFlash('success','Book returned.');redirect('library.php?tab=loans');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$tab=$_GET['tab']??'catalog';$search=sanitize($_GET['q']??'');

$books=[];
try{
    $where='WHERE org_id=?';$params=[$orgId];
    if($search){$where.=" AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR category LIKE ?)";$q="%$search%";$params=array_merge($params,[$q,$q,$q,$q]);}
    $s=$pdo->prepare("SELECT * FROM sch_books $where ORDER BY title");$s->execute($params);$books=$s->fetchAll();
}catch(Exception $e){}

$totalBooks=count($books);$availBooks=array_sum(array_column($books,'available'));
$totalCopies=array_sum(array_column($books,'total_copies'));

$loans=[];$overdueCount=0;
try{$s=$pdo->prepare("SELECT l.*,b.title AS book_title FROM sch_book_loans l JOIN sch_books b ON l.book_id=b.id WHERE l.org_id=? AND l.status IN ('issued','overdue') ORDER BY l.due_date");$s->execute([$orgId]);$loans=$s->fetchAll();$overdueCount=count(array_filter($loans,fn($l)=>$l['due_date']<date('Y-m-d')));}catch(Exception $e){}

$students=[];try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$students=$s->fetchAll();}catch(Exception $e){}
$staff=[];try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_staff WHERE org_id=? ORDER BY first_name");$s->execute([$orgId]);$staff=$s->fetchAll();}catch(Exception $e){}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-book-reader me-2" style="color:<?=$moduleColor?>"></i>Library</h4><p class="text-muted mb-0">Book catalog management, lending and returns</p></div>
  <div class="d-flex gap-2">
    <?php if($tab==='catalog'):?>
    <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#bookModal"><i class="fas fa-plus me-2"></i>Add Book</button>
    <?php else:?>
    <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#issueModal"><i class="fas fa-share me-2"></i>Issue Book</button>
    <?php endif;?>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-book"></i></div><div class="stat-body"><div class="stat-value"><?=$totalBooks?></div><div class="stat-label">Book Titles</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-copy"></i></div><div class="stat-body"><div class="stat-value"><?=$totalCopies?></div><div class="stat-label">Total Copies</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-hand-holding-open"></i></div><div class="stat-body"><div class="stat-value"><?=count($loans)?></div><div class="stat-label">Active Loans</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?=$overdueCount?></div><div class="stat-label">Overdue</div></div></div></div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?=$tab==='catalog'?'active':''?>" href="library.php?tab=catalog"><i class="fas fa-book me-1"></i>Book Catalog</a></li>
  <li class="nav-item"><a class="nav-link <?=$tab==='loans'?'active':''?>" href="library.php?tab=loans"><i class="fas fa-exchange-alt me-1"></i>Loans <?=$overdueCount>0?'<span class="badge bg-danger ms-1">'.$overdueCount.' overdue</span>':''?></a></li>
</ul>

<?php if($tab==='catalog'):?>
<!-- Search -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="tab" value="catalog">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" value="<?=e($search)?>" placeholder="Title, author, ISBN, categoryâ€¦"></div>
    <div class="col-auto"><button class="btn btn-sm btn-success">Search</button><a href="library.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Book Catalog (<?=count($books)?>)</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Title</th><th>Author</th><th>Category</th><th>ISBN</th><th class="text-center">Copies</th><th class="text-center">Available</th><th>Shelf</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($books)):?><tr><td colspan="9" class="text-center text-muted py-4">No books in catalog.</td></tr>
    <?php else:foreach($books as $bk):$avC=$bk['available']>0?'success':'danger';?>
    <tr>
      <td class="fw-semibold"><?=e($bk['title'])?></td>
      <td><?=e($bk['author']??'â€”')?></td>
      <td><?=e($bk['category']??'â€”')?></td>
      <td class="small text-muted"><?=e($bk['isbn']??'â€”')?></td>
      <td class="text-center"><?=$bk['total_copies']?></td>
      <td class="text-center"><span class="badge bg-<?=$avC?>"><?=$bk['available']?></span></td>
      <td class="small"><?=e($bk['shelf']??'â€”')?></td>
      <td><?=$bk['status']==='active'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Retired</span>'?></td>
      <td class="text-end">
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-book"
          data-id="<?=$bk['id']?>" data-isbn="<?=e($bk['isbn']??'')?>" data-title="<?=e($bk['title'])?>"
          data-author="<?=e($bk['author']??'')?>" data-publisher="<?=e($bk['publisher']??'')?>"
          data-category="<?=e($bk['category']??'')?>" data-edition="<?=e($bk['edition']??'')?>"
          data-year="<?=$bk['year']??''?>" data-copies="<?=$bk['total_copies']?>"
          data-shelf="<?=e($bk['shelf']??'')?>" data-status="<?=$bk['status']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete_book"><input type="hidden" name="id" value="<?=$bk['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Remove this book from catalog?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<?php else:?>
<!-- Loans -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-exchange-alt me-2" style="color:<?=$moduleColor?>"></i>Active Loans</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Book</th><th>Borrower</th><th>Type</th><th>Issued</th><th>Due Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($loans)):?><tr><td colspan="7" class="text-center text-muted py-4">No active loans.</td></tr>
    <?php else:foreach($loans as $ln):$overdue=$ln['due_date']<date('Y-m-d');$statusC=$overdue?'danger':'warning';$statusL=$overdue?'Overdue':'Issued';?>
    <tr class="<?=$overdue?'table-danger-subtle':''?>">
      <td class="fw-semibold"><?=e($ln['book_title']??'â€”')?></td>
      <td><?=e($ln['borrower_name'])?></td>
      <td><span class="badge bg-<?=$ln['borrower_type']==='student'?'primary':'info'?>"><?=ucfirst($ln['borrower_type'])?></span></td>
      <td class="small"><?=formatDate($ln['issue_date'])?></td>
      <td class="small <?=$overdue?'text-danger fw-semibold':''?>"><?=formatDate($ln['due_date'])?><?php if($overdue):?> <span class="badge bg-danger ms-1"><?=ceil((strtotime(date('Y-m-d'))-strtotime($ln['due_date']))/86400)?>d</span><?php endif;?></td>
      <td><span class="badge bg-<?=$statusC?>"><?=$statusL?></span></td>
      <td class="text-end"><button class="btn btn-xs btn-outline-success btn-return" data-id="<?=$ln['id']?>" data-book="<?=e($ln['book_title'])?>" data-borrower="<?=e($ln['borrower_name'])?>"><i class="fas fa-undo me-1"></i>Return</button></td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>
<?php endif;?>

<!-- Book Modal -->
<div class="modal fade" id="bookModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-book me-2"></i><span id="bookModalTitle">Add Book</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save_book"><input type="hidden" name="id" id="bookId" value="0">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label><input type="text" name="title" id="bookTitle" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Author</label><input type="text" name="author" id="bookAuthor" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Publisher</label><input type="text" name="publisher" id="bookPublisher" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">ISBN</label><input type="text" name="isbn" id="bookIsbn" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Category</label><input type="text" name="category" id="bookCategory" class="form-control" list="catList"><datalist id="catList"><option>Fiction</option><option>Non-Fiction</option><option>Science</option><option>History</option><option>Mathematics</option><option>Literature</option><option>Reference</option></datalist></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Edition</label><input type="text" name="edition" id="bookEdition" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Year</label><input type="number" name="year" id="bookYear" class="form-control" min="1900" max="<?=date('Y')?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Total Copies</label><input type="number" name="total_copies" id="bookCopies" class="form-control" value="1" min="1"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Shelf Location</label><input type="text" name="shelf" id="bookShelf" class="form-control" placeholder="e.g. A3"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="bookStatus" class="form-select"><option value="active">Active</option><option value="retired">Retired</option></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Book</button></div>
  </form>
</div></div></div>

<!-- Issue Modal -->
<div class="modal fade" id="issueModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-share me-2"></i>Issue Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="issue_book">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Book <span class="text-danger">*</span></label>
        <select name="book_id" class="form-select"><option value="">â€” Select Book â€”</option><?php foreach($books as $bk):if($bk['available']>0&&$bk['status']==='active'):?><option value="<?=$bk['id']?>"><?=e($bk['title'])?> (<?=$bk['available']?> avail.)</option><?php endif;endforeach;?></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Borrower Type</label>
        <select name="borrower_type" id="borrowerType" class="form-select" onchange="updateBorrower()"><option value="student">Student</option><option value="staff">Staff</option></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Borrower</label>
        <select name="borrower_id" id="borrowerSel" class="form-select" onchange="setBorrowerName(this)">
          <option value="">â€” Select â€”</option>
          <?php foreach($students as $st):?><option value="<?=$st['id']?>" class="opt-student" data-name="<?=e($st['name'])?>"><?=e($st['name'])?> (<?=e($st['admission_no']??'')?>)</option><?php endforeach;?>
          <?php foreach($staff as $sf):?><option value="<?=$sf['id']?>" class="opt-staff" data-name="<?=e($sf['name'])?>" style="display:none"><?=e($sf['name'])?></option><?php endforeach;?>
        </select>
        <input type="hidden" name="borrower_name" id="borrowerName">
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Issue Date</label><input type="date" name="issue_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Due Date <span class="text-danger">*</span></label><input type="date" name="due_date" class="form-control" value="<?=date('Y-m-d',strtotime('+14 days'))?>"></div>
      <div class="col-12"><label class="form-label fw-semibold">Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional"></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Issue Book</button></div>
  </form>
</div></div></div>

<!-- Return Modal -->
<div class="modal fade" id="returnModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo me-2"></i>Return Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="return_book"><input type="hidden" name="loan_id" id="returnLoanId">
    <p class="text-muted small mb-3"><strong id="returnBookTitle"></strong> borrowed by <strong id="returnBorrower"></strong></p>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Return Date</label><input type="date" name="return_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Fine Amount</label><input type="number" name="fine_amount" class="form-control" value="0" min="0" step="0.01"></div>
      <div class="col-12"><div class="form-check"><input type="checkbox" name="fine_paid" value="1" class="form-check-input" id="finePaid"><label class="form-check-label" for="finePaid">Fine paid</label></div></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Confirm Return</button></div>
  </form>
</div></div></div>

<?php ob_start();?>
<script>
document.querySelectorAll('.btn-edit-book').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('bookModalTitle').textContent='Edit Book';
  document.getElementById('bookId').value=this.dataset.id;
  document.getElementById('bookTitle').value=this.dataset.title||'';
  document.getElementById('bookAuthor').value=this.dataset.author||'';
  document.getElementById('bookPublisher').value=this.dataset.publisher||'';
  document.getElementById('bookIsbn').value=this.dataset.isbn||'';
  document.getElementById('bookCategory').value=this.dataset.category||'';
  document.getElementById('bookEdition').value=this.dataset.edition||'';
  document.getElementById('bookYear').value=this.dataset.year||'';
  document.getElementById('bookCopies').value=this.dataset.copies||1;
  document.getElementById('bookShelf').value=this.dataset.shelf||'';
  document.getElementById('bookStatus').value=this.dataset.status||'active';
  new bootstrap.Modal(document.getElementById('bookModal')).show();
});});
document.querySelectorAll('.btn-return').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('returnLoanId').value=this.dataset.id;
  document.getElementById('returnBookTitle').textContent=this.dataset.book||'';
  document.getElementById('returnBorrower').textContent=this.dataset.borrower||'';
  new bootstrap.Modal(document.getElementById('returnModal')).show();
});});
function updateBorrower(){
  var type=document.getElementById('borrowerType').value;
  document.querySelectorAll('.opt-student').forEach(o=>o.style.display=type==='student'?'':'none');
  document.querySelectorAll('.opt-staff').forEach(o=>o.style.display=type==='staff'?'':'none');
  document.getElementById('borrowerSel').value='';document.getElementById('borrowerName').value='';
}
function setBorrowerName(sel){var opt=sel.options[sel.selectedIndex];document.getElementById('borrowerName').value=opt?opt.dataset.name||opt.text:'';}
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>

