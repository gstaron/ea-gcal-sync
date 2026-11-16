<?php
/**
 * Čisté funkcie bez závislosti na WordPresse – testované v tests/unit.php.
 */

defined('ABSPATH') || exit;

const EAGS_DEFAULT_LANGUAGE = 'Česky, English';

/**
 * Malé písmená, bez diakritiky, jedna medzera. "  Přihlásit  se " → "prihlasit se".
 */
function eags_normalize_key(string $s): string
{
    $s = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e',
        'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ž' => 'z',
    ]);
}

/**
 * "Názov miesta, https://maps.app.goo.gl/x" → ['Názov miesta', 'https://maps.app.goo.gl/x'].
 * URL sa berie len na konci textu (konvencia v kalendári).
 *
 * @return array{0: string, 1: string}
 */
function eags_split_location(string $location): array
{
    $location = trim($location);
    if (preg_match('~^(.*?)[\s,;]*(https?://\S+)$~su', $location, $m)) {
        return [trim($m[1], " \t\n\r,;"), $m[2]];
    }
    return [$location, ''];
}

function eags_first_url(string $text): string
{
    return preg_match('~https?://[^\s<>"\')]+~u', $text, $m) ? $m[0] : '';
}

/**
 * HTML popisu z Google Calendar → čistý text po riadkoch.
 * Odkaz <a href="X">Y</a> sa zmení na X (ak je Y URL) alebo "Y (X)".
 */
function eags_description_to_text(string $html): string
{
    $html = (string) preg_replace_callback(
        '~<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>~isu',
        static function (array $m): string {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $label === $href || preg_match('~^https?://~i', $label)) {
                return $href;
            }
            return $label . ' (' . $href . ')';
        },
        $html
    );
    $html = (string) preg_replace('~<br\s*/?>|</p>|</div>|</li>~i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\u{00A0}"], ['', ' '], $text);
    $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

/**
 * Vyberie z textu riadky "Kľúč: hodnota", ktorých kľúč pozná $known
 * (normalizovaný kľúč → názov ACF poľa). Ostatné riadky vráti ako zvyšný text.
 *
 * @param array<string,string> $known
 * @return array{0: array<string,string>, 1: string}
 */
function eags_parse_description(string $text, array $known): array
{
    $values = [];
    $rest = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        if (preg_match('/^\s*([^:]{1,40}?)\s*:\s*(.+?)\s*$/u', $line, $m)) {
            $key = eags_normalize_key($m[1]);
            if (isset($known[$key])) {
                $values[$known[$key]] = $m[2];
                continue;
            }
        }
        $rest[] = $line;
    }
    $rest_text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $rest)));
    return [$values, $rest_text];
}

/**
 * Voľný text jazyka → jedna z hodnôt webu, alebo null, ak sa nedá určiť.
 */
function eags_normalize_language(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $v = eags_normalize_key($value);
    $cz = (bool) preg_match('/\b(cesky|cestina|czech|cz|cs|cj)\b/u', $v);
    $en = (bool) preg_match('/\b(english|anglicky|anglictina|angl|en|aj)\b/u', $v);
    $both = (bool) preg_match('/\b(oba|obe|both|bilingual)\b/u', $v);
    if ($both || ($cz && $en)) {
        return 'Česky, English';
    }
    if ($cz) {
        return 'Česky';
    }
    if ($en) {
        return 'English';
    }
    return null;
}

/**
 * Prevedie text na hodnotu, ktorú ACF pole daného typu uloží.
 * Vracia null, keď sa hodnota nedá použiť (volajúci zapíše upozornenie).
 *
 * @param array<string,mixed> $field ACF field (type, choices, …)
 * @param mixed $value
 * @return string|int|null
 */
