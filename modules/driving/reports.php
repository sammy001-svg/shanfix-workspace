<?php
$moduleSlug='driving';$moduleName='Driving School';$moduleIcon='fas fa-car';$moduleColor='#1a237e';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];

// Filters
$fInstructor=(int)($_GET['instructor_id']??0);
$fStatus=$_GET['student_status']??'';
$fFrom=$_GET['date_from']??date('Y-m-01');
$fTo=$_GET['date_to']??date('Y-m-d');
$fReport=$_GET['report']??'students';

$instructors=[];
try{$s=$pdo->prepare("SELECT id,name FROM driving_instructors WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$instructors=$s->fetchAll();}catch(Exception $e){}

// ── Student Progress Report ──────────────────────────────────────────────────
$studentProgress=[];
if($fReport==='students'){
    $where='s.org_id=?';$params=[$orgId];
    if($fInstructor){$where.=' AND s.instructor_id=?';$params[]=$fInstructor;}
    if($fStatus){$where.=' AND s.status=?';$params[]=$fStatus;}
    try{
        $sql="SELECT s.id,CONCAT(s.first_name,' ',s.last_name) AS name,s.license_category,s.enrollment_date,s.status,
            i.name AS instructor_name,
            COUNT(DISTINCT l.id) AS total_lessons,
            SUM(l.status='completed') AS completed_lessons,
            SUM(l.status='started') AS started_lessons,
            MAX(l.lesson_date) AS last_lesson,
            ROUND(AVG(CASE WHEN l.score IS NOT NULL THEN l.score END),1) AS avg_score,
            (SELECT COUNT(*) FROM driving_tests t WHERE t.student_id=s.id AND t.org_id=s.org_id) AS total_tests,
            (SELECT COUNT(*) FROM driving_tests t WHERE t.student_id=s.id AND t.org_id=s.org_id AND t.status='passed') AS tests_passed,
            (SELECT dl.status FROM driving_licenses dl WHERE dl.student_id=s.id AND dl.org_id=s.org_id ORDER BY dl.id DESC LIMIT 1) AS lic_status
          FROM driving_students s
          LEFT JOIN driving_instructors i ON s.instructor_id=i.id
          LEFT JOIN driving_lessons l ON l.student_id=s.id AND l.org_id=s.org_id
          WHERE $where GROUP BY s.id ORDER BY s.first_name,s.last_name";
        $s=$pdo->prepare($sql);$s->execute($params);$studentProgress=$s->fetchAll();
    }catch(Exception $e){}
}

// ── Instructor Performance Report ────────────────────────────────────────────
$instrPerf=[];
if($fReport==='instructors'){
    try{
        $sql="SELECT i.id,i.name,i.specialization,i.status,
            COUNT(DISTINCT s.id) AS student_count,
            COUNT(DISTINCT l.id) AS total_lessons,
            SUM(l.status='completed') AS completed_lessons,
            ROUND(100*SUM(l.status='completed')/NULLIF(COUNT(DISTINCT l.id),0),1) AS completion_rate,
            ROUND(AVG(CASE WHEN l.score IS NOT NULL THEN l.score END),1) AS avg_score,
            COUNT(DISTINCT t.id) AS tests_conducted,
            SUM(t.status='passed') AS tests_passed
          FROM driving_instructors i
          LEFT JOIN driving_students s ON s.instructor_id=i.id AND s.org_id=i.org_id
          LEFT JOIN driving_lessons l ON l.instructor_id=i.id AND l.org_id=i.org_id AND l.lesson_date BETWEEN ? AND ?
          LEFT JOIN driving_tests t ON t.instructor_id=i.id AND t.org_id=i.org_id AND t.test_date BETWEEN ? AND ?
          WHERE i.org_id=? GROUP BY i.id ORDER BY completed_lessons DESC";
        $s=$pdo->prepare($sql);$s->execute([$fFrom,$fTo,$fFrom,$fTo,$orgId]);$instrPerf=$s->fetchAll();
    }catch(Exception $e){}
}

// ── Vehicle Utilisation Report ───────────────────────────────────────────────
$vehUtil=[];
if($fReport==='vehicles'){
    try{
        $sql="SELECT v.id,v.name,v.number_plate,v.type,v.status,
            COUNT(DISTINCT l.id) AS total_lessons,
            SUM(l.status='completed') AS completed,
            SUM(l.duration_hours) AS total_hours,
            COUNT(DISTINCT l.student_id) AS students_served,
            COUNT(DISTINCT t.id) AS tests_done
          FROM driving_vehicles v
          LEFT JOIN driving_lessons l ON l.vehicle_id=v.id AND l.org_id=v.org_id AND l.lesson_date BETWEEN ? AND ?
          LEFT JOIN driving_tests t ON t.vehicle_id=v.id AND t.org_id=v.org_id AND t.test_date BETWEEN ? AND ?
          WHERE v.org_id=? GROUP BY v.id ORDER BY total_lessons DESC";
        $s=$pdo->prepare($sql);$s->execute([$fFrom,$fTo,$fFrom,$fTo,$orgId]);$vehUtil=$s->fetchAll();
    }catch(Exception $e){}
}

// ── Summary KPIs (always visible) ────────────────────────────────────────────
$totalStudents=countRows('driving_students','org_id=?',[$orgId]);
$activeStudents=countRows('driving_students','org_id=? AND status=?',[$orgId,'active']);
$lessonsInRange=0;$completedInRange=0;$passedTests=0;$failedTests=0;
try{
    $s=$pdo->prepare("SELECT COUNT(*) AS tot, SUM(status='completed') AS done FROM driving_lessons WHERE org_id=? AND lesson_date BETWEEN ? AND ?");
    $s->execute([$orgId,$fFrom,$fTo]);$r=$s->fetch();$lessonsInRange=(int)($r['tot']??0);$completedInRange=(int)($r['done']??0);
}catch(Exception $e){}
try{
    $s=$pdo->prepare("SELECT SUM(status='passed') AS p, SUM(status='failed') AS f FROM driving_tests WHERE org_id=? AND test_date BETWEEN ? AND ?");
    $s->execute([$orgId,$fFrom,$fTo]);$r=$s->fetch();$passedTests=(int)($r['p']??0);$failedTests=(int)($r['f']??0);
}catch(Exception $e){}

// Monthly lesson trend (last 6 months)
$trendLabels=[];$trendData=[];
for($i=5;$i>=0;$i--){$m=date('Y-m',strtotime("-$i months"));$trendLabels[]=$m;$trendData[$m]=0;}
try{
    $s=$pdo->prepare("SELECT DATE_FORMAT(lesson_date,'%Y-%m') AS mo, COUNT(*) AS cnt FROM driving_lessons WHERE org_id=? AND lesson_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH) AND status='completed' GROUP BY mo");
    $s->execute([$orgId]);
    foreach($s->fetchAll() as $r){if(isset($trendData[$r['mo']]))$trendData[$r['mo']]=(int)$r['cnt'];}
}catch(Exception $e){}

// Lesson status breakdown for doughnut
$lsnStatus=['completed'=>0,'started'=>0,'draft'=>0,'cancelled'=>0];
try{
    $s=$pdo->prepare("SELECT status,COUNT(*) AS cnt FROM driving_lessons WHERE org_id=? AND lesson_date BETWEEN ? AND ? GROUP BY status");
    $s->execute([$orgId,$fFrom,$fTo]);
    foreach($s->fetchAll() as $r){if(isset($lsnStatus[$r['status']]))$lsnStatus[$r['status']]=(int)$r['cnt'];}
}catch(Exception $e){}

$licStats=['pending'=>0,'approved'=>0,'rejected'=>0,'expired'=>0];
try{
    $s=$pdo->prepare("SELECT status,COUNT(*) AS cnt FROM driving_licenses WHERE org_id=? GROUP BY status");
    $s->execute([$orgId]);foreach($s->fetchAll() as $r){if(isset($licStats[$r['status']]))$licStats[$r['status']]=(int)$r['cnt'];}
}catch(Exception $e){}

$passRate=$passedTests+$failedTests>0?round(100*$passedTests/($passedTests+$failedTests)):0;
$completionRate=$lessonsInRange>0?round(100*$completedInRange/$lessonsInRange):0;
?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?=$moduleColor?>"></i>Reports & Analytics</h4><p class="text-muted mb-0">Student progress, instructor performance, vehicle utilisation and more</p></div>
  <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-user-graduate"></i></div><div class="stat-body"><div class="stat-value"><?=$activeStudents?></div><div class="stat-label">Active Students</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-road"></i></div><div class="stat-body"><div class="stat-value"><?=$completedInRange?></div><div class="stat-label">Lessons Completed</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#d4edda;color:#155724"><i class="fas fa-check-double"></i></div><div class="stat-body"><div class="stat-value"><?=$passRate?>%</div><div class="stat-label">Test Pass Rate</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-tasks"></i></div><div class="stat-body"><div class="stat-value"><?=$completionRate?>%</div><div class="stat-label">Lesson Completion</div></div></div></div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-line me-2" style="color:<?=$moduleColor?>"></i>Monthly Completed Lessons (Last 6 Months)</h6></div>
    <div class="card-body"><canvas id="trendChart" height="100"></canvas></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100"><div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-pie me-2" style="color:<?=$moduleColor?>"></i>Lesson Status Breakdown</h6></div>
    <div class="card-body d-flex align-items-center justify-content-center"><canvas id="lsnDonut" height="200"></canvas></div></div>
  </div>
</div>

<!-- License Status + Test Results row -->
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-id-card me-2" style="color:<?=$moduleColor?>"></i>License Status Overview</h6></div>
    <div class="card-body">
      <?php foreach([['pending','warning','Pending'],['approved','success','Approved'],['rejected','danger','Rejected'],['expired','secondary','Expired']] as [$key,$color,$label]):
        $total=array_sum($licStats);$pct=$total>0?round(100*$licStats[$key]/$total):0;?>
      <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1"><span class="fw-semibold"><?=$label?></span><span class="badge bg-<?=$color?> <?=$key==='pending'?'text-dark':''?>"><?=$licStats[$key]?></span></div>
        <div class="progress" style="height:8px"><div class="progress-bar bg-<?=$color?>" style="width:<?=$pct?>%"></div></div>
      </div>
      <?php endforeach;?>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><h6 class="mb-0"><i class="fas fa-clipboard-check me-2" style="color:<?=$moduleColor?>"></i>Test Results Summary (Period)</h6></div>
    <div class="card-body">
      <div class="row g-3 text-center">
        <div class="col-4">
          <div style="font-size:2rem;font-weight:700;color:<?=$moduleColor?>"><?=$passedTests+$failedTests?></div>
          <div class="text-muted small">Tests Taken</div>
        </div>
        <div class="col-4">
          <div style="font-size:2rem;font-weight:700" class="text-success"><?=$passedTests?></div>
          <div class="text-muted small">Passed</div>
        </div>
        <div class="col-4">
          <div style="font-size:2rem;font-weight:700" class="text-danger"><?=$failedTests?></div>
          <div class="text-muted small">Failed</div>
        </div>
      </div>
      <div class="mt-3">
        <div class="d-flex justify-content-between small mb-1"><span>Pass Rate</span><strong><?=$passRate?>%</strong></div>
        <div class="progress" style="height:12px"><div class="progress-bar bg-success" style="width:<?=$passRate?>%"></div></div>
      </div>
    </div></div>
  </div>
</div>

<!-- Filter Card -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Report Type</label>
      <select name="report" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="students" <?=$fReport==='students'?'selected':''?>>Student Progress</option>
        <option value="instructors" <?=$fReport==='instructors'?'selected':''?>>Instructor Performance</option>
        <option value="vehicles" <?=$fReport==='vehicles'?'selected':''?>>Vehicle Utilisation</option>
      </select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="date_from" class="form-control form-control-sm" value="<?=e($fFrom)?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="date_to" class="form-control form-control-sm" value="<?=e($fTo)?>"></div>
    <?php if($fReport==='students'):?>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Instructor</label>
      <select name="instructor_id" class="form-select form-select-sm"><option value="">All</option><?php foreach($instructors as $i):?><option value="<?=$i['id']?>" <?=$fInstructor==$i['id']?'selected':''?>><?=e($i['name'])?></option><?php endforeach;?></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="student_status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['active','inactive','graduated','suspended'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <?php endif;?>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="reports.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<!-- ── Student Progress Table ── -->
<?php if($fReport==='students'):?>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-user-graduate me-2" style="color:<?=$moduleColor?>"></i>Student Progress</h6>
  <span class="badge bg-secondary"><?=count($studentProgress)?> students</span>
</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Student</th><th>Instructor</th><th>Class</th><th>Lessons</th><th>Progress</th><th>Avg Score</th><th>Tests</th><th>Last Lesson</th><th>License</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($studentProgress)):?>
<tr><td colspan="10" class="text-center text-muted py-4">No student data found.</td></tr>
<?php else:foreach($studentProgress as $sp):
  $done=(int)($sp['completed_lessons']??0);$tot=(int)($sp['total_lessons']??0);
  $pct=$tot>0?round(100*$done/$tot):0;$barC=$pct>=80?'success':($pct>=50?'warning':'danger');
  $licColor=['approved'=>'success','pending'=>'warning','rejected'=>'danger','expired'=>'secondary'][$sp['lic_status']??'']??'light';
?>
<tr>
  <td><div class="fw-semibold"><?=e($sp['name'])?></div><div class="small text-muted">Class <?=e($sp['license_category']??'B')?></div></td>
  <td class="small"><?=e($sp['instructor_name']??'—')?></td>
  <td class="small text-muted"><?=$sp['enrollment_date']?formatDate($sp['enrollment_date']):'—'?></td>
  <td class="text-center"><span class="fw-semibold"><?=$done?></span><span class="text-muted">/ <?=$tot?></span></td>
  <td style="min-width:120px">
    <div class="d-flex align-items-center gap-2">
      <div class="progress flex-grow-1" style="height:8px"><div class="progress-bar bg-<?=$barC?>" style="width:<?=$pct?>%"></div></div>
      <small class="fw-semibold"><?=$pct?>%</small>
    </div>
  </td>
  <td class="text-center"><?=$sp['avg_score']!==null?'<span class="badge bg-'.($sp['avg_score']>=70?'success':'danger').'">'.$sp['avg_score'].'%</span>':'<span class="text-muted">—</span>'?></td>
  <td class="text-center"><span class="text-success fw-semibold"><?=(int)$sp['tests_passed']?></span><span class="text-muted">/<?=(int)$sp['total_tests']?></span></td>
  <td class="small"><?=$sp['last_lesson']?formatDate($sp['last_lesson']):'—'?></td>
  <td><?=$sp['lic_status']?'<span class="badge bg-'.$licColor.' '.($sp['lic_status']==='pending'?'text-dark':'').'">'.ucfirst($sp['lic_status']).'</span>':'<span class="text-muted small">None</span>'?></td>
  <td><?=statusBadge($sp['status']??'active')?></td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- ── Instructor Performance Table ── -->
<?php elseif($fReport==='instructors'):?>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-chalkboard-teacher me-2" style="color:<?=$moduleColor?>"></i>Instructor Performance (<?=e($fFrom)?> – <?=e($fTo)?>)</h6>
  <span class="badge bg-secondary"><?=count($instrPerf)?> instructors</span>
</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Instructor</th><th>Specialization</th><th>Students</th><th>Lessons</th><th>Completed</th><th>Completion Rate</th><th>Avg Score</th><th>Tests</th><th>Pass Rate</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($instrPerf)):?>
<tr><td colspan="10" class="text-center text-muted py-4">No instructor data found.</td></tr>
<?php else:foreach($instrPerf as $ip):
  $cr=(float)($ip['completion_rate']??0);$crC=$cr>=80?'success':($cr>=50?'warning':'danger');
  $testPass=(int)($ip['tests_passed']??0);$testTot=(int)($ip['tests_conducted']??0);
  $tpr=$testTot>0?round(100*$testPass/$testTot):0;
