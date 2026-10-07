<?php
// includes/member_requests.php
// Shared by /member/dashboard and /member/request.
// Applicants never see internal states ("Recharge Failed", "Payment Failed" go back to reviewers),
// so they are shown as plain "In review".

/** [label, colour, needs-action] for a request status, as the member sees it. */
function dash_status(string $type, string $status): array
{
    $review = ['In review', '#106b9a', false];
    $map = $type === 'internet'
        ? [
            'Submitted'            => $review,
            'Info Requested'       => ['Reply needed', '#b45309', true],
            'Approved for Support' => ['Approved', '#0f766e', false],
            'Recharge Failed'      => $review,
            'Awaiting Receipt'     => ['Upload your receipt', '#b45309', true],
            'Receipt Submitted'    => ['Receipt being checked', '#106b9a', false],
            'Closed'               => ['Completed', '#15803d', false],
            'Rejected'             => ['Not approved', '#97161b', false],
        ]
        : [
            'Submitted'            => $review,
            'Under Review'         => $review,
            'Info Requested'       => ['Reply needed', '#b45309', true],
            'Approved for Payment' => ['Approved', '#0f766e', false],
            'Payment Failed'       => $review,
            'Paid'                 => ['Paid', '#15803d', false],
            'Rejected'             => ['Not approved', '#97161b', false],
        ];
    return $map[$status] ?? $review;
}

function dash_date(?string $utc): string
{
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y');
}

function dash_datetime(?string $utc): string
{
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i A') . ' IST';
}

/** Path to one request's page on the member side. */
function member_request_url(string $trackingId): string
{
    return '/member/request?id=' . rawurlencode($trackingId);
}

/** 25000 -> "250", 25050 -> "250.50" (paise in, rupees out, no symbol). */
function dash_money(int $paise): string
{
    return number_format($paise / 100, $paise % 100 ? 2 : 0);
}

/**
 * Amount lines for the member's request page: a list of [label, "₹x", reason] rows.
 *
 *  - The first row is always what the member asked for.
 *  - "Approved amount" is added once approval has happened, and only if it differs from the request.
 *  - The paid / recharged row is added once money has moved, always (it confirms the figure).
 *  - A reason is shown only against a figure that actually differs from the one before it.
 *
 * The reasons are the ones staff wrote FOR the applicant (approved_amount_note / paid_amount_note),
 * never internal notes.
 */
function member_amount_rows(
    string $requestedLabel, int $requested, int $approved, ?int $paid,
    ?string $approvedNote, ?string $paidNote,
    bool $approvalDone, bool $moneyMoved, string $paidLabel
): array {
    $fmt  = fn(int $p) => '₹' . number_format($p / 100, 2);
    $rows = [[$requestedLabel, $fmt($requested), '']];
    if ($approvalDone && $approved !== $requested) {
        $rows[] = ['Approved amount', $fmt($approved), trim((string) $approvedNote)];
    }
    if ($moneyMoved && $paid !== null) {
        $base   = $approvalDone ? $approved : $requested;
        $rows[] = [$paidLabel, $fmt($paid), $paid !== $base ? trim((string) $paidNote) : ''];
    }
    return $rows;
}
