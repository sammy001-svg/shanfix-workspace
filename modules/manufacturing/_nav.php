<?php
/**
 * modules/manufacturing/_nav.php
 * Shared navigation definition for all manufacturing module pages.
 * Include BEFORE header-module.php in every manufacturing page.
 */
$moduleSlug  = 'manufacturing';
$moduleName  = 'Manufacturing';
$moduleIcon  = 'fas fa-industry';
$moduleColor = '#d35400';

$moduleNav   = [
    ['url' => 'index.php',       'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'products.php',    'icon' => 'fas fa-box',            'label' => 'Products'],
    ['url' => 'materials.php',   'icon' => 'fas fa-cubes',          'label' => 'Raw Materials'],
    ['url' => 'bom.php',         'icon' => 'fas fa-list-alt',       'label' => 'Bill of Materials'],
    ['url' => 'production.php',  'icon' => 'fas fa-industry',       'label' => 'Production Orders'],
    ['url' => 'workorders.php',  'icon' => 'fas fa-clipboard-list', 'label' => 'Work Orders'],
    ['url' => 'machines.php',    'icon' => 'fas fa-cogs',           'label' => 'Machines'],
    ['url' => 'quality.php',     'icon' => 'fas fa-check-circle',   'label' => 'Quality Control'],
    ['url' => 'suppliers.php',   'icon' => 'fas fa-truck',           'label' => 'Suppliers'],
    ['url' => 'inventory.php',   'icon' => 'fas fa-warehouse',       'label' => 'Inventory'],
    ['url' => 'procurement.php', 'icon' => 'fas fa-shopping-basket', 'label' => 'Procurement'],
    ['url' => 'reports.php',     'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
