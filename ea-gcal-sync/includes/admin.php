<?php
/**
 * Nastavenia → EA GCal Sync: konfigurácia, náhľad, ručná synchronizácia, log.
 * Navrhnuté na ovládanie z mobilu (jeden formulár, žiadny JavaScript).
 */

defined('ABSPATH') || exit;

add_action('admin_menu', static function (): void {
    add_options_page('EA GCal Sync', 'EA GCal Sync', 'manage_options', EAGS_SLUG, 'eags_render_settings_page');
});

add_filter('plugin_action_links_' . plugin_basename(EAGS_FILE), static function (array $links): array {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . EAGS_SLUG)) . '">Nastavenia</a>');
    return $links;
});

// Posledný beh skončil chybou → upozornenie v celom admine.
add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $log = eags_get_log();
    $last = $log[0] ?? null;
    if (!$last || $last['level'] !== 'error') {
        return;
    }
    printf(
        '<div class="notice notice-error"><p><strong>EA GCal Sync:</strong> %s <a href="%s">Otvoriť nastavenia</a></p></div>',
        esc_html($last['msg']),
        esc_url(admin_url('options-general.php?page=' . EAGS_SLUG))
    );
});

/**
 * @return array{0: string, 1: string} [typ notice, správa]
 */
function eags_save_settings_from_post(array $post): array
{
    $settings = eags_settings();
    $settings['calendar_id'] = sanitize_text_field((string) ($post['calendar_id'] ?? ''));
    $post_type = sanitize_key((string) ($post['post_type'] ?? ''));
    $settings['post_type'] = post_type_exists($post_type) ? $post_type : $settings['post_type'];
    $settings['new_status'] = ($post['new_status'] ?? '') === 'publish' ? 'publish' : 'draft';
    $settings['notify_email'] = sanitize_email((string) ($post['notify_email'] ?? ''));
    $settings['notify_new'] = empty($post['notify_new']) ? 0 : 1;

    $json = trim((string) ($post['sa_json'] ?? ''));
    if ($json !== '') {
        try {
            eags_parse_service_account($json);
        } catch (RuntimeException $e) {
            eags_update_settings($settings);
            return ['error', 'Ostatné nastavenia uložené, ale JSON kľúč nie: ' . $e->getMessage()];
        }
        $settings['sa_json'] = $json;
    }
    eags_update_settings($settings);
    return ['success', 'Nastavenia uložené.'];
}

/**
 * Najbližších 10 udalostí a ako by vyzerali na webe – nič nezapisuje.
 *
 * @return array<int, array<string,mixed>>
 */
function eags_preview(): array
{
    $settings = eags_settings();
    if ($settings['calendar_id'] === '') {
        throw new RuntimeException('Najprv vyplň Calendar ID a ulož nastavenia.');
    }
    $events = eags_google_events($settings['calendar_id'], [
        'timeMin' => eags_rfc3339(time()),
        'singleEvents' => 'true',
        'orderBy' => 'startTime',
        'maxResults' => 10,
    ], 1);

    $rows = [];
    foreach ($events as $event) {
        $row = ['title' => (string) ($event['summary'] ?? '(bez názvu)'), 'post_id' => eags_find_post_by_event((string) $event['id'])];
        if (empty($event['start']['dateTime'])) {
            $row['warnings'] = ['Celodenná udalosť – nebude importovaná.'];
        } else {
            $mapped = eags_map_event($event, eags_known_keys(), wp_timezone(), 'eags_find_organizer');
            $row['fields'] = $mapped['fields'];
            $row['warnings'] = $mapped['warnings'];
        }
        $rows[] = $row;
    }
    return $rows;
}

function eags_format_when(array $fields): string
{
    $d = (string) ($fields['datum-eventu'] ?? '');
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m)) {
        return '';
    }
    $from = substr((string) ($fields['cas-eventu'] ?? ''), 0, 5);
    $to = substr((string) ($fields['cas-konce-eventu'] ?? ''), 0, 5);
    return sprintf('%d. %d. %d %s–%s', $m[3], $m[2], $m[1], $from, $to);
}

