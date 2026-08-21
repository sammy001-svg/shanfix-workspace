<?php
/**
 * modules/sacco/_nav.php
 * Shared navigation definition for all sacco module pages.
 * Include BEFORE header-module.php in every sacco page.
 */
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';

$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',      'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',               'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',          'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',    'label' => 'Loans'],
    ['url' => 'schedule.php',     'icon' => 'fas fa-calendar-alt',        'label' => 'Schedules'],
    ['url' => 'arrears.php',      'icon' => 'fas fa-exclamation-triangle','label' => 'Arrears'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',         'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',                'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',          'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',        'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',         'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle',  'label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',             'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',           'label' => 'Reports'],
];
