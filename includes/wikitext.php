<?php
/**
 * DCW Engage - Minimal, safe wikitext formatting for form descriptions
 * (see #44, reworking #41/#15 to match https://en.wikipedia.org/wiki/Help:Cheatsheet)
 *
 * Most of DCW's work is around wikis, so form descriptions use wikitext-style
 * syntax instead of Markdown — but still a small, closed subset of it, not a
 * full parser and not raw HTML, so admin-entered text can never put arbitrary
 * markup or CSS on a public page.
 *
 * Safety model: the input is htmlspecialchars()'d FIRST, so any literal
 * <, >, ", ' the admin typed becomes inert text/entities before any of our
 * own markup is inserted on top of it. Every pattern below only ever matches
 * on punctuation that survives escaping (', =, :, [, ], the escaped &#039;
 * entity), and only our own fixed set of replacement tags is ever emitted —
 * nothing an admin types can produce a tag or attribute of their own. Links
 * are restricted to http(s) URLs specifically so a javascript: URL can't
 * slip through.
 *
 * Supported syntax:
 *   '''bold'''
 *   ''italic''
 *   '''''bold italic'''''
 *   [https://example.com link text]   — http(s) only, opens in a new tab
 *   == Large heading ==               — must start and end the line
 *   === Medium heading ===            — must start and end the line
 *   : first indent                    — up to 3 levels (:, ::, :::)
 *   :: second indent
 *   ::: third indent
 *
 * This is intentionally not the real MediaWiki parser — no internal
 * [[links]], no lists, no nested/overlapping apostrophe runs beyond the
 * three fixed widths above. Just enough of the cheatsheet to cover what
 * form descriptions need.
 */
class MiniWikiText {
    /** How far margin-left grows per indent level, in pixels. */
    const INDENT_STEP_PX = 24;

    /** Indent nests at most this many levels (see #44). */
    const MAX_INDENT_LEVEL = 3;

    /**
     * Render admin-entered description text to safe HTML.
     */
    public static function render($text) {
        $lines = explode("\n", (string) $text);
        $htmlLines = [];
        $prevWasBlock = false;

        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            if (preg_match('/^===\s+(.*?)\s+===$/', $escaped, $m)) {
                $htmlLines[] = '<div style="font-size:1.15em; font-weight:700; margin:10px 0 4px;">' . self::inline($m[1]) . '</div>';
                $prevWasBlock = true;
                continue;
            }
            if (preg_match('/^==\s+(.*?)\s+==$/', $escaped, $m)) {
                $htmlLines[] = '<div style="font-size:1.35em; font-weight:700; margin:12px 0 6px;">' . self::inline($m[1]) . '</div>';
                $prevWasBlock = true;
                continue;
            }
            if (preg_match('/^(:+)\s*(.*)$/', $escaped, $m)) {
                $level = min(strlen($m[1]), self::MAX_INDENT_LEVEL);
                $px = $level * self::INDENT_STEP_PX;
                $htmlLines[] = '<div style="margin-left:' . $px . 'px;">' . self::inline($m[2]) . '</div>';
                $prevWasBlock = true;
                continue;
            }

            // Mirrors the plain nl2br() this replaced: a <br> between lines,
            // but skipped right after a block line (heading/indent), which
            // is already block-level and doesn't need one.
            if (!$prevWasBlock && !empty($htmlLines)) {
                $htmlLines[] = '<br>';
            }
            $htmlLines[] = self::inline($escaped);
            $prevWasBlock = false;
        }

        return implode("\n", $htmlLines);
    }

    /**
     * Bold / italic / link, applied within a single already-escaped line.
     *
     * Bold and italic both use the apostrophe, which htmlspecialchars()
     * turns into the &#039; entity — so these patterns match runs of that
     * entity, not a literal quote. Widest run (bold+italic) first, so it
     * isn't partially consumed by the narrower bold/italic patterns.
     */
    private static function inline($escaped) {
        // Links first, so a URL's own punctuation, or the link text's own
        // apostrophes, never get misread as bold/italic markers afterward.
        $escaped = preg_replace_callback(
            '/\[(https?:\/\/[^\s\]]+)\s+([^\]]+)\]/',
            function ($m) {
                return '<a href="' . $m[1] . '" target="_blank" rel="noopener noreferrer">' . $m[2] . '</a>';
            },
            $escaped
        );

        $q = '&#039;';
        $escaped = preg_replace('/(?:' . $q . '){5}(.+?)(?:' . $q . '){5}/', '<strong><em>$1</em></strong>', $escaped);
        $escaped = preg_replace('/(?:' . $q . '){3}(.+?)(?:' . $q . '){3}/', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/(?:' . $q . '){2}(.+?)(?:' . $q . '){2}/', '<em>$1</em>', $escaped);

        return $escaped;
    }

    /**
     * Strip the syntax back to plain text. Used for previews — e.g. the
     * homepage program card blurb — that truncate the description and must
     * not show raw formatting punctuation (quotes/heading/indent/link
     * markers) or risk cutting a tag in half.
     *
     * Operates on the raw (unescaped) input, since the output here is plain
     * text, not HTML — same as render()'s inline(), just without the
     * htmlspecialchars() detour.
     */
    public static function stripToPlainText($text) {
        $text = (string) $text;
        $text = preg_replace('/^={2,3}\s+(.*?)\s+={2,3}$/m', '$1', $text);
        $text = preg_replace('/^:+\s*/m', '', $text);
        $text = preg_replace('/\[https?:\/\/\S+\s+([^\]]+)\]/', '$1', $text);
        $text = preg_replace("/'{5}(.+?)'{5}/", '$1', $text);
        $text = preg_replace("/'{3}(.+?)'{3}/", '$1', $text);
        $text = preg_replace("/'{2}(.+?)'{2}/", '$1', $text);
        return trim($text);
    }
}
