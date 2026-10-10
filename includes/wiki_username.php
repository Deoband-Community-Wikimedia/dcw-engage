<?php
/**
 * Wikimedia username helpers.
 *
 * MediaWiki always capitalises the first letter of a username, turns underscores into
 * spaces and collapses repeated spaces, so "jane_doe" and "Jane doe" are the same account.
 * normalize() applies those same rules so what we store matches what Wikimedia shows.
 */
final class WikiUsername
{
    /**
     * Exact form-field names that hold a Wikimedia username. The form builder derives the
     * name from the question label, so add the real key here if the pattern below misses it.
     */
    private const FIELD_NAMES = [
        'wikimedia_username',
        'wikimedia_user_name',
        'wikipedia_username',
        'wiki_username',
    ];

    /** Fallback: a field whose name or label mentions wiki(media/pedia) and user. */
    private const FIELD_PATTERN = '/(wikimedia|wikipedia|wiki).*user|user.*(wikimedia|wikipedia|wiki)/i';

    /** Trim, turn underscores and runs of whitespace into one space, uppercase the first letter. */
    public static function normalize(string $name): string
    {
        $name = trim((string) preg_replace('/[\s_]+/u', ' ', $name));
        if ($name === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
    }

    /** True when a form-schema field is a Wikimedia username field. */
    public static function isField(array $field): bool
    {
        if (($field['type'] ?? '') === 'file') {
            return false;
        }
        $name = (string) ($field['name'] ?? '');
        if ($name !== '' && in_array(strtolower($name), self::FIELD_NAMES, true)) {
            return true;
        }
        return preg_match(self::FIELD_PATTERN, $name . ' ' . (string) ($field['label'] ?? '')) === 1;
    }

    /**
     * Normalise every username field in an answers array, using the form schema to find them.
     * Returns the array with the fields fixed. Non-string values are left alone.
     */
    public static function normalizeAnswers(array $schema, array $answers): array
    {
        foreach ($schema['fields'] ?? [] as $f) {
            $n = (string) ($f['name'] ?? '');
            if ($n !== '' && self::isField($f) && isset($answers[$n]) && is_string($answers[$n])) {
                $answers[$n] = self::normalize($answers[$n]);
            }
        }
        return $answers;
    }
}
