<?php
/**
 * modules/sales/_nav.php
 * Shared navigation definition for all sales module pages.
 * Include BEFORE header-module.php in every sales page.
 */
$moduleSlug  = 'sales';
$moduleName  = 'Sales Management';
$moduleIcon  = 'fas fa-chart-line';
$moduleColor = '#1A8A4E';

$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];
