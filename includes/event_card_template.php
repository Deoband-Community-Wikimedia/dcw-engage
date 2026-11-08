<?php
require_once __DIR__ . '/member_card_template.php';   // member_card_theme(): the DCW colours
require_once __DIR__ . '/../models/EventCardModel.php';

/**
 * DCW Engage - event participation card (front and back), as HTML for mPDF and for the in-page preview.
 *
 * Portrait, A6 by default. A band of jali lattice (assets/event-cards/jali-frame.png, tinted with the person's
 * role colour at print time) runs round the edge, the lanyard slot goes through its top, and everything else
 * sits on a white panel inside it. The three DCW colours (member_card_theme('generic')) are the thin rules.
 *
 * Everything is placed with position:absolute in millimetres, so the card is exactly EVENT_CARD_W_MM x
 * EVENT_CARD_H_MM whatever the content. The same markup is used for the PDF (mPDF) and the preview (browser);
 * the only difference is what the image sources are: "var:name" for mPDF, data: URIs for the browser.
 *
 *   FRONT   0-8 mm        jali band (lanyard slot at the top)
 *           13-39         logos: event logo left + DCW logo right, or DCW alone, centred
 *           41            rule in the three DCW colours
 *           44-62         event name (bold) and edition
 *           64-102        the person's name, large; affiliation under it when there is one
 *           106-123       role band, full width of the panel
 *           126-134       "Knowledge is Human."
 *           136           rule
 *   BACK    same frame, event name, QR code, "Scan to verify", name + role, tagline, rule
 *
 * $c = [
 *   'name', 'role', 'role_color', 'affiliation' (already resolved, may be ''), 'event_name', 'edition',
 *   'frame'      => 'var:jaliXXXXXX' or data URI, '' for a plain coloured border,
 *   'event_logo' => 'var:evlogo' or data URI or '', 'event_logo_ratio' => w/h (0 = unknown),
 *   'dcw_logo'   => same,                          'dcw_logo_ratio'   => w/h,
 *   'qr'         => image source (back only),
 *   'guides'     => true on the preview only: marks the lanyard slot,
 * ]
 */

if (!defined('EVENT_CARD_W_MM'))      define('EVENT_CARD_W_MM', 105);
if (!defined('EVENT_CARD_H_MM'))      define('EVENT_CARD_H_MM', 148);
if (!defined('EVENT_CARD_FRAME_MM'))  define('EVENT_CARD_FRAME_MM', 8);    // width of the jali band
if (!defined('EVENT_CARD_MARGIN_MM')) define('EVENT_CARD_MARGIN_MM', 11);  // text starts this far from the edge
if (!defined('EVENT_CARD_TAGLINE'))   define('EVENT_CARD_TAGLINE', 'Knowledge is Human.');

/** A colour as six lowercase hex digits without the #, whatever came in; falls back to the DCW navy. */
function event_card_hex(string $colour): string
{
    $c = strtolower(ltrim(trim($colour), '#'));
    if (preg_match('/^[0-9a-f]{3}$/', $c)) {
        $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
    }
    return preg_match('/^[0-9a-f]{6}$/', $c) ? $c : '1b3f66';
}

