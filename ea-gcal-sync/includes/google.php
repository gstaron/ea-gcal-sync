<?php
/**
 * Google Calendar API cez service account – bez OAuth súhlasu a bez refresh tokenov.
 * Každý beh si podpíše JWT privátnym kľúčom a vymení ho za hodinový access token.
 */

defined('ABSPATH') || exit;

const EAGS_GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const EAGS_GOOGLE_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

/**
 * Overí a vráti JSON kľúč service accountu.
 *
 * @return array<string,string>
 */
function eags_parse_service_account(string $json): array
{
    $sa = json_decode($json, true);
    if (!is_array($sa) || ($sa['type'] ?? '') !== 'service_account' || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('JSON kľúč nie je platný kľúč service accountu (chýba type, client_email alebo private_key).');
    }
    return $sa;
}

/**
 * @return array<string,string>
 */
function eags_service_account(): array
{
    $json = eags_settings()['sa_json'];
    if ($json === '') {
        throw new RuntimeException('Chýba JSON kľúč service accountu (Nastavenia → EA GCal Sync).');
    }
    return eags_parse_service_account($json);
}

function eags_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function eags_token_cache_key(array $sa): string
{
    return 'eags_token_' . md5($sa['client_email'] . '|' . ($sa['private_key_id'] ?? ''));
}

function eags_google_token(): string
{
    $sa = eags_service_account();
    $cache_key = eags_token_cache_key($sa);
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('PHP na hostingu nemá rozšírenie OpenSSL, JWT sa nedá podpísať.');
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($sa['private_key_id'])) {
        $header['kid'] = $sa['private_key_id'];
    }
    $claims = [
        'iss' => $sa['client_email'],
        'scope' => EAGS_GOOGLE_SCOPE,
        'aud' => EAGS_GOOGLE_TOKEN_URL,
        'iat' => $now,
        'exp' => $now + HOUR_IN_SECONDS,
    ];
    $unsigned = eags_base64url((string) wp_json_encode($header)) . '.' . eags_base64url((string) wp_json_encode($claims));

    $key = openssl_pkey_get_private($sa['private_key']);
    if ($key === false) {
        throw new RuntimeException('Privátny kľúč service accountu sa nepodarilo načítať – skontroluj vložený JSON.');
    }
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Podpísanie JWT zlyhalo.');
    }

    $response = wp_remote_post(EAGS_GOOGLE_TOKEN_URL, [
        'timeout' => 15,
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned . '.' . eags_base64url($signature),
        ],
    ]);
    if (is_wp_error($response)) {
        throw new RuntimeException('Google token: ' . $response->get_error_message());
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $raw = (string) wp_remote_retrieve_body($response);
    $body = json_decode($raw, true);
    if ($code !== 200 || empty($body['access_token'])) {
        $detail = is_array($body) ? (string) ($body['error_description'] ?? $body['error'] ?? '') : '';
        throw new RuntimeException(sprintf('Google token (HTTP %d): %s', $code, $detail !== '' ? $detail : substr($raw, 0, 300)));
    }

    $ttl = max(MINUTE_IN_SECONDS, (int) ($body['expires_in'] ?? HOUR_IN_SECONDS) - 5 * MINUTE_IN_SECONDS);
    set_transient($cache_key, $body['access_token'], $ttl);
    return (string) $body['access_token'];
}

/**
 * events.list so stránkovaním.
 *
 * @param array<string,string|int> $query
 * @return array<int, array<string,mixed>>
 */
function eags_google_events(string $calendar_id, array $query, int $max_pages = 20): array
{
    $token = eags_google_token();
    $items = [];
    $page_token = null;
    $pages = 0;

    do {
        $q = $query;
        if ($page_token !== null) {
            $q['pageToken'] = $page_token;
        }
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id)
            . '/events?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException('Calendar API: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            delete_transient(eags_token_cache_key(eags_service_account()));
        }
        if ($code === 404) {
            throw new RuntimeException(sprintf(
                'Kalendár „%s“ sa nenašiel. Je zdieľaný s %s s právom „See all event details“?',
                $calendar_id,
                eags_service_account()['client_email']
            ));
        }
        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';
            throw new RuntimeException(sprintf('Calendar API (HTTP %d): %s', $code, $msg !== '' ? $msg : 'neznáma chyba'));
        }

        foreach ((array) ($body['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        $page_token = isset($body['nextPageToken']) ? (string) $body['nextPageToken'] : null;
        $pages++;
    } while ($page_token !== null && $pages < $max_pages);

    return $items;
}

function eags_rfc3339(int $timestamp): string
{
    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}
