<?php
require_once __DIR__ . '/member_card_template.php';   // member_card_theme(): the DCW colours

/**
 * DCW Engage - event participation card (front and back), as HTML for mPDF.
 *
 * Modelled on the WikiConference India badge: portrait, a clear strip at the top for the lanyard slot, the
 * event logo and name, the person's name large in the middle, their affiliation, and a full-width role band
 * near the bottom. Colours come from the DCW logo (member_card_theme('generic')), so event cards and
 * membership cards belong together.
 *
 * Size: EVENT_CARD_W_MM x EVENT_CARD_H_MM (A6 portrait by default). Everything is placed with
 * position:absolute in millimetres, so the card is exactly that size whatever the content.
 *
 * Both sides carry the name and role, because a lanyard badge hangs flipped as often as not.
 *
 *   FRONT   top 0-14 mm      empty: the lanyard slot goes here (marked on the preview only)
 *           15-35            logos: event logo left + DCW logo right, or DCW alone, centred
 *           37               thin rule in the three DCW colours
 *           41-58            event name (bold) and edition (small capitals)
 *           62-86            the person's name, large, centred in its box
 *           88-96            affiliation
 *           108-124          role band, full width of the card minus the side margins
 *           131-141          "Identify  Learn  Think  Mentor"
 *   BACK    same top strip, event name, QR code, "Scan to verify", name + role, notice, rule
 *
 * $c = [
 *   'name', 'role', 'role_color', 'affiliation', 'event_name', 'edition',
 *   'event_logo' => 'var:evlogo' or '', 'event_logo_ratio' => w/h (0 = unknown),
 *   'dcw_logo'   => 'var:dcw'    or '', 'dcw_logo_ratio'   => w/h,
 *   'qr'         => 'var:qr<id>'   (back only),
 * ]
 */

if (!defined('EVENT_CARD_W_MM')) define('EVENT_CARD_W_MM', 105);
if (!defined('EVENT_CARD_H_MM')) define('EVENT_CARD_H_MM', 148);

