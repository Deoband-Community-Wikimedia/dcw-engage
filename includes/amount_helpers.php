<?php
// includes/amount_helpers.php
// Small helpers for amounts typed by staff. Amounts are stored as whole paise.

if (!function_exists('rupees_to_paise')) {
    /** "1,250.50" or "₹250" -> paise, or null if it isn't a positive amount (max 7 digits, 2 decimals). */
    function rupees_to_paise($raw): ?int {
        $raw = str_replace([',', ' ', '₹'], '', trim((string) $raw));
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw)) {
            return null;
        }
        $paise = (int) round(((float) $raw) * 100);
        return $paise > 0 ? $paise : null;
    }
}

if (!function_exists('paise_to_rupees')) {
    /** 25050 -> "250.50" (no symbol, no thousands separator: safe for prefilling an input). */
    function paise_to_rupees($paise): string {
        return number_format(((int) $paise) / 100, 2, '.', '');
    }
}

if (!function_exists('rupees_label')) {
    /** 25050 -> "₹250.50" for display. */
    function rupees_label($paise): string {
        return '₹' . number_format(((int) $paise) / 100, 2);
    }
}
