<?php
/**
 * modules/tour/_lib.php
 * Shared helpers for the Tour & Travel module (Phase 2).
 * Safe to include more than once; every function is guarded.
 */

if (!function_exists('tourSettings')) {

    /** Load every setting for an org as key => value. */
    function tourSettings(int $orgId): array
    {
        global $pdo;
        static $cache = [];
        if (isset($cache[$orgId])) return $cache[$orgId];

        $rows = [];
        try {
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM tour_settings WHERE org_id=?");
            $stmt->execute([$orgId]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {}

        return $cache[$orgId] = ($rows ?: []);
    }

    /** Read one setting, falling back to $default when unset or blank. */
    function tourSetting(int $orgId, string $key, string $default = ''): string
    {
        $all = tourSettings($orgId);
        $val = $all[$key] ?? '';
        return ($val === '' || $val === null) ? $default : (string)$val;
    }

    /** Upsert one setting. Clears the request-level cache. */
    function saveTourSetting(int $orgId, string $key, string $value): void
    {
        global $pdo;
        try {
            $pdo->prepare(
                "INSERT INTO tour_settings (org_id, setting_key, setting_value) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            )->execute([$orgId, $key, $value]);
        } catch (Throwable $e) {}
    }

    /** Defaults for every setting the module reads. */
    function tourSettingDefaults(): array
    {
        return [
            't_company_name'     => '',
            't_tagline'          => '',
            't_contact_phone'    => '',
            't_contact_email'    => '',
            't_emergency_phone'  => '',
            't_quote_prefix'     => 'QT',
            't_invoice_prefix'   => 'INV',
            't_departure_prefix' => 'DEP',
            't_quote_validity'   => '14',
            't_invoice_due_days' => '7',
            't_deposit_percent'  => '30',
            't_tax_label'        => 'VAT',
            't_tax_rate'         => '0',
            't_quote_terms'      => 'Prices are per person and subject to availability at time of confirmation.',
            't_invoice_terms'    => 'Full payment is required before travel. Bank details available on request.',
            't_portal_enabled'   => '1',
            't_portal_welcome'   => 'Track your trip, view your day-by-day itinerary and check your balance.',
        ];
    }

    /** Read a setting using the module-wide default table. */
    function tourConf(int $orgId, string $key): string
    {
        $defaults = tourSettingDefaults();
        return tourSetting($orgId, $key, $defaults[$key] ?? '');
    }

    /**
     * Generate the next document number for an org, e.g. QT-2026-0007.
     * Reads the highest existing sequence for the current year and adds one.
     */
    function tourNextNumber(int $orgId, string $table, string $column, string $prefix): string
    {
        global $pdo;
        $allowed = [
            'tour_quotations' => 'quote_no',
            'tour_invoices'   => 'invoice_no',
            'tour_departures' => 'departure_code',
        ];
        if (($allowed[$table] ?? null) !== $column) {
            // Never interpolate caller-supplied identifiers into SQL.
            return strtoupper($prefix) . '-' . date('Y') . '-0001';
        }

        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'DOC');
        $stem   = $prefix . '-' . date('Y') . '-';
        $next   = 1;

        try {
            $stmt = $pdo->prepare(
                "SELECT `$column` FROM `$table`
                 WHERE org_id = ? AND `$column` LIKE ?
                 ORDER BY LENGTH(`$column`) DESC, `$column` DESC LIMIT 1"
            );
            $stmt->execute([$orgId, $stem . '%']);
            $last = (string)$stmt->fetchColumn();
            if ($last !== '') {
                $next = (int)substr($last, strlen($stem)) + 1;
            }
        } catch (Throwable $e) {}

        return $stem . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    }

    /** Seats already committed on a departure (cancelled bookings excluded). */
    function tourSeatsBooked(int $orgId, int $departureId): int
    {
        global $pdo;
        if ($departureId <= 0) return 0;
        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(adults + children),0) FROM tour_bookings
                 WHERE org_id=? AND departure_id=? AND status <> 'cancelled'"
            );
            $stmt->execute([$orgId, $departureId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Live seat position for a departure.
     * @return array{total:int,booked:int,available:int,percent:int}
     */
    function tourSeatStatus(int $orgId, int $departureId, int $seatsTotal): array
    {
        $booked    = tourSeatsBooked($orgId, $departureId);
        $available = max(0, $seatsTotal - $booked);
        $percent   = $seatsTotal > 0 ? (int)round(($booked / $seatsTotal) * 100) : 0;
        return ['total' => $seatsTotal, 'booked' => $booked, 'available' => $available, 'percent' => min(100, $percent)];
    }

    /** Total cost booked against a trip: supplier bookings + logged expenses. */
    function tourTripCost(int $orgId, ?int $bookingId = null, ?int $departureId = null): float
    {
        global $pdo;
        $cost = 0.0;
        try {
            if ($bookingId) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM tour_supplier_bookings WHERE org_id=? AND booking_id=? AND status <> 'cancelled'");
                $stmt->execute([$orgId, $bookingId]);
                $cost += (float)$stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_expenses WHERE org_id=? AND booking_id=?");
                $stmt->execute([$orgId, $bookingId]);
                $cost += (float)$stmt->fetchColumn();
            }
            if ($departureId) {
                $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM tour_supplier_bookings WHERE org_id=? AND departure_id=? AND booking_id IS NULL AND status <> 'cancelled'");
                $stmt->execute([$orgId, $departureId]);
                $cost += (float)$stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_expenses WHERE org_id=? AND departure_id=? AND booking_id IS NULL");
                $stmt->execute([$orgId, $departureId]);
                $cost += (float)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {}
        return $cost;
    }

    /** Sum of receipted payments against a booking (refunds subtracted). */
    function tourBookingPaid(int $orgId, int $bookingId): float
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN payment_type='refund' THEN -amount ELSE amount END),0)
                 FROM tour_payments WHERE org_id=? AND booking_id=?"
            );
            $stmt->execute([$orgId, $bookingId]);
            return (float)$stmt->fetchColumn();
        } catch (Throwable $e) { return 0.0; }
    }

    /** Recompute an invoice's paid amount + status from its booking's payments. */
    function tourSyncInvoice(int $orgId, int $invoiceId): void
    {
        global $pdo;
        try {
            $stmt = $pdo->prepare("SELECT * FROM tour_invoices WHERE id=? AND org_id=? LIMIT 1");
            $stmt->execute([$invoiceId, $orgId]);
            $inv = $stmt->fetch();
            if (!$inv || in_array($inv['status'], ['cancelled', 'draft'], true)) return;

            $paid = $inv['booking_id'] ? tourBookingPaid($orgId, (int)$inv['booking_id']) : (float)$inv['amount_paid'];
            $total = (float)$inv['total_amount'];

            if ($paid >= $total && $total > 0)      $status = 'paid';
            elseif ($paid > 0)                      $status = 'partial';
            elseif (!empty($inv['due_date']) && $inv['due_date'] < date('Y-m-d')) $status = 'overdue';
            else                                    $status = 'sent';

            $pdo->prepare("UPDATE tour_invoices SET amount_paid=?, status=?, updated_at=NOW() WHERE id=? AND org_id=?")
                ->execute([$paid, $status, $invoiceId, $orgId]);
        } catch (Throwable $e) {}
    }

    /** Human label for supplier / service / expense enums. */
    function tourLabel(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