?>
<tr>
  <td class="fw-semibold"><?=e($ip['name'])?></td>
  <td class="small"><?=e($ip['specialization']??'—')?></td>
  <td class="text-center"><?=(int)$ip['student_count']?></td>
  <td class="text-center"><?=(int)$ip['total_lessons']?></td>
  <td class="text-center"><?=(int)$ip['completed_lessons']?></td>
  <td>
    <div class="d-flex align-items-center gap-2">
      <div class="progress flex-grow-1" style="height:8px"><div class="progress-bar bg-<?=$crC?>" style="width:<?=$cr?>%"></div></div>
      <small class="fw-semibold"><?=$cr?>%</small>
    </div>
  </td>
  <td class="text-center"><?=$ip['avg_score']!==null?'<span class="badge bg-'.($ip['avg_score']>=70?'success':'warning').'">'.$ip['avg_score'].'%</span>':'—'?></td>
  <td class="text-center"><?=$testTot?></td>
  <td class="text-center"><?=$testTot>0?'<span class="badge bg-'.($tpr>=70?'success':'danger').'">'.$tpr.'%</span>':'<span class="text-muted">—</span>'?></td>
  <td><?=statusBadge($ip['status']??'active')?></td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>

<!-- ── Vehicle Utilisation Table ── -->
<?php elseif($fReport==='vehicles'):?>
<div class="card"><div class="card-header d-flex align-items-center justify-content-between">
  <h6 class="mb-0"><i class="fas fa-car me-2" style="color:<?=$moduleColor?>"></i>Vehicle Utilisation (<?=e($fFrom)?> – <?=e($fTo)?>)</h6>
  <span class="badge bg-secondary"><?=count($vehUtil)?> vehicles</span>
