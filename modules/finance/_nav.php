<?php
/**
 * modules/finance/_nav.php
 * Shared navigation definition for all finance module pages.
 * Include BEFORE header-module.php in every finance page.
 */
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';

$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',   'label' => 'Dashboard'],
    ['url' => 'income.php',         'icon' => 'fas fa-arrow-circle-down','label'=> 'Income'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-arrow-circle-up',  'label'=> 'Expenses'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',       'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',     'label' => 'All Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',             'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',         'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',             'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',     'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',         'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',        'label' => 'Reports'],];
