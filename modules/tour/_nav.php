<?php
/**
 * modules/tour/_nav.php
 * Shared navigation definition for all Tour & Travel module pages.
 * Include BEFORE header-module.php in every tour page.
 */
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';

$moduleNav = [
    ['url' => 'index.php', 'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],

    // ── Product ──────────────────────────────────────────────────
    ['divider' => true, 'label' => 'Product'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'itineraries.php',  'icon' => 'fas fa-route',          'label' => 'Itineraries'],
    ['url' => 'departures.php',   'icon' => 'fas fa-plane-departure','label' => 'Departures'],

    // ── Sales ────────────────────────────────────────────────────
    ['divider' => true, 'label' => 'Sales'],
    ['url' => 'quotations.php', 'icon' => 'fas fa-file-signature',  'label' => 'Quotations'],
    ['url' => 'bookings.php',   'icon' => 'fas fa-calendar-check',  'label' => 'Bookings'],
    ['url' => 'invoices.php',   'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',   'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'customers.php',  'icon' => 'fas fa-user-friends',    'label' => 'Customers'],

    // ── Operations ───────────────────────────────────────────────
    ['divider' => true, 'label' => 'Operations'],
    ['url' => 'guides.php',    'icon' => 'fas fa-hiking',    'label' => 'Guides'],
    ['url' => 'vehicles.php',  'icon' => 'fas fa-bus',       'label' => 'Vehicles'],
    ['url' => 'suppliers.php', 'icon' => 'fas fa-handshake', 'label' => 'Suppliers'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-receipt',   'label' => 'Trip Costs'],

    // ── Insights & Admin ─────────────────────────────────────────
    ['divider' => true, 'label' => 'Insights'],
    ['url' => 'reports.php',  'icon' => 'fas fa-chart-bar', 'label' => 'Reports'],
    ['url' => 'portals.php',  'icon' => 'fas fa-id-card',   'label' => 'Traveller Portal'],
    ['url' => 'settings.php', 'icon' => 'fas fa-cog',       'label' => 'Settings'],
];
