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
    // ── Overview ─────────────────────────────────────────────────
    ['url' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],

    // ── People ───────────────────────────────────────────────────
    ['divider' => true, 'label' => 'People'],
    ['url' => 'students.php',    'icon' => 'fas fa-user-graduate',      'label' => 'Students'],
    ['url' => 'parents.php',     'icon' => 'fas fa-users',              'label' => 'Parents'],
    ['url' => 'teachers.php',    'icon' => 'fas fa-chalkboard-teacher', 'label' => 'Teachers'],
    ['url' => 'alumni.php',      'icon' => 'fas fa-user-graduate',      'label' => 'Alumni'],

    // ── Academic ─────────────────────────────────────────────────
    ['divider' => true, 'label' => 'Academic'],
    ['url' => 'academic.php',         'icon' => 'fas fa-calendar-check',     'label' => 'Academic Year'],
    ['url' => 'classes.php',          'icon' => 'fas fa-chalkboard',         'label' => 'Classes'],
    ['url' => 'subjects.php',         'icon' => 'fas fa-book',               'label' => 'Subjects'],
    ['url' => 'grades.php',           'icon' => 'fas fa-star',               'label' => 'Grading'],
    ['url' => 'subject-teachers.php', 'icon' => 'fas fa-user-edit',          'label' => 'Assignments'],
    ['url' => 'timetable.php',        'icon' => 'fas fa-clock',              'label' => 'Timetable'],
    ['url' => 'staff.php',            'icon' => 'fas fa-book-open',          'label' => 'Curriculum'],

    // ── Learning & Assessment ────────────────────────────────────
    ['divider' => true, 'label' => 'Learning'],
    ['url' => 'attendance.php', 'icon' => 'fas fa-clipboard-check', 'label' => 'Attendance'],
    ['url' => 'homework.php',   'icon' => 'fas fa-book-open',       'label' => 'Homework'],
    ['url' => 'exams.php',      'icon' => 'fas fa-file-alt',        'label' => 'Exams'],
    ['url' => 'results.php',    'icon' => 'fas fa-chart-line',      'label' => 'Results'],
    ['url' => 'promotion.php',  'icon' => 'fas fa-arrow-up',        'label' => 'Promotion'],

    // ── Finance ──────────────────────────────────────────────────
    ['divider' => true, 'label' => 'Finance'],
    ['url' => 'fees.php',          'icon' => 'fas fa-money-bill-wave', 'label' => 'Fees'],
    ['url' => 'fee-statement.php', 'icon' => 'fas fa-file-invoice',    'label' => 'Fee Statements'],
    ['url' => 'budget.php',        'icon' => 'fas fa-chart-pie',       'label' => 'Budget'],

    // ── Facilities & Welfare ──────────────────────────────────────
    ['divider' => true, 'label' => 'Facilities'],
    ['url' => 'library.php',    'icon' => 'fas fa-book-reader', 'label' => 'Library'],
    ['url' => 'transport.php',  'icon' => 'fas fa-bus',         'label' => 'Transport'],
    ['url' => 'hostel.php',     'icon' => 'fas fa-bed',         'label' => 'Hostel'],
    ['url' => 'discipline.php', 'icon' => 'fas fa-gavel',       'label' => 'Discipline'],

    // ── Communication ────────────────────────────────────────────
    ['divider' => true, 'label' => 'Communication'],
    ['url' => 'events.php',        'icon' => 'fas fa-calendar-day',    'label' => 'Events'],
    ['url' => 'notices.php',       'icon' => 'fas fa-bullhorn',        'label' => 'Notices'],
    ['url' => 'communication.php', 'icon' => 'fas fa-broadcast-tower', 'label' => 'Communicate'],

    // ── Administration ───────────────────────────────────────────
    ['divider' => true, 'label' => 'Administration'],
    ['url' => 'id-cards.php', 'icon' => 'fas fa-id-card',  'label' => 'ID Cards'],
    ['url' => 'reports.php',  'icon' => 'fas fa-chart-bar','label' => 'Reports'],
    ['url' => 'portals.php',  'icon' => 'fas fa-key',       'label' => 'Portal Access'],
    ['url' => 'settings.php', 'icon' => 'fas fa-cog',       'label' => 'Settings'],
];