</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
<thead class="table-light"><tr><th>Vehicle</th><th>Plate</th><th>Type</th><th>Total Lessons</th><th>Completed</th><th>Total Hours</th><th>Students Served</th><th>Tests</th><th>Status</th></tr></thead>
<tbody>
<?php if(empty($vehUtil)):?>
<tr><td colspan="9" class="text-center text-muted py-4">No vehicle data found.</td></tr>
<?php else:foreach($vehUtil as $vu):
  $typeIcons=['car'=>'fas fa-car','motorcycle'=>'fas fa-motorcycle','truck'=>'fas fa-truck','bus'=>'fas fa-bus','other'=>'fas fa-car-side'];
?>
<tr>
  <td><div class="d-flex align-items-center gap-2">
    <div style="width:32px;height:32px;border-radius:6px;background:<?=$moduleColor?>1a;color:<?=$moduleColor?>;display:flex;align-items:center;justify-content:center"><i class="<?=$typeIcons[$vu['type']]??'fas fa-car'?> fa-sm"></i></div>
    <span class="fw-semibold"><?=e($vu['name'])?></span>
  </div></td>
  <td><span class="badge bg-dark"><?=e($vu['number_plate'])?></span></td>
  <td><?=ucfirst($vu['type']??'car')?></td>
  <td class="text-center"><?=(int)$vu['total_lessons']?></td>
  <td class="text-center"><?=(int)$vu['completed']?></td>
  <td class="text-center"><?=number_format((float)($vu['total_hours']??0),1)?> hrs</td>
  <td class="text-center"><?=(int)$vu['students_served']?></td>
  <td class="text-center"><?=(int)$vu['tests_done']?></td>
  <td><?=statusBadge($vu['status']??'active')?></td>
