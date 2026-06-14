<?php
/**
 * modules/school/_nav.php
 * Shared navigation definition for all school module pages.
 * Include BEFORE header-module.php in every school page.
 */
$moduleSlug  = 'school';
$moduleName  = 'International School';
$moduleIcon  = 'fas fa-school';
$moduleColor = '#1A8A4E';

$moduleNav = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt',    'label' => 'Dashboard'],
    ['url' => 'students.php',    'icon' => 'fas fa-user-graduate',     'label' => 'Students'],
    ['url' => 'parents.php',     'icon' => 'fas fa-users',             'label' => 'Parents'],
    ['url' => 'teachers.php',    'icon' => 'fas fa-chalkboard-teacher','label' => 'Teachers'],
    ['url' => 'academic.php',    'icon' => 'fas fa-calendar-check',    'label' => 'Academic'],
    ['url' => 'classes.php',     'icon' => 'fas fa-chalkboard',        'label' => 'Classes'],
    ['url' => 'subjects.php',    'icon' => 'fas fa-book',              'label' => 'Subjects'],
    ['url' => 'timetable.php',   'icon' => 'fas fa-clock',             'label' => 'Timetable'],
    ['url' => 'attendance.php',  'icon' => 'fas fa-clipboard-check',   'label' => 'Attendance'],
    ['url' => 'exams.php',       'icon' => 'fas fa-file-alt',          'label' => 'Exams'],
    ['url' => 'results.php',     'icon' => 'fas fa-chart-line',        'label' => 'Results'],
    ['url' => 'fees.php',        'icon' => 'fas fa-money-bill-wave',   'label' => 'Fees'],
    ['url' => 'library.php',     'icon' => 'fas fa-book-reader',       'label' => 'Library'],
    ['url' => 'transport.php',   'icon' => 'fas fa-bus',               'label' => 'Transport'],
    ['url' => 'hostel.php',      'icon' => 'fas fa-bed',               'label' => 'Hostel'],
    ['url' => 'discipline.php',  'icon' => 'fas fa-gavel',             'label' => 'Discipline'],
    ['url' => 'homework.php',    'icon' => 'fas fa-book-open',         'label' => 'Homework'],
    ['url' => 'promotion.php',   'icon' => 'fas fa-arrow-up',          'label' => 'Promotion'],
    ['url' => 'id-cards.php',    'icon' => 'fas fa-id-card',           'label' => 'ID Cards'],
    ['url' => 'events.php',      'icon' => 'fas fa-calendar-day',      'label' => 'Events'],
    ['url' => 'notices.php',     'icon' => 'fas fa-bullhorn',          'label' => 'Notices'],
    ['url' => 'grades.php',           'icon' => 'fas fa-star',                'label' => 'Grading'],
    ['url' => 'subject-teachers.php', 'icon' => 'fas fa-chalkboard-teacher',  'label' => 'Assignments'],
    ['url' => 'communication.php',    'icon' => 'fas fa-broadcast-tower',     'label' => 'Communicate'],
    ['url' => 'alumni.php',           'icon' => 'fas fa-user-graduate',       'label' => 'Alumni'],
    ['url' => 'fee-statement.php',    'icon' => 'fas fa-file-invoice',        'label' => 'Fee Statements'],
    ['url' => 'budget.php',           'icon' => 'fas fa-chart-pie',           'label' => 'Budget'],
    ['url' => 'reports.php',          'icon' => 'fas fa-chart-bar',           'label' => 'Reports'],
    ['url' => 'staff.php',            'icon' => 'fas fa-user-tie',            'label' => 'Curriculum'],
    ['url' => 'portals.php',          'icon' => 'fas fa-key',                 'label' => 'Portal Access'],
    ['url' => 'settings.php',         'icon' => 'fas fa-cog',                 'label' => 'Settings'],
];
