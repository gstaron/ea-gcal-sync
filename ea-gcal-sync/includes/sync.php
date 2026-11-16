<?php
/**
 * Synchronizácia Google Calendar → príspevky all-events.
 *
 * Pravidlá:
 * - Udalosť ↔ príspevok cez meta _gcal_event_id (žiadne duplikáty, aj pri opakovanom behu).
 * - Pole sa prepíše len vtedy, keď ho admin od posledného importu ručne nezmenil
 *   (posledná importovaná hodnota je v meta _eags_src_<pole>).
 * - Zrušená udalosť → príspevok do konceptov, nikdy sa nemaže.
 * - Príspevok v koši alebo odpojený v boxe „Google kalendár“ sa nemení.
 */

defined('ABSPATH') || exit;

const EAGS_META_EVENT_ID = '_gcal_event_id';
const EAGS_META_DETACHED = '_eags_detached';
const EAGS_META_SRC_PREFIX = '_eags_src_';
const EAGS_META_WARNINGS = '_eags_warnings';

/**
 * @return array{created: int[], updated: int[], cancelled: int[], adopted: int[], skipped: int, warnings: string[], failed: int, error?: string}
 */
function eags_sync(): array
{
    $stats = ['created' => [], 'updated' => [], 'cancelled' => [], 'adopted' => [], 'skipped' => 0, 'warnings' => [], 'failed' => 0];

    if (get_transient('eags_running')) {
        eags_log('info', 'Synchronizácia už beží, tento beh preskakujem.');
        return $stats;
    }
    set_transient('eags_running', 1, 5 * MINUTE_IN_SECONDS);

    try {
        $settings = eags_settings();
        if ($settings['calendar_id'] === '') {
            throw new RuntimeException('Chýba Calendar ID (Nastavenia → EA GCal Sync).');
        }

        $started = eags_rfc3339(time() - 5 * MINUTE_IN_SECONDS); // prekryv pre istotu
        $query = [
            'timeMin' => eags_rfc3339(time() - DAY_IN_SECONDS),
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'maxResults' => 250,
        ];
        $last_sync = (string) get_option(EAGS_OPT_LAST_SYNC, '');
        if ($last_sync !== '') {
            $query['updatedMin'] = $last_sync; // inak prvý beh = všetky budúce udalosti
        }

        $events = eags_google_events($settings['calendar_id'], $query);
        foreach ($events as $event) {
            try {
                eags_upsert_event($event, $settings, $stats);
            } catch (Throwable $e) {
                $stats['failed']++;
                $msg = sprintf('Udalosť „%s“: %s', (string) ($event['summary'] ?? $event['id'] ?? '?'), $e->getMessage());
                $stats['warnings'][] = $msg;
                eags_log('error', $msg);
            }
        }

        // Pri chybe jednej udalosti sa časová značka neposúva, aby sa skúsila znova.
        if ($stats['failed'] === 0) {
            update_option(EAGS_OPT_LAST_SYNC, $started, false);
        }

        eags_log($stats['failed'] ? 'warn' : 'ok', eags_stats_summary($stats, count($events)));
        delete_transient('eags_error_mailed');
        eags_notify($stats, $settings);
    } catch (Throwable $e) {
        $stats['error'] = $e->getMessage();
        eags_log('error', $e->getMessage());
        eags_notify_error($e->getMessage());
    } finally {
        delete_transient('eags_running');
    }

    return $stats;
}

function eags_stats_summary(array $stats, ?int $events = null): string
{
    $parts = [];
    if ($events !== null) {
        $parts[] = sprintf('zmenených udalostí v kalendári: %d', $events);
    }
    $parts[] = sprintf('nové: %d', count($stats['created']));
    $parts[] = sprintf('aktualizované: %d', count($stats['updated']));
    if ($stats['adopted']) {
        $parts[] = sprintf('prevzaté existujúce: %d', count($stats['adopted']));
    }
    if ($stats['cancelled']) {
        $parts[] = sprintf('zrušené: %d', count($stats['cancelled']));
    }
    if ($stats['failed']) {
        $parts[] = sprintf('chyby: %d', $stats['failed']);
    }
    return 'Hotovo – ' . implode(', ', $parts) . '.';
}

/**
 * @return int ID príspevku alebo 0
 */
function eags_find_post_by_event(string $event_id): int
{
    $ids = get_posts([
        'post_type' => EAGS_POST_TYPE,
        'post_status' => array_keys(get_post_stati()),
        'meta_key' => EAGS_META_EVENT_ID,
        'meta_value' => $event_id,
        'fields' => 'ids',
        'numberposts' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => true,
    ]);
    return (int) ($ids[0] ?? 0);
}

