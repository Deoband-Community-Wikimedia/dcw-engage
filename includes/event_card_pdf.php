<?php
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/app_log.php';
require_once __DIR__ . '/event_card_template.php';
require_once __DIR__ . '/../models/EventCardModel.php';
require_once __DIR__ . '/../models/MemberCardModel.php';

/**
 * Decode a logo data URI into [bytes, width/height ratio]; [null, 0.0] when there is no usable logo.
 * Same idea as member_card_logo_prepare(): a PNG's ratio comes from its pixels, an SVG's from its viewBox.
 * (The DCW logo is assets/chapters/generic.svg, so the SVG path is the one used for it. mPDF's SVG support
 * is partial, and the membership cards pass the same file to mPDF as it is.)
 */
function event_card_logo_prepare(?string $uri): array
{
    if ($uri === null || $uri === '' || strpos($uri, ',') === false) {
        return [null, 0.0];
    }
    $bytes = base64_decode(substr($uri, strpos($uri, ',') + 1));
    if ($bytes === false || $bytes === '') {
        return [null, 0.0];
    }
    $ratio = 0.0;
    if (strncmp($bytes, "\x89PNG", 4) === 0) {
        $size = @getimagesizefromstring($bytes);
        if ($size && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
        }
    } elseif (preg_match('/viewBox\s*=\s*"[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $bytes, $m) && (float) $m[2] > 0) {
        $ratio = (float) $m[1] / (float) $m[2];
    }
    return [$bytes, $ratio];
}

/**
 * Build the print file for an event and stream it as a download: for each person a front page, then a back
 * page, at the exact card size (EVENT_CARD_W_MM x EVENT_CARD_H_MM), no margins. Print double-sided, flipping
 * on the long edge. Nothing is saved on the server.
 *
 * $event is a card_events row; $people is a list of card_participants rows (all of them, or just one).
 * Throws on failure; the caller decides what to show.
 */
function event_cards_pdf_send(array $event, array $people): void
{
    if (!$people) {
        throw new InvalidArgumentException('There are no cards to print.');
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    $mpdf = new \Mpdf\Mpdf([
        'format'        => [EVENT_CARD_W_MM, EVENT_CARD_H_MM],
        'margin_left'   => 0, 'margin_right'  => 0,
        'margin_top'    => 0, 'margin_bottom' => 0,
        'margin_header' => 0, 'margin_footer' => 0,
    ]);
    $mpdf->SetTitle(trim($event['name'] . ' ' . $event['edition']) . ' - cards');

    // Logos are handed to mPDF as bytes (src="var:name"), once for the whole file.
    $evRef = '';
    [$evBytes, $evRatio] = event_card_logo_prepare(EventCardModel::logoDataUri($event['logo_key'] ?? null));
    if ($evBytes !== null) {
        $mpdf->imageVars['evlogo'] = $evBytes;
        $evRef = 'var:evlogo';
    }
    $dcwRef = '';
    [$dcwBytes, $dcwRatio] = event_card_logo_prepare(MemberCardModel::logoDataUri('generic'));
    if ($dcwBytes !== null) {
        $mpdf->imageVars['dcw'] = $dcwBytes;
        $dcwRef = 'var:dcw';
    }

    $html = '';
    $last = count($people) - 1;
    foreach (array_values($people) as $i => $p) {
        // A QR image per person, under its own name, so mPDF cannot reuse a cached one for the next card.
        $qrName = 'qr' . (int) $p['id'];
        $mpdf->imageVars[$qrName] = QrCode::png(EventCardModel::url((string) $p['token']), 10);

        $c = [
            'name'             => (string) $p['name'],
            'role'             => (string) $p['role'],
            'role_color'       => EventCardModel::ROLES[$p['role']] ?? '#1b3f66',
            'affiliation'      => (string) $p['affiliation'],
            'event_name'       => (string) $event['name'],
            'edition'          => (string) $event['edition'],
            'event_logo'       => $evRef,
            'event_logo_ratio' => $evRatio,
            'dcw_logo'         => $dcwRef,
            'dcw_logo_ratio'   => $dcwRatio,
            'qr'               => 'var:' . $qrName,
        ];
        $html .= event_card_front_html($c) . '<pagebreak />' . event_card_back_html($c);
        if ($i !== $last) {
            $html .= '<pagebreak />';
        }
    }
    $mpdf->WriteHTML($html);

    $filename = 'Event-Cards-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($event['name'] . '-' . $event['edition'], '-')) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
