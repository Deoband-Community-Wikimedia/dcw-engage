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
 * The jali frame in one role's colour, as PNG bytes; '' when it cannot be made (the card then gets a plain
 * border in the role colour, so printing never fails because of the artwork).
 *
 * assets/event-cards/jali-frame.png (built by tools/build_jali_frame.py) is an 8-bit indexed PNG whose palette
 * is a grey ramp: 0 = darkest stone ... 254 = lightest hole, 255 = the white panel. Re-colouring is just
 * rewriting the 256 palette entries, so there is no per-pixel work. One result per colour per request.
 */
function event_card_frame_png(string $colour): string
{
    static $cache = [];
    $hex = event_card_hex($colour);
    if (isset($cache[$hex])) {
        return $cache[$hex];
    }
    $cache[$hex] = '';

    $path = __DIR__ . '/../assets/event-cards/jali-frame.png';
    if (!is_file($path) || !function_exists('imagecreatefrompng')) {
        return '';
    }
    $im = @imagecreatefrompng($path);
    if (!$im || imageistruecolor($im) || imagecolorstotal($im) < 256) {
        return '';
    }
    [$r, $g, $b] = sscanf($hex, '%2x%2x%2x');
    $dark  = [$r * 0.82, $g * 0.82, $b * 0.82];                                  // stone
    $light = [$r * 0.10 + 229.5, $g * 0.10 + 229.5, $b * 0.10 + 229.5];          // holes: a faint wash of the colour
    for ($i = 0; $i < 255; $i++) {
        $t = $i / 254;
        imagecolorset($im, $i,
            (int) round($dark[0] + ($light[0] - $dark[0]) * $t),
            (int) round($dark[1] + ($light[1] - $dark[1]) * $t),
            (int) round($dark[2] + ($light[2] - $dark[2]) * $t));
    }
    imagecolorset($im, 255, 255, 255, 255);                                       // the panel stays white

    ob_start();
    imagepng($im, null, 9);
    return $cache[$hex] = (string) ob_get_clean();
}

/** What the template needs about one person and one event. The affiliation is resolved here (organisers and volunteers default). */
function event_card_fields(array $event, array $p): array
{
    $role = (string) ($p['role'] ?? '');
    return [
        'name'        => trim((string) ($p['name'] ?? '')),
        'role'        => $role,
        'role_color'  => EventCardModel::ROLES[$role] ?? '#1b3f66',
        'affiliation' => event_card_affiliation($role, (string) ($p['affiliation'] ?? '')),
        'event_name'  => (string) $event['name'],
        'edition'     => (string) $event['edition'],
    ];
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
        $c = event_card_fields($event, $p) + [
            'event_logo'       => $evRef,
            'event_logo_ratio' => $evRatio,
            'dcw_logo'         => $dcwRef,
            'dcw_logo_ratio'   => $dcwRatio,
        ];

        // One tinted frame per role colour, shared by every card of that role.
        $frameName = 'jali' . event_card_hex($c['role_color']);
        if (!isset($mpdf->imageVars[$frameName])) {
            $png = event_card_frame_png($c['role_color']);
            if ($png !== '') {
                $mpdf->imageVars[$frameName] = $png;
            }
        }
        $c['frame'] = isset($mpdf->imageVars[$frameName]) ? 'var:' . $frameName : '';

        // A QR image per person, under its own name, so mPDF cannot reuse a cached one for the next card.
        $qrName = 'qr' . (int) $p['id'];
        $mpdf->imageVars[$qrName] = QrCode::png(EventCardModel::url((string) $p['token']), 10);
        $c['qr'] = 'var:' . $qrName;

        $html .= event_card_front_html($c) . '<pagebreak />' . event_card_back_html($c);
        if ($i !== $last) {
            $html .= '<pagebreak />';
        }
    }
    $mpdf->WriteHTML($html);

    $filename = 'Event-Cards-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($event['name'] . '-' . $event['edition'], '-')) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}

/**
 * One card side as a standalone HTML page for the in-page preview (shown in an iframe). It uses the same
 * template as the PDF, with data: URIs in place of mPDF's "var:" images, scaled to fit whatever frame holds it.
 *
 * $p may be a saved participant row or a draft (name, role, affiliation); a draft without a token gets a sample QR.
 * $side is 'front' or 'back'.
 */
function event_card_preview_html(array $event, array $p, string $side): string
{
    $png = static fn(string $bytes): string => 'data:image/png;base64,' . base64_encode($bytes);

    [, $evRatio]  = event_card_logo_prepare(EventCardModel::logoDataUri($event['logo_key'] ?? null));
    [, $dcwRatio] = event_card_logo_prepare(MemberCardModel::logoDataUri('generic'));

    $c = event_card_fields($event, $p);
    if ($c['name'] === '') {
        $c['name'] = 'Full Name';
    }
    $frame = event_card_frame_png($c['role_color']);
    $c += [
        'event_logo'       => (string) EventCardModel::logoDataUri($event['logo_key'] ?? null),
        'event_logo_ratio' => $evRatio,
        'dcw_logo'         => (string) MemberCardModel::logoDataUri('generic'),
        'dcw_logo_ratio'   => $dcwRatio,
        'frame'            => $frame !== '' ? $png($frame) : '',
        'qr'               => $png(QrCode::png(EventCardModel::url((string) ($p['token'] ?? 'preview-sample')), 10)),
        'guides'           => true,
    ];
    $card = $side === 'back' ? event_card_back_html($c) : event_card_front_html($c);

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<title>Card preview</title><style>'
         . 'html,body{margin:0;height:100%;background:transparent}'
         . '#st{margin:8px auto}'
         . '#c{font-family:"DejaVu Sans",Verdana,"Segoe UI",sans-serif;position:relative;width:' . EVENT_CARD_W_MM . 'mm;height:' . EVENT_CARD_H_MM . 'mm;background:#fff;overflow:hidden;transform-origin:0 0;'
         . 'box-shadow:0 1px 3px rgba(15,23,42,.25),0 10px 28px rgba(15,23,42,.18)}'
         . '#c table{border-collapse:separate;border-spacing:0}#c td{padding:0}'
         . '</style></head><body><div id="st"><div id="c">' . $card . '</div></div>'
         . '<script>(function(){var c=document.getElementById("c"),s=document.getElementById("st");'
         . 'function fit(){var w=c.offsetWidth,h=c.offsetHeight,k=Math.min((innerWidth-16)/w,(innerHeight-16)/h);'
         . 'c.style.transform="scale("+k+")";s.style.width=w*k+"px";s.style.height=h*k+"px"}'
         . 'fit();addEventListener("resize",fit)})();</script></body></html>';
}