/**
 * Príspevok, ktorý kedysi vytvoril Make.com (rovnaký názov a dátum, bez _gcal_event_id).
 */
function eags_find_adoptable_post(string $title, string $date_ymd): int
{
    $ids = get_posts([
        'post_type' => EAGS_POST_TYPE,
        'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
        'title' => $title,
        'fields' => 'ids',
        'numberposts' => 1,
        'suppress_filters' => true,
        'meta_query' => [
            'relation' => 'AND',
            ['key' => EAGS_META_EVENT_ID, 'compare' => 'NOT EXISTS'],
            ['key' => 'datum-eventu', 'value' => $date_ymd],
        ],
    ]);
    return (int) ($ids[0] ?? 0);
}

/**
 * @param array<string,mixed> $event
 */
function eags_upsert_event(array $event, array $settings, array &$stats): void
{
    $event_id = (string) ($event['id'] ?? '');
    if ($event_id === '') {
        return;
    }
    $post_id = eags_find_post_by_event($event_id);

    if ($post_id && (get_post_status($post_id) === 'trash' || get_post_meta($post_id, EAGS_META_DETACHED, true))) {
        $stats['skipped']++;
        return;
    }

    if (($event['status'] ?? '') === 'cancelled') {
        if ($post_id && in_array(get_post_status($post_id), ['publish', 'future'], true)) {
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            $stats['cancelled'][] = $post_id;
            eags_log('warn', sprintf('Udalosť „%s“ bola zrušená v kalendári – príspevok vrátený do konceptov.', get_the_title($post_id)));
        }
        return;
    }

    if (empty($event['start']['dateTime']) || empty($event['end']['dateTime'])) {
        $stats['skipped']++;
        eags_log('info', sprintf('Celodenná udalosť „%s“ preskočená.', (string) ($event['summary'] ?? '')));
        return;
    }

    $mapped = eags_map_event($event, eags_known_keys(), wp_timezone(), 'eags_find_organizer');
    $acf = eags_acf_fields();
    $adopting = false;

    if (!$post_id) {
        $post_id = eags_find_adoptable_post($mapped['title'], (string) $mapped['fields']['datum-eventu']);
        $adopting = $post_id > 0;
    }

    if (!$post_id) {
        $status = $settings['new_status'] === 'publish' && !$mapped['warnings'] ? 'publish' : 'draft';
        $post_id = wp_insert_post([
            'post_type' => EAGS_POST_TYPE,
            'post_status' => $status,
            'post_title' => $mapped['title'],
            'post_content' => eags_content_html($mapped['content']),
        ], true);
        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }
        update_post_meta($post_id, EAGS_META_EVENT_ID, $event_id);
        update_post_meta($post_id, EAGS_META_SRC_PREFIX . 'title', $mapped['title']);
        $stats['created'][] = $post_id;
        $is_new = true;
    } else {
        $is_new = false;
        if ($adopting) {
            update_post_meta($post_id, EAGS_META_EVENT_ID, $event_id);
            $stats['adopted'][] = $post_id;
        }
        if (eags_apply_title($post_id, $mapped['title'], $adopting) === 'written') {
            $stats['updated'][$post_id] = $post_id;
        }
    }

    foreach ($mapped['fields'] as $name => $raw) {
        if (!isset($acf[$name])) {
            if (in_array($name, ['datum-eventu', 'cas-eventu'], true)) {
                throw new RuntimeException(sprintf('Pole „%s“ nie je vo field group pre %s (alebo nie je aktívne ACF).', $name, EAGS_POST_TYPE));
            }
            continue; // voliteľné pole, ktoré web nemá
        }
        $value = eags_coerce_value($acf[$name], $raw);
        if ($value === null) {
            $mapped['warnings'][] = sprintf('Hodnotu „%s“ nebolo možné zapísať do poľa „%s“.', is_scalar($raw) ? (string) $raw : '?', $acf[$name]['label']);
            continue;
        }
        if ($acf[$name]['type'] === 'text') {
            $value = sanitize_text_field((string) $value);
        }
        $mode = $is_new ? 'new' : ($adopting ? 'adopt' : 'update');
        $result = eags_apply_field($post_id, $acf[$name], $value, $mode);
        if ($result === 'written' && !$is_new) {
            $stats['updated'][$post_id] = $post_id;
        }
    }

    // Rovnaké upozornenie pre ten istý príspevok hlásime len raz (nie pri každom behu).
    $warn_hash = $mapped['warnings'] ? md5((string) wp_json_encode($mapped['warnings'])) : '';
    if ($warn_hash !== (string) get_post_meta($post_id, EAGS_META_WARNINGS, true)) {
        update_post_meta($post_id, EAGS_META_WARNINGS, $warn_hash);
        foreach ($mapped['warnings'] as $warning) {
            $stats['warnings'][] = sprintf('„%s“: %s', $mapped['title'], $warning);
        }
        if ($mapped['warnings']) {
            eags_log('warn', sprintf('„%s“: %s', $mapped['title'], implode(' ', $mapped['warnings'])));
        }
    }
}

