<?php
/**
 * Testovacie prostredie: CPT all-events, ACF polia ako na webe a falošné Google API.
 * Kopíruje sa do wp-content/mu-plugins len v e2e teste.
 */

add_action('init', static function (): void {
    register_post_type('all-events', ['public' => true, 'label' => 'Akcie', 'supports' => ['title', 'editor']]);
});

add_action('acf/init', static function (): void {
    $f = static function (string $name, string $type, string $label, array $extra = []): array {
        return array_merge(['key' => 'field_test_' . str_replace('-', '_', $name), 'name' => $name, 'label' => $label, 'type' => $type], $extra);
    };
    acf_add_local_field_group([
        'key' => 'group_test_all_events',
        'title' => 'Akcia',
        'fields' => [
            $f('datum-eventu', 'date_picker', 'Datum eventu', ['return_format' => 'Ymd']),
            $f('cas-eventu', 'time_picker', 'Čas eventu'),
            $f('cas-konce-eventu', 'time_picker', 'Čas konce eventu'),
            $f('misto-eventu', 'text', 'Místo eventu'),
            $f('misto-eventu-url', 'url', 'URL místa'),
            $f('misto-eventu-text', 'text', 'Text odkazu na místo'),
            $f('organizator-eventu', 'text', 'Organizátor eventu'),
            $f('jazyk-eventu', 'text', 'Jazyk eventu'),
            $f('fotka-organizatora-eventu', 'image', 'Fotka organizátora', ['return_format' => 'id']),
            $f('text-odkazu-eventu', 'text', 'Text odkazu', ['default_value' => 'Přihlásit se']),
            $f('url-eventu', 'url', 'URL eventu'),
            $f('kapacita-eventu', 'number', 'Kapacita'),
        ],
        'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'all-events']]],
    ]);
});

// Falošné Google API. Režim a udalosti nastavuje test cez options.
$GLOBALS['eags_test_requests'] = [];
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    $GLOBALS['eags_test_requests'][] = $url;
    $mode = get_option('eags_test_mode', 'ok');
    $json = static function (int $code, array $body): array {
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    };

    if (strpos($url, 'https://oauth2.googleapis.com/token') === 0) {
        if ($mode === 'token_fail') {
            return $json(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
        }
        // Over, že prišiel podpísaný JWT so správnymi claims.
        $parts = explode('.', (string) ($args['body']['assertion'] ?? ''));
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        if (count($parts) !== 3 || ($claims['scope'] ?? '') !== 'https://www.googleapis.com/auth/calendar.readonly') {
            return $json(400, ['error' => 'bad_assertion']);
        }
        update_option('eags_test_token_calls', (int) get_option('eags_test_token_calls', 0) + 1);
        return $json(200, ['access_token' => 'test-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }

    if (strpos($url, 'https://www.googleapis.com/calendar/v3/calendars/') === 0) {
        if (($args['headers']['Authorization'] ?? '') !== 'Bearer test-token') {
            return $json(401, ['error' => ['message' => 'Invalid Credentials']]);
        }
        if ($mode === 'not_found') {
            return $json(404, ['error' => ['message' => 'Not Found']]);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $items = array_values(array_filter((array) get_option('eags_test_events', []), static function ($e) use ($q) {
            return ($q['showDeleted'] ?? '') === 'true' || ($e['status'] ?? '') !== 'cancelled';
        }));
        // Stránkovanie: po 2 položkách, aby sa otestoval nextPageToken.
        $offset = (int) ($q['pageToken'] ?? 0);
        $body = ['items' => array_slice($items, $offset, 2)];
        if ($offset + 2 < count($items)) {
            $body['nextPageToken'] = (string) ($offset + 2);
        }
        return $json(200, $body);
    }

    return $pre;
}, 10, 3);

// Zachytávanie e-mailov namiesto odosielania.
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $mails = (array) get_option('eags_test_mails', []);
    $mails[] = ['to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message']];
    update_option('eags_test_mails', $mails);
    return true;
}, 10, 2);