</tr>
<?php endforeach;endif;?>
</tbody></table></div></div></div>
<?php endif;?>

<?php
$trendLabelsJson=json_encode(array_values($trendLabels));
$trendDataJson  =json_encode(array_values($trendData));
$lsnLabels      =json_encode(['Completed','In Progress','Draft','Cancelled']);
$lsnValues      =json_encode(array_values($lsnStatus));
$extraJs=<<<JS
<script>
(function(){
  const c=<?=$moduleColor?>;
  // Trend line chart
  new Chart(document.getElementById('trendChart'),{
    type:'line',
    data:{labels:<?=$trendLabelsJson?>,datasets:[{label:'Completed Lessons',data:<?=$trendDataJson?>,borderColor:'<?=$moduleColor?>',backgroundColor:'<?=$moduleColor?>22',tension:.3,fill:true,pointRadius:5}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}
  });
  // Doughnut
  new Chart(document.getElementById('lsnDonut'),{
    type:'doughnut',
    data:{labels:<?=$lsnLabels?>,datasets:[{data:<?=$lsnValues?>,backgroundColor:['#28a745','#17a2b8','#6c757d','#dc3545'],borderWidth:2}]},
    options:{responsive:true,cutout:'65%',plugins:{legend:{position:'bottom'}}}
  });
})();
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>
