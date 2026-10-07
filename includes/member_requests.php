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
