-- ============================================================
-- Register Courier Management module in the modules table
-- Run this on your live database if schema.sql has already
-- been applied (i.e. the platform is already deployed).
-- ============================================================

INSERT IGNORE INTO modules
    (slug, name, description, icon, color, category, monthly_price, annual_price, sort_order)
VALUES
    ('courier', 'Courier Management',
     'Parcel tracking, delivery agents, payments, agreements & route management',
     'fas fa-shipping-fast', '#1565c0', 'Logistics', 3000, 30000, 21);
