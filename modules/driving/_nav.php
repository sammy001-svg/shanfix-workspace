<?php
/**
 * modules/driving/_nav.php
 * Shared navigation definition for all driving module pages.
 * Include BEFORE header-module.php in every driving page.
 */
$moduleSlug  = 'driving';
$moduleName  = 'Driving School';
$moduleIcon  = 'fas fa-car-side';
$moduleColor = '#1a237e';

$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'students.php','icon'=>'fas fa-user-graduate','label'=>'Students'],['url'=>'instructors.php','icon'=>'fas fa-chalkboard-teacher','label'=>'Instructors'],['url'=>'vehicles.php','icon'=>'fas fa-car','label'=>'Vehicles'],['url'=>'classes.php','icon'=>'fas fa-calendar-alt','label'=>'Classes'],['url'=>'lessons.php','icon'=>'fas fa-road','label'=>'Lessons'],['url'=>'tests.php','icon'=>'fas fa-clipboard-check','label'=>'Tests'],['url'=>'licenses.php','icon'=>'fas fa-id-card','label'=>'Licenses'],['url'=>'schedule.php','icon'=>'fas fa-calendar-week','label'=>'Schedule'],['url'=>'payments.php','icon'=>'fas fa-money-bill','label'=>'Payments'],['url'=>'certificates.php','icon'=>'fas fa-certificate','label'=>'Certificates'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];
