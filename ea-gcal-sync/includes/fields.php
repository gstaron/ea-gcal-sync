<?php
/**
 * ACF polia príspevkov all-events – zisťujú sa za behu z field group,
 * takže nové pole funguje bez zmeny kódu.
 */

defined('ABSPATH') || exit;

/**
 * Aliasy kľúčov v popise udalosti → názov ACF poľa.
 * Okrem nich sa automaticky rozpoznáva label aj name každého poľa.
 *
 * @return array<string,string>
 */
function eags_aliases(): array
{
    return (array) apply_filters('eags_aliases', [
        'jazyk' => 'jazyk-eventu',
        'jazyky' => 'jazyk-eventu',
        'language' => 'jazyk-eventu',
        'prihlasit se' => 'url-eventu',
        'prihlasenie' => 'url-eventu',
        'prihlaska' => 'url-eventu',
        'registrace' => 'url-eventu',
        'registracia' => 'url-eventu',
        'registration' => 'url-eventu',
        'organizator' => 'organizator-eventu',
        'organizer' => 'organizator-eventu',
    ]);
}

/**
 * @return array<string, array<string,mixed>> názov poľa → ACF field
 */
function eags_acf_fields(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return $cache;
    }
    foreach (acf_get_field_groups(['post_type' => EAGS_POST_TYPE]) as $group) {
        foreach ((array) acf_get_fields($group['key']) as $field) {
            if (!empty($field['name']) && !empty($field['key'])) {
                $cache[$field['name']] = $field;
            }
        }
    }
    return $cache;
}

/**
 * Normalizovaný kľúč z popisu → názov ACF poľa.
 *
 * @return array<string,string>
 */
function eags_known_keys(): array
{
    $fields = eags_acf_fields();
    $known = [];
    foreach ($fields as $name => $field) {
        $known[eags_normalize_key((string) $field['label'])] = $name;
        $known[eags_normalize_key((string) $name)] = $name;
        $known[eags_normalize_key(str_replace(['-', '_'], ' ', (string) $name))] = $name;
    }
    foreach (eags_aliases() as $alias => $name) {
        if (isset($fields[$name])) {
            $known[eags_normalize_key($alias)] = $name;
        }
    }
    unset($known['']);
    return $known;
}