function eags_coerce_value(array $field, $value)
{
    $type = (string) ($field['type'] ?? 'text');
    if (is_int($value)) {
        return in_array($type, ['image', 'file', 'number', 'range', 'post_object', 'user'], true) ? $value : (string) $value;
    }
    $v = trim(strip_tags((string) $value));

    switch ($type) {
        case 'text':
        case 'textarea':
            return $v;

        case 'email':
            return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $v) ? $v : null;

        case 'url':
            if ($v === '') {
                return '';
            }
            $url = eags_first_url($v);
            return $url !== '' ? $url : null;

        case 'number':
        case 'range':
            $n = str_replace([' ', ','], ['', '.'], $v);
            return is_numeric($n) ? $n : null;

        case 'true_false':
            $k = eags_normalize_key($v);
            if (in_array($k, ['1', 'ano', 'yes', 'true', 'y'], true)) {
                return 1;
            }
            if (in_array($k, ['0', 'nie', 'ne', 'no', 'false', 'n'], true)) {
                return 0;
            }
            return null;

        case 'date_picker':
            if (preg_match('/^\d{8}$/', $v)) {
                return $v;
            }
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
                return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d%02d%02d', $m[1], $m[2], $m[3]) : null;
            }
            if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})$/', $v, $m)) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d%02d%02d', $m[3], $m[2], $m[1]) : null;
            }
            return null;

        case 'time_picker':
            if (preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $v, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
                return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
            }
            return null;

        case 'select':
        case 'radio':
        case 'button_group':
            $want = eags_normalize_key($v);
            foreach ((array) ($field['choices'] ?? []) as $choice_value => $choice_label) {
                if (eags_normalize_key((string) $choice_value) === $want || eags_normalize_key((string) $choice_label) === $want) {
                    return (string) $choice_value;
                }
            }
            return null;

        case 'image':
        case 'file':
            return ctype_digit($v) ? (int) $v : null;

        default:
            return null; // nepodporovaný typ poľa
    }
}

/**
 * Udalosť z Google Calendar API → hodnoty pre príspevok.
 *
 * @param array<string,mixed>  $event          položka z events.list
 * @param array<string,string> $known          normalizovaný kľúč → názov ACF poľa (labely + aliasy)
 * @param callable             $find_organizer fn(string $creator_email, string $name_from_description): array{0:string,1:int}
 * @return array{title: string, content: string, fields: array<string,string|int>, warnings: string[]}
 */
function eags_map_event(array $event, array $known, DateTimeZone $tz, callable $find_organizer): array
{
    $warnings = [];
    $start = (new DateTimeImmutable((string) $event['start']['dateTime']))->setTimezone($tz);
    $end = (new DateTimeImmutable((string) $event['end']['dateTime']))->setTimezone($tz);
    [$place, $place_url] = eags_split_location((string) ($event['location'] ?? ''));
    [$values, $rest] = eags_parse_description(eags_description_to_text((string) ($event['description'] ?? '')), $known);

    $fields = [
        'datum-eventu' => $start->format('Ymd'),
        'cas-eventu' => $start->format('H:i:s'),
        'cas-konce-eventu' => $end->format('H:i:s'),
        'misto-eventu' => $place,
        'misto-eventu-url' => $place_url,
        'misto-eventu-text' => $place_url !== '' ? 'Google maps' : '',
    ];

    $language_raw = $values['jazyk-eventu'] ?? null;
    unset($values['jazyk-eventu']);
    $language = eags_normalize_language($language_raw);
    if ($language === null) {
        $warnings[] = $language_raw === null
            ? 'V popise chýba riadok „Jazyk: …“, použité „' . EAGS_DEFAULT_LANGUAGE . '“.'
            : 'Neznámy jazyk „' . $language_raw . '“, použité „' . EAGS_DEFAULT_LANGUAGE . '“.';
        $language = EAGS_DEFAULT_LANGUAGE;
    }
    $fields['jazyk-eventu'] = $language;

    $organizer_override = trim((string) ($values['organizator-eventu'] ?? ''));
    unset($values['organizator-eventu']);
    [$organizer, $photo] = $find_organizer((string) ($event['creator']['email'] ?? ''), $organizer_override);
    $fields['organizator-eventu'] = $organizer;
    if ($photo > 0) {
        $fields['fotka-organizatora-eventu'] = $photo;
    }

    // Riadky z popisu majú prednosť pred odvodenými hodnotami (napr. "Místo eventu: …").
    $fields = array_merge($fields, $values);

    $title = trim((string) ($event['summary'] ?? ''));
    return [
        'title' => $title !== '' ? $title : '(bez názvu)',
        'content' => $rest,
        'fields' => $fields,
        'warnings' => $warnings,
    ];
}
