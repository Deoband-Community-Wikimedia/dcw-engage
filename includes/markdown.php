<?php
/**
 * DCW Engage - Minimal, safe formatting for form descriptions (see #15)
 *
 * Not full Markdown and not raw HTML — a small, closed syntax so admins can
 * bold/italic text, link things, and mark a line as a larger heading-style
 * line ("set font sizes"), without ever letting admin-entered HTML or CSS
 * reach the page.
 *
 * Safety model: the input is htmlspecialchars()'d FIRST, so any literal
 * <, >, ", & the admin typed becomes inert text before any of our own
 * markup is inserted on top of it. Every pattern below only ever matches on
 * punctuation that survives escaping (*, _, #, [, ], (, )), and only our
 * own fixed set of replacement tags is ever emitted — nothing an admin
 * types can produce a tag or attribute of their own. Links are restricted
 * to http(s) URLs specifically so a javascript: URL can't slip through.
 *
 * Supported syntax:
 *   **bold**
 *   *italic*  or  _italic_
 *   [link text](https://example.com)   — http(s) only, opens in a new tab
 *   # Large heading                    — must start the line
 *   ## Medium heading                  — must start the line
 */
class MiniMarkdown {
    /**
     * Render admin-entered description text to safe HTML.
     */
    public static function render($text) {
        $lines = explode("\n", (string) $text);
        $htmlLines = [];
        $prevWasBlock = false;

        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            if (preg_match('/^##\s+(.*)$/', $escaped, $m)) {
                $htmlLines[] = '<div style="font-size:1.15em; font-weight:700; margin:10px 0 4px;">' . self::inline($m[1]) . '</div>';
                $prevWasBlock = true;
                continue;
            }
            if (preg_match('/^#\s+(.*)$/', $escaped, $m)) {
                $htmlLines[] = '<div style="font-size:1.35em; font-weight:700; margin:12px 0 6px;">' . self::inline($m[1]) . '</div>';
                $prevWasBlock = true;
                continue;
            }

            // Mirrors the plain nl2br() this replaced: a <br> between lines,
            // but skipped right after a heading div, which is already
            // block-level and doesn't need one.
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
     */
    private static function inline($escaped) {
        // Links first, so a URL's own () or the link text's own * / _
        // never get misread as bold/italic markers afterward.
        $escaped = preg_replace_callback(
            '/\[([^\[\]]+)\]\((https?:\/\/[^\s()]+)\)/',
            function ($m) {
                return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
            },
            $escaped
        );

        // Bold before italic — otherwise **x**'s own asterisks would get
        // consumed two-at-a-time by the single-star italic pattern first.
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $escaped);
        $escaped = preg_replace('/_(.+?)_/', '<em>$1</em>', $escaped);

        return $escaped;
    }

    /**
     * Strip the syntax back to plain text. Used for previews — e.g. the
     * homepage program card blurb — that truncate the description and must
     * not show raw formatting punctuation (bold/heading/link markers) or
     * risk cutting a tag in half.
     */
    public static function stripToPlainText($text) {
        $text = (string) $text;
        $text = preg_replace('/^#{1,2}\s+/m', '', $text);
        $text = preg_replace('/\[([^\[\]]+)\]\([^)]+\)/', '$1', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/', '$1', $text);
        $text = preg_replace('/_(.+?)_/', '$1', $text);
        return trim($text);
    }
}
