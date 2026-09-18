<?php
/**
 * Resolve an applicant name from a dynamic form submission.
 *
 * Form field names are generated from labels, so the name field may be
 * full_name, applicant_name, name_and_age, or another custom key.
 */
function resolveApplicantName(array $postData, array $schema, string $fallback = 'Applicant'): string
{
    $preferredKeys = [
        'full_name',
        'applicant_name',
        'name_and_age',
        'name',
        'your_name',
    ];

    foreach ($preferredKeys as $key) {
        if (isset($postData[$key]) && is_scalar($postData[$key])) {
            $value = trim((string) $postData[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    foreach ($schema['fields'] ?? [] as $field) {
        $fieldName = trim((string) ($field['name'] ?? ''));
        $fieldLabel = trim((string) ($field['label'] ?? ''));
        if ($fieldName === '') {
            continue;
        }

        $normalizedName = strtolower($fieldName);
        $normalizedLabel = strtolower($fieldLabel);
        $looksLikeName = preg_match('/(^|_)(full_?name|applicant_?name|your_?name|name)($|_)/', $normalizedName)
            || preg_match('/(^|\s)(full\s+name|applicant\s+name|your\s+name|name)(\s|$)/', $normalizedLabel)
            || preg_match('/name\s+and\s+age|age\s+and\s+name/', $normalizedLabel);

        if ($looksLikeName && isset($postData[$fieldName]) && is_scalar($postData[$fieldName])) {
            $value = trim((string) $postData[$fieldName]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return $fallback;
}