/** Font size in points for the person's name, by length, so a long name still fits inside its box. */
function event_card_name_pt(string $name): float
{
    $n = mb_strlen($name, 'UTF-8');
    if ($n <= 14) return 22.0;
    if ($n <= 20) return 18.0;
    if ($n <= 28) return 15.0;
    if ($n <= 40) return 12.5;
    return 11.0;
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

/** The thin three-colour rule used under the logos. */
function event_card_rule(float $left, float $top, float $w, array $colours): string
{
    $cell = '';
    $pct  = round(100 / max(1, count($colours)), 2);
    foreach ($colours as $col) {
        $cell .= '<td style="width: ' . $pct . '%; background-color: ' . $col . '; height: 0.9mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td>';
    }
    return event_card_box($left, $top, $w, 1, '<table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>' . $cell . '</tr></table>');
}

/** The shared <style> block. */
function event_card_css(array $t): string
{
    return '<style>
        body { font-family: dejavusans, sans-serif; color: #0f172a; }
        .ev   { font-size: 13pt; font-weight: bold; color: ' . $t['ink'] . '; line-height: 1.25; text-align: center; }
        .ed   { font-size: 7.5pt; font-weight: bold; color: ' . $t['accent'] . '; text-transform: uppercase; letter-spacing: 0.5mm; text-align: center; }
        .nm   { font-weight: bold; color: ' . $t['ink'] . '; line-height: 1.2; }
        .aff  { font-size: 8.5pt; color: #334155; text-align: center; }
        .band { font-weight: bold; color: #ffffff; text-transform: uppercase; }
        .fw   { font-size: 6.5pt; font-weight: bold; color: ' . $t['accent'] . '; letter-spacing: 0.35mm; text-align: center; }
        .scan { font-size: 7pt; font-weight: bold; color: ' . $t['accent'] . '; text-align: center; }
        .note { font-size: 6pt; color: #64748b; text-align: center; line-height: 1.4; }
    </style>';
}

/** Header logos: the event logo and the DCW logo side by side, or the DCW logo alone, centred. */
function event_card_logos(array $c, float $top): string
{
    $ev  = event_card_fit((string) ($c['event_logo'] ?? ''), (float) ($c['event_logo_ratio'] ?? 0), 38, 20);
    $dcw = event_card_fit((string) ($c['dcw_logo'] ?? ''),   (float) ($c['dcw_logo_ratio'] ?? 0),   38, 20);
    if ($ev !== '') {
        $inner = '<table cellpadding="0" cellspacing="0" style="width: 100%; height: 20mm;"><tr>'
               . '<td style="width: 50%; text-align: center; vertical-align: middle;">' . $ev . '</td>'
               . '<td style="width: 50%; text-align: center; vertical-align: middle;">' . $dcw . '</td></tr></table>';
    } else {
        $inner = event_card_middle($dcw, 20);
    }
    return event_card_box(8, $top, EVENT_CARD_W_MM - 16, 20, $inner);
}

function event_card_front_html(array $c): string
{
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $t  = member_card_theme('generic');
    $W  = EVENT_CARD_W_MM;
    $in = $W - 16;                       // content width: 8 mm margin each side

    $name      = trim((string) ($c['name'] ?? ''));
    $role      = trim((string) ($c['role'] ?? ''));
    $roleColor = (string) ($c['role_color'] ?? $t['ink']);
    $aff       = trim((string) ($c['affiliation'] ?? ''));
    if ($aff === '') $aff = 'Deoband Community Wikimedia';
    $edition   = trim((string) ($c['edition'] ?? ''));

    $out  = event_card_css($t);
    $out .= event_card_logos($c, 15);
    $out .= event_card_rule(8, 37, $in, $t['stripe']);

    $evHtml = '<div class="ev">' . $h($c['event_name'] ?? '') . '</div>'
            . ($edition !== '' ? '<div style="height: 1.2mm; font-size: 1pt; line-height: 1pt;">&nbsp;</div><div class="ed">' . $h($edition) . '</div>' : '');
    $out .= event_card_box(8, 41, $in, 18, event_card_middle($evHtml, 18));

    $pt   = event_card_name_pt($name);
    $out .= event_card_box(8, 62, $in, 24, event_card_middle('<div class="nm" style="font-size: ' . $pt . 'pt;">' . $h($name) . '</div>', 24));
    $out .= event_card_box(8, 88, $in, 8, event_card_middle('<div class="aff">' . $h($aff) . '</div>', 8));

    $band = '<div class="band" style="font-size: 14pt; letter-spacing: 1.3mm;">' . $h($role) . '</div>';
    $out .= event_card_box(8, 108, $in, 16, event_card_middle($band, 16, 'background-color: ' . $roleColor . ';'));

    $d  = '&nbsp;&nbsp;<span style="color: ' . ($t['stripe'][1] ?? $t['accent']) . '; font-size: 5pt;">&#9670;</span>&nbsp;&nbsp;';
    $fw = '<div class="fw">' . implode($d, ['IDENTIFY', 'LEARN', 'THINK', 'MENTOR']) . '</div>';
    $out .= event_card_box(8, 131, $in, 10, event_card_middle($fw, 10));

    return $out;
}

function event_card_back_html(array $c): string
{
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $t  = member_card_theme('generic');
    $W  = EVENT_CARD_W_MM;
    $in = $W - 16;

    $name      = trim((string) ($c['name'] ?? ''));
    $role      = trim((string) ($c['role'] ?? ''));
    $roleColor = (string) ($c['role_color'] ?? $t['ink']);
    $edition   = trim((string) ($c['edition'] ?? ''));

    $out  = event_card_css($t);
    $ev   = '<div class="ev" style="font-size: 11pt;">' . $h($c['event_name'] ?? '') . '</div>'
          . ($edition !== '' ? '<div class="ed" style="font-size: 6.5pt;">' . $h($edition) . '</div>' : '');
    $out .= event_card_box(8, 16, $in, 14, event_card_middle($ev, 14));
    $out .= event_card_rule(8, 32, $in, $t['stripe']);

    $qrSize = 40;
    $out .= event_card_box(($W - $qrSize) / 2, 38, $qrSize, $qrSize,
        '<img src="' . $h($c['qr'] ?? '') . '" style="width: ' . $qrSize . 'mm; height: ' . $qrSize . 'mm;" />');
    $out .= event_card_box(8, 80, $in, 6, event_card_middle('<div class="scan">Scan to verify</div>', 6));

    $pt   = min(14.0, event_card_name_pt($name) - 4);
    $out .= event_card_box(8, 90, $in, 14, event_card_middle('<div class="nm" style="font-size: ' . $pt . 'pt;">' . $h($name) . '</div>', 14));

    $band = '<div class="band" style="font-size: 10pt; letter-spacing: 1mm;">' . $h($role) . '</div>';
    $out .= event_card_box(8, 108, $in, 10, event_card_middle($band, 10, 'background-color: ' . $roleColor . ';'));

    $note = '<div class="note">Confirms participation only, not identity.<br />If found, please return to the event organisers.</div>';
    $out .= event_card_box(8, 124, $in, 12, event_card_middle($note, 12));
    $out .= event_card_rule(8, 140, $in, $t['stripe']);

    return $out;
}