/**
 * Zapíše hodnotu, ak ju admin od posledného importu ručne nezmenil.
 *
 * @param array<string,mixed> $field
 * @param string|int $value
 * @param string $mode new|update|adopt
 * @return string written|same|kept
 */
function eags_apply_field(int $post_id, array $field, $value, string $mode = 'update'): string
{
    $adopting = $mode === 'adopt';
    $meta_key = EAGS_META_SRC_PREFIX . $field['name'];
    $new = (string) $value;
    $current = get_field($field['key'], $post_id, false);
    $current = is_scalar($current) ? (string) $current : '';
    $has_src = metadata_exists('post', $post_id, $meta_key);
    $src = (string) get_post_meta($post_id, $meta_key, true);

    if ($current === $new) {
        if (!$has_src || $src !== $new) {
            update_post_meta($post_id, $meta_key, $new);
        }
        return 'same';
    }

    // Prevzatý príspevok z Make.com: vyplnené hodnoty nechávame, len si zapamätáme zdroj.
    $untouched = $mode === 'new' || ($has_src ? $current === $src : $current === '');
    if ($adopting && $current !== '') {
        $untouched = false;
    }
    if (!$untouched) {
        if (!$has_src) {
            update_post_meta($post_id, $meta_key, $new);
        }
        return 'kept';
    }

    update_field($field['key'], $value, $post_id);
    update_post_meta($post_id, $meta_key, $new);
    return 'written';
}

function eags_apply_title(int $post_id, string $title, bool $adopting): string
{
    $meta_key = EAGS_META_SRC_PREFIX . 'title';
    $current = (string) get_post_field('post_title', $post_id, 'raw');
    $has_src = metadata_exists('post', $post_id, $meta_key);
    $src = (string) get_post_meta($post_id, $meta_key, true);

    if ($current === $title) {
        update_post_meta($post_id, $meta_key, $title);
        return 'same';
    }
    if ($adopting || !$has_src || $current !== $src) {
        return 'kept';
    }
    wp_update_post(['ID' => $post_id, 'post_title' => $title]);
    update_post_meta($post_id, $meta_key, $title);
    return 'written';
}

function eags_content_html(string $text): string
{
    if ($text === '') {
        return '';
    }
    return wp_kses_post(wpautop(make_clickable(esc_html($text))));
}

function eags_edit_link(int $post_id): string
{
    return admin_url('post.php?post=' . $post_id . '&action=edit');
}

function eags_notify(array $stats, array $settings): void
{
    if (empty($settings['notify_new']) || $settings['notify_email'] === '') {
        return;
    }
    $lines = [];
    foreach ($stats['created'] as $post_id) {
        $status = get_post_status($post_id) === 'publish' ? 'publikovaná' : 'koncept – skontroluj a publikuj';
        $lines[] = sprintf('Nová akcia (%s): %s%s  %s', $status, get_post_field('post_title', $post_id, 'raw'), "\n", eags_edit_link($post_id));
    }
    foreach ($stats['cancelled'] as $post_id) {
        $lines[] = sprintf('Zrušená v kalendári, vrátená do konceptov: %s%s  %s', get_post_field('post_title', $post_id, 'raw'), "\n", eags_edit_link($post_id));
    }
    foreach (array_unique($stats['warnings']) as $warning) {
        $lines[] = 'Upozornenie: ' . $warning;
    }
    if (!$lines) {
        return;
    }
    wp_mail(
        $settings['notify_email'],
        sprintf('[%s] Akcie z Google kalendára', wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)),
        implode("\n\n", $lines) . "\n\n— EA GCal Sync, " . admin_url('options-general.php?page=' . EAGS_SLUG)
    );
}

function eags_notify_error(string $message): void
{
    $email = eags_settings()['notify_email'];
    if ($email === '' || get_transient('eags_error_mailed')) {
        return; // najviac jeden chybový e-mail denne
    }
    wp_mail(
        $email,
        sprintf('[%s] EA GCal Sync zlyhal', wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)),
        $message . "\n\nPodrobnosti a tlačidlo „Synchronizovať teraz“: " . admin_url('options-general.php?page=' . EAGS_SLUG)
    );
    set_transient('eags_error_mailed', 1, DAY_IN_SECONDS);
}
