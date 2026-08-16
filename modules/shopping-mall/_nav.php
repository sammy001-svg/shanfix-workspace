<?php
/**
 * modules/shopping-mall/_nav.php
 * Shared navigation definition for all shopping-mall module pages.
 * Include BEFORE header-module.php in every shopping-mall page.
 */
$moduleSlug  = 'shopping-mall';
$moduleName  = 'Shopping Mall';
$moduleIcon  = 'fas fa-building';
$moduleColor = '#8e44ad';

$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'floors.php',         'icon' => 'fas fa-layer-group',    'label' => 'Floors'],
    ['url' => 'shops.php',          'icon' => 'fas fa-store',          'label' => 'Shops'],
    ['url' => 'tenants.php',        'icon' => 'fas fa-user-tie',       'label' => 'Tenants'],
    ['url' => 'leases.php',         'icon' => 'fas fa-file-contract',  'label' => 'Leases'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-check',    'label' => 'Rent Payments'],
    ['url' => 'service-charges.php','icon' => 'fas fa-file-invoice',   'label' => 'Service Charges'],
    ['url' => 'notices.php',        'icon' => 'fas fa-bullhorn',       'label' => 'Notices'],
    ['url' => 'maintenance.php',    'icon' => 'fas fa-tools',          'label' => 'Maintenance'],
    ['url' => 'utilities.php',      'icon' => 'fas fa-bolt',           'label' => 'Utilities'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],];