function eags_render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notices = [];
    $preview = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['eags_action'])) {
        check_admin_referer('eags_settings');
        $post = wp_unslash($_POST);
        switch (sanitize_key((string) $post['eags_action'])) {
            case 'save':
                $notices[] = eags_save_settings_from_post($post);
                break;
            case 'preview':
                try {
                    $preview = eags_preview();
                } catch (Throwable $e) {
                    $notices[] = ['error', $e->getMessage()];
                }
                break;
            case 'sync':
                $stats = eags_sync();
                $notices[] = isset($stats['error']) ? ['error', $stats['error']] : ['success', eags_stats_summary($stats)];
                break;
            case 'reset':
                delete_option(EAGS_OPT_LAST_SYNC);
                $notices[] = ['success', 'Ďalšia synchronizácia prejde všetky budúce udalosti. Existujúce príspevky sa neduplikujú.'];
                break;
        }
    }

    $s = eags_settings();
    $sa_email = '';
    if ($s['sa_json'] !== '') {
        try {
            $sa_email = eags_parse_service_account($s['sa_json'])['client_email'];
        } catch (RuntimeException $e) {
            $sa_email = '';
        }
    }
    $next = wp_next_scheduled(EAGS_CRON_HOOK);
    $last_sync = (string) get_option(EAGS_OPT_LAST_SYNC, '');
    $acf_ok = function_exists('acf_get_field_groups');
    $fields = eags_acf_fields();
    ?>
    <div class="wrap eags">
        <h1>EA GCal Sync</h1>
        <style>
            .eags .eags-card { background: #fff; border: 1px solid #c3c4c7; padding: 12px 16px; margin: 16px 0; max-width: 900px; }
            .eags textarea, .eags input.regular-text { width: 100%; max-width: 640px; }
            .eags .eags-buttons .button { margin: 0 8px 8px 0; }
            .eags table.widefat td, .eags table.widefat th { vertical-align: top; }
            .eags .eags-warn { color: #996800; }
            .eags .eags-error { color: #b32d2e; }
            .eags .eags-table-wrap { overflow-x: auto; }
        </style>

        <?php foreach ($notices as [$type, $msg]) : ?>
            <div class="notice notice-<?php echo esc_attr($type); ?>"><p><?php echo esc_html($msg); ?></p></div>
        <?php endforeach; ?>

        <?php if (!$acf_ok) : ?>
            <div class="notice notice-error"><p>ACF nie je aktívne – plugin nemá kam zapisovať polia.</p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('eags_settings'); ?>
            <?php // Enter v textovom poli = Uložiť, nie prvé tlačidlo vo formulári (Synchronizovať). ?>
            <button type="submit" name="eags_action" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;"></button>

            <div class="eags-card">
                <h2>Stav</h2>
                <ul>
                    <li>Service account: <strong><?php echo $sa_email !== '' ? esc_html($sa_email) : 'nenastavený'; ?></strong>
                        <?php if ($sa_email !== '') : ?><br><small>Kalendár musí byť zdieľaný s týmto e-mailom (právo „See all event details“).</small><?php endif; ?></li>
                    <li>Posledná úspešná synchronizácia: <strong><?php echo $last_sync !== '' ? esc_html(wp_date('j. n. Y H:i', (int) strtotime($last_sync))) : 'ešte nebola'; ?></strong></li>
                    <li>Ďalší automatický beh: <strong><?php echo $next ? esc_html(wp_date('j. n. Y H:i', $next)) : 'nenaplánovaný'; ?></strong> <small>(WP-Cron, spúšťa sa návštevou webu)</small></li>
                    <li>ACF polia pre <?php echo esc_html(EAGS_POST_TYPE); ?>: <strong><?php echo esc_html((string) count($fields)); ?></strong>
                        <?php if ($fields) : ?><br><small><?php echo esc_html(implode(', ', array_keys($fields))); ?></small><?php endif; ?></li>
                </ul>
                <p class="eags-buttons">
                    <button class="button button-primary" name="eags_action" value="sync">Synchronizovať teraz</button>
                    <button class="button" name="eags_action" value="preview">Náhľad najbližších udalostí</button>
                </p>
            </div>

            <?php if (is_array($preview)) : ?>
                <div class="eags-card">
                    <h2>Náhľad (nič sa nezapísalo)</h2>
                    <?php if (!$preview) : ?>
                        <p>V kalendári nie sú žiadne budúce udalosti.</p>
                    <?php else : ?>
                        <div class="eags-table-wrap">
                            <table class="widefat striped">
                                <thead><tr><th>Udalosť</th><th>Kedy</th><th>Miesto</th><th>Organizátor</th><th>Jazyk</th><th>Na webe</th></tr></thead>
                                <tbody>
                                <?php foreach ($preview as $row) : $f = $row['fields'] ?? []; ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($row['title']); ?></strong>
                                            <?php foreach ($row['warnings'] ?? [] as $w) : ?><br><span class="eags-warn"><?php echo esc_html($w); ?></span><?php endforeach; ?></td>
                                        <td><?php echo esc_html(eags_format_when($f)); ?></td>
                                        <td><?php echo esc_html((string) ($f['misto-eventu'] ?? '')); ?>
                                            <?php if (!empty($f['misto-eventu-url'])) : ?><br><a href="<?php echo esc_url((string) $f['misto-eventu-url']); ?>" target="_blank" rel="noopener">mapa</a><?php endif; ?></td>
                                        <td><?php echo esc_html((string) ($f['organizator-eventu'] ?? '')); ?>
                                            <?php if (!empty($f['fotka-organizatora-eventu'])) : ?><br><?php echo wp_get_attachment_image((int) $f['fotka-organizatora-eventu'], [40, 40]); ?><?php endif; ?></td>
                                        <td><?php echo esc_html((string) ($f['jazyk-eventu'] ?? '')); ?></td>
                                        <td><?php if ($row['post_id']) : ?>
                                                <a href="<?php echo esc_url(eags_edit_link((int) $row['post_id'])); ?>"><?php echo esc_html(get_post_status((int) $row['post_id']) === 'publish' ? 'publikované' : 'koncept'); ?></a>
                                            <?php else : ?>bude vytvorené<?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="eags-card">
                <h2>Nastavenia</h2>
                <p><label for="eags-cal"><strong>Calendar ID</strong></label><br>
                    <input id="eags-cal" name="calendar_id" class="regular-text" value="<?php echo esc_attr($s['calendar_id']); ?>" placeholder="…@group.calendar.google.com">
                    <br><small>Google Calendar → nastavenia kalendára → Integrate calendar → Calendar ID.</small></p>

                <p><label for="eags-sa"><strong>JSON kľúč service accountu</strong></label><br>
                    <textarea id="eags-sa" name="sa_json" rows="4" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr($sa_email !== '' ? 'Uložený. Vlož nový len pri výmene kľúča.' : '{ "type": "service_account", … }'); ?>"></textarea></p>

                <p><label for="eags-type"><strong>Typ príspevku akcií</strong></label><br>
                    <select id="eags-type" name="post_type">
                        <?php foreach (get_post_types(['show_ui' => true], 'objects') as $type) : ?>
                            <option value="<?php echo esc_attr($type->name); ?>" <?php selected($s['post_type'], $type->name); ?>><?php echo esc_html($type->labels->name . ' (' . $type->name . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($s['post_type'] !== EAGS_POST_TYPE) : ?><br><small>Zmena sa prejaví po opätovnom načítaní stránky.</small><?php endif; ?></p>

                <p><label for="eags-status"><strong>Nové akcie</strong></label><br>
                    <select id="eags-status" name="new_status">
                        <option value="draft" <?php selected($s['new_status'], 'draft'); ?>>uložiť ako koncept (odporúčané)</option>
                        <option value="publish" <?php selected($s['new_status'], 'publish'); ?>>hneď publikovať, ak nechýbajú údaje</option>
                    </select></p>

                <p><label for="eags-mail"><strong>E-mail pre upozornenia</strong></label><br>
                    <input id="eags-mail" type="email" name="notify_email" class="regular-text" value="<?php echo esc_attr($s['notify_email']); ?>"><br>
                    <label><input type="checkbox" name="notify_new" value="1" <?php checked((int) $s['notify_new'], 1); ?>> posielať e-mail pri novej, zrušenej alebo neúplnej akcii</label>
                    <br><small>Chyba synchronizácie sa posiela vždy, najviac raz denne.</small></p>

                <p class="eags-buttons">
                    <button class="button button-primary" name="eags_action" value="save">Uložiť nastavenia</button>
                </p>
            </div>

            <div class="eags-card">
                <h2>Konvencia v popise udalosti</h2>
                <p>Riadok <code>Názov poľa: hodnota</code> v popise udalosti sa zapíše do ACF poľa s rovnakým názvom (bez ohľadu na diakritiku a veľké písmená). Do textu príspevku sa tieto riadky nedostanú. Napríklad:</p>
                <pre>Jazyk: English
Přihlásit se: https://forms.gle/…
Organizátor: Jana Nováková</pre>
                <p>Organizátorov a ich fotky spravuješ v <a href="<?php echo esc_url(admin_url('edit.php?post_type=' . EAGS_POST_TYPE . '&page=eags-organizatori')); ?>">Akcie → Organizátori</a>.</p>
            </div>

            <div class="eags-card">
                <h2>Log</h2>
                <?php $log = eags_get_log(); ?>
                <?php if (!$log) : ?>
                    <p>Zatiaľ prázdny.</p>
                <?php else : ?>
                    <div class="eags-table-wrap">
                        <table class="widefat striped">
                            <tbody>
                            <?php foreach (array_slice($log, 0, 30) as $entry) : ?>
                                <tr>
                                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('j. n. H:i', (int) $entry['t'])); ?></td>
                                    <td class="<?php echo $entry['level'] === 'error' ? 'eags-error' : ($entry['level'] === 'warn' ? 'eags-warn' : ''); ?>"><?php echo esc_html($entry['msg']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="eags-buttons">
                    <button class="button" name="eags_action" value="reset" onclick="return confirm('Prejsť znova všetky budúce udalosti?');">Prejsť všetky budúce udalosti znova</button>
                </p>
            </div>
        </form>
    </div>
    <?php
}