/** White or dark text, whichever reads better on the given background colour. */
function event_card_on(string $background): string
{
    [$r, $g, $b] = sscanf(event_card_hex($background), '%2x%2x%2x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 170 ? '#0f172a' : '#ffffff';
}

/**
 * The affiliation line to print. What was typed wins. Only people who are officially DCW (organisers and
 * volunteers) fall back to the DCW name; for everyone else the line stays empty and is left off the card.
 */
function event_card_affiliation(string $role, string $typed): string
{
    $typed = trim($typed);
    if ($typed !== '') return $typed;
    $dcwRoles = ['organiser', 'organisers', 'organizer', 'organizers', 'volunteer', 'volunteers'];
    return in_array(mb_strtolower(trim($role), 'UTF-8'), $dcwRoles, true) ? EventCardModel::DEFAULT_AFFILIATION : '';
}

/** Font size in points for the person's name. Long names step down and wrap to two lines inside the box. */
function event_card_name_pt(string $name): float
{
    $n = max(1, mb_strlen(trim($name), 'UTF-8'));
    $one = 367 / $n;                         // largest size that keeps it on one line in an 83 mm box
    if ($one >= 22) return (float) min(30, floor($one));
    return (float) max(11, min(26, floor(624 / $n)));   // two lines
}

/** Font size in points for the role band, so a long role still fits on one line. */
function event_card_role_pt(string $role, float $max, float $widthMm): float
{
    $n = max(1, mb_strlen(trim($role), 'UTF-8'));
    return (float) max(8, min($max, floor(($widthMm - 4) / ($n * 0.331))));
}

/** An absolutely positioned box, in millimetres, relative to the card. */
function event_card_box(float $left, float $top, float $w, float $h, string $inner, string $extra = ''): string
{
    return '<div style="position: absolute; left: ' . $left . 'mm; top: ' . $top . 'mm; width: ' . $w . 'mm; height: ' . $h . 'mm; ' . $extra . '">'
         . $inner . '</div>';
}

/** Content centred both ways in a box (mPDF honours a table cell's vertical-align, not a div's). */
function event_card_middle(string $inner, float $h, string $cellStyle = ''): string
{
    return '<table cellpadding="0" cellspacing="0" style="width: 100%; height: ' . $h . 'mm;"><tr>'
         . '<td style="text-align: center; vertical-align: middle; ' . $cellStyle . '">' . $inner . '</td></tr></table>';
}

/** A logo fitted inside a box whatever its shape. Unknown ratio is treated as square. */
function event_card_fit(string $uri, float $ratio, float $boxW, float $boxH): string
{
    if ($uri === '') return '';
    $ratio = $ratio > 0 ? $ratio : 1.0;
    $style = $ratio > $boxW / $boxH ? 'width: ' . $boxW . 'mm;' : 'height: ' . $boxH . 'mm;';
    return '<img src="' . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . '" style="' . $style . '" />';
}

/** The thin rule in the three DCW colours. */
function event_card_rule(float $left, float $top, float $w, array $colours): string
{
    $cell = '';
    $pct  = round(100 / max(1, count($colours)), 2);
    foreach ($colours as $col) {
        $cell .= '<td style="width: ' . $pct . '%; background-color: ' . $col . '; height: 0.9mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td>';
    }
    return event_card_box($left, $top, $w, 1, '<table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>' . $cell . '</tr></table>');
}

/** The jali band: the tinted PNG when there is one, otherwise a plain border in the role colour. */
function event_card_frame(array $c, string $roleColor): string
{
    $W = EVENT_CARD_W_MM;
    $H = EVENT_CARD_H_MM;
    $f = (string) ($c['frame'] ?? '');
    if ($f !== '') {
        return event_card_box(0, 0, $W, $H, '<img src="' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '" style="width: ' . $W . 'mm; height: ' . $H . 'mm;" />');
    }
    $b = EVENT_CARD_FRAME_MM;
    $s = 'background-color: #' . event_card_hex($roleColor) . '; font-size: 1pt; line-height: 1pt;';
    return event_card_box(0, 0, $W, $b, '&nbsp;', $s)
         . event_card_box(0, $H - $b, $W, $b, '&nbsp;', $s)
         . event_card_box(0, $b, $b, $H - 2 * $b, '&nbsp;', $s)
         . event_card_box($W - $b, $b, $b, $H - 2 * $b, '&nbsp;', $s);
}

/** Preview only: shows where the lanyard slot is punched. */
function event_card_guides(array $c): string
{
    if (empty($c['guides'])) return '';
    return event_card_box((EVENT_CARD_W_MM - 14) / 2, 2.5, 14, 3, '&nbsp;',
        'border: 0.3mm dashed #ffffff; border-radius: 1.5mm; background-color: rgba(15, 23, 42, 0.35); font-size: 1pt; line-height: 1pt;');
}

/** "Knowledge is Human." between two eight-pointed stars, echoing the jali. */
function event_card_tagline(array $t, float $top, float $left, float $w): string
{
    $star = '<span style="color: ' . ($t['stripe'][1] ?? $t['accent']) . '; font-size: 8pt;">&#10036;</span>';
    $html = '<div class="tag">' . $star . '&nbsp;&nbsp;' . htmlspecialchars(EVENT_CARD_TAGLINE, ENT_QUOTES, 'UTF-8') . '&nbsp;&nbsp;' . $star . '</div>';
    return event_card_box($left, $top, $w, 8, event_card_middle($html, 8));
}

/** The shared <style> block. */
function event_card_css(array $t): string
{
    return '<style>
        body { font-family: dejavusans, sans-serif; color: #0f172a; }
        .ev   { font-size: 16pt; font-weight: bold; color: ' . $t['ink'] . '; line-height: 1.25; text-align: center; }
        .ed   { font-size: 8.5pt; font-weight: bold; color: ' . $t['accent'] . '; text-transform: uppercase; letter-spacing: 0.6mm; text-align: center; }
        .nm   { font-weight: bold; color: ' . $t['ink'] . '; line-height: 1.2; }
        .aff  { font-size: 10.5pt; color: #334155; text-align: center; line-height: 1.3; }
        .band { font-weight: bold; text-transform: uppercase; }
        .tag  { font-size: 10pt; font-weight: bold; color: ' . $t['ink'] . '; letter-spacing: 0.3mm; text-align: center; }
        .scan { font-size: 9pt; font-weight: bold; color: ' . $t['accent'] . '; text-align: center; }
    </style>';
}

/** Header logos: the event logo and the DCW logo side by side, or the DCW logo alone, centred. */
function event_card_logos(array $c, float $top): string
{
    $M   = EVENT_CARD_MARGIN_MM;
    $in  = EVENT_CARD_W_MM - 2 * $M;
    $ev  = event_card_fit((string) ($c['event_logo'] ?? ''), (float) ($c['event_logo_ratio'] ?? 0), 38, 26);
    $dcw = event_card_fit((string) ($c['dcw_logo'] ?? ''),   (float) ($c['dcw_logo_ratio'] ?? 0),   $ev !== '' ? 38 : 40, 26);
    if ($ev !== '') {
        $inner = '<table cellpadding="0" cellspacing="0" style="width: 100%; height: 26mm;"><tr>'
               . '<td style="width: 50%; text-align: center; vertical-align: middle;">' . $ev . '</td>'
               . '<td style="width: 50%; text-align: center; vertical-align: middle;">' . $dcw . '</td></tr></table>';
    } else {
        $inner = event_card_middle($dcw, 26);
    }
    return event_card_box($M, $top, $in, 26, $inner);
}

function event_card_front_html(array $c): string
{
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $t  = member_card_theme('generic');
    $M  = EVENT_CARD_MARGIN_MM;
    $in = EVENT_CARD_W_MM - 2 * $M;

    $name      = trim((string) ($c['name'] ?? ''));
    $role      = trim((string) ($c['role'] ?? ''));
    $roleColor = '#' . event_card_hex((string) ($c['role_color'] ?? ''));
    $aff       = trim((string) ($c['affiliation'] ?? ''));
    $edition   = trim((string) ($c['edition'] ?? ''));

    $out  = event_card_css($t);
    $out .= event_card_frame($c, $roleColor);
    $out .= event_card_logos($c, 13);
    $out .= event_card_rule($M, 41, $in, $t['stripe']);

    $evHtml = '<div class="ev">' . $h($c['event_name'] ?? '') . '</div>'
            . ($edition !== '' ? '<div style="height: 1.4mm; font-size: 1pt; line-height: 1pt;">&nbsp;</div><div class="ed">' . $h($edition) . '</div>' : '');
    $out .= event_card_box($M, 44, $in, 18, event_card_middle($evHtml, 18));

    $nameHtml = '<div class="nm" style="font-size: ' . event_card_name_pt($name) . 'pt;">' . $h($name) . '</div>';
    if ($aff !== '') {
        $out .= event_card_box($M, 64, $in, 28, event_card_middle($nameHtml, 28));
        $out .= event_card_box($M, 93, $in, 9, event_card_middle('<div class="aff">' . $h($aff) . '</div>', 9));
    } else {
        $out .= event_card_box($M, 64, $in, 38, event_card_middle($nameHtml, 38));
    }

    $band = '<div class="band" style="font-size: ' . ($pt = event_card_role_pt($role, 18, $in)) . 'pt; letter-spacing: ' . round($pt * 0.065, 2) . 'mm; color: ' . event_card_on($roleColor) . ';">' . $h($role) . '</div>';
    $out .= event_card_box($M, 106, $in, 17, event_card_middle($band, 17, 'background-color: ' . $roleColor . ';'));

    $out .= event_card_tagline($t, 126, $M, $in);
    $out .= event_card_rule($M, 136, $in, $t['stripe']);
    $out .= event_card_guides($c);

    return $out;
}

function event_card_back_html(array $c): string
{
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $t  = member_card_theme('generic');
    $W  = EVENT_CARD_W_MM;
    $M  = EVENT_CARD_MARGIN_MM;
    $in = $W - 2 * $M;

    $name      = trim((string) ($c['name'] ?? ''));
    $role      = trim((string) ($c['role'] ?? ''));
    $roleColor = '#' . event_card_hex((string) ($c['role_color'] ?? ''));
    $edition   = trim((string) ($c['edition'] ?? ''));

    $out  = event_card_css($t);
    $out .= event_card_frame($c, $roleColor);

    $ev   = '<div class="ev" style="font-size: 13pt;">' . $h($c['event_name'] ?? '') . '</div>'
          . ($edition !== '' ? '<div class="ed" style="font-size: 7.5pt;">' . $h($edition) . '</div>' : '');
    $out .= event_card_box($M, 13, $in, 16, event_card_middle($ev, 16));
    $out .= event_card_rule($M, 31, $in, $t['stripe']);

    $qrSize = 44;
    $out .= event_card_box(($W - $qrSize) / 2, 35, $qrSize, $qrSize,
        '<img src="' . $h($c['qr'] ?? '') . '" style="width: ' . $qrSize . 'mm; height: ' . $qrSize . 'mm;" />');
    $out .= event_card_box($M, 80, $in, 7, event_card_middle('<div class="scan">Scan to verify</div>', 7));

    $pt   = max(10.0, min(16.0, round(event_card_name_pt($name) * 0.62)));
    $out .= event_card_box($M, 89, $in, 17, event_card_middle('<div class="nm" style="font-size: ' . $pt . 'pt;">' . $h($name) . '</div>', 17));

    $band = '<div class="band" style="font-size: ' . ($bp = event_card_role_pt($role, 11, $in)) . 'pt; letter-spacing: ' . round($bp * 0.065, 2) . 'mm; color: ' . event_card_on($roleColor) . ';">' . $h($role) . '</div>';
    $out .= event_card_box($M, 108, $in, 11, event_card_middle($band, 11, 'background-color: ' . $roleColor . ';'));

    $out .= event_card_tagline($t, 123, $M, $in);
    $out .= event_card_rule($M, 136, $in, $t['stripe']);
    $out .= event_card_guides($c);

    return $out;
}
