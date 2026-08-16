<?php
/**
 * modules/hrm/_nav.php
 * Shared navigation definition for all hrm module pages.
 * Include BEFORE header-module.php in every hrm page.
 */
$moduleSlug  = 'hrm';
$moduleName  = 'HRM System';
$moduleIcon  = 'fas fa-users-cog';
$moduleColor = '#2c3e50';

$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'employees.php',    'icon' => 'fas fa-id-badge',           'label' => 'Employees'],
    ['url' => 'departments.php',  'icon' => 'fas fa-sitemap',            'label' => 'Departments'],
    ['url' => 'payroll.php',      'icon' => 'fas fa-money-check',        'label' => 'Payroll'],
    ['url' => 'leave.php',        'icon' => 'fas fa-calendar-minus',     'label' => 'Leave'],
    ['url' => 'attendance.php',   'icon' => 'fas fa-fingerprint',        'label' => 'Attendance'],
    ['url' => 'benefits.php',     'icon' => 'fas fa-gift',               'label' => 'Benefits'],
    ['url' => 'disciplinary.php', 'icon' => 'fas fa-gavel',              'label' => 'Disciplinary'],
    ['url' => 'recruitment.php',  'icon' => 'fas fa-user-plus',          'label' => 'Recruitment'],
    ['url' => 'performance.php',  'icon' => 'fas fa-star',               'label' => 'Performance'],
    ['url' => 'training.php',     'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Training'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],];
