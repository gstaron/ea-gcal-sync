<?php
/**
 * End-to-end test synchronizácie na skutočnom WordPresse + ACF.
 * Spúšťa tests/e2e/run.sh cez `wp eval-file`.
 */

$GLOBALS['eags_t'] = ['fail' => 0, 'count' => 0];
function t(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['eags_t']['count']++;
    if ($ok) {
        echo "  ok   $name\n";
        return;
    }
    $GLOBALS['eags_t']['fail']++;
    echo "  FAIL $name" . ($detail !== '' ? " – $detail" : '') . "\n";
}
function eq(string $name, $expected, $actual): void
{
    t($name, $expected === $actual, 'očakávané ' . var_export($expected, true) . ', je ' . var_export($actual, true));
}
function raw(int $post_id, string $name)
{
    return get_field($name, $post_id, false);
}
function posts_for(string $event_id): array
{
    return get_posts(['post_type' => 'all-events', 'post_status' => 'any', 'meta_key' => '_gcal_event_id', 'meta_value' => $event_id, 'fields' => 'ids', 'numberposts' => -1]);
}
function event(string $id, string $summary, string $start, string $end, array $extra = []): array
{
    return array_merge([
        'id' => $id,
        'status' => 'confirmed',
        'summary' => $summary,
        'creator' => ['email' => 'someone@example.org'],
        'start' => ['dateTime' => $start, 'timeZone' => 'Europe/Prague'],
        'end' => ['dateTime' => $end, 'timeZone' => 'Europe/Prague'],
    ], $extra);
}
function reset_mocks(): void
{
    update_option('eags_test_mails', []);
    $GLOBALS['eags_test_requests'] = [];
}

update_option('timezone_string', 'Europe/Prague');

// --- Príprava: service account s ozajstným RSA kľúčom (JWT sa naozaj podpisuje)
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $pem);
$sa_json = wp_json_encode([
    'type' => 'service_account',
    'project_id' => 'test',
    'private_key_id' => 'kid1',
    'private_key' => $pem,
    'client_email' => 'sync@test.iam.gserviceaccount.com',
]);
eags_update_settings(['calendar_id' => 'ea-akcie@group.calendar.google.com', 'sa_json' => $sa_json, 'new_status' => 'draft', 'notify_email' => 'admin@example.org', 'notify_new' => 1]);

echo "Prostredie\n";
t('ACF field group načítaná', count(eags_acf_fields()) === 12, 'polí: ' . count(eags_acf_fields()));
t('cron naplánovaný', (bool) wp_next_scheduled(EAGS_CRON_HOOK));

// Príspevok, ktorý kedysi vytvoril Make.com (bez _gcal_event_id)
$make_post = wp_insert_post(['post_type' => 'all-events', 'post_status' => 'publish', 'post_title' => 'Starý post z Make']);
update_field('field_test_datum_eventu', '20261120', $make_post);
update_field('field_test_organizator_eventu', 'Ručne zadané', $make_post);

update_option('eags_test_events', [
    event('e1', 'Diskusný večer', '2026-12-01T19:00:00+01:00', '2026-12-01T21:00:00+01:00', [
        'description' => 'Príďte!<br><br>Jazyk: English<br>Kapacita: 30<br>Přihlásit se: <a href="https://forms.gle/x">https://forms.gle/x</a>',
        'location' => 'Impact Hub, https://maps.app.goo.gl/abc',
    ]),
    event('e2', 'Bez jazyka', '2026-12-02T18:00:00+01:00', '2026-12-02T20:00:00+01:00'),
    ['id' => 'e3', 'status' => 'confirmed', 'summary' => 'Celodenná', 'start' => ['date' => '2026-12-03'], 'end' => ['date' => '2026-12-04']],
    event('e4', 'Starý post z Make', '2026-11-20T18:00:00+01:00', '2026-11-20T20:00:00+01:00', ['description' => 'Jazyk: Česky']),
    event('e5', 'Akcia so zlou kapacitou', '2026-12-05T18:00:00+01:00', '2026-12-05T19:00:00+01:00', ['description' => "Jazyk: cz\nKapacita: veľa"]),
]);

// --- 1. prvý beh
echo "1. Prvý beh\n";
reset_mocks();
$stats = eags_sync();
t('bez chyby', !isset($stats['error']), $stats['error'] ?? '');
t('prvý dotaz bez updatedMin', strpos($GLOBALS['eags_test_requests'][1] ?? '', 'updatedMin') === false);
t('stránkovanie prešlo (3 stránky)', count(array_filter($GLOBALS['eags_test_requests'], static function ($u) { return strpos($u, '/events?') !== false; })) === 3);
eq('vytvorené 3 (e1, e2, e5)', 3, count($stats['created']));
eq('prevzatý 1 (e4)', [$make_post], $stats['adopted']);
eq('celodenná preskočená', 1, $stats['skipped']);
t('last_sync nastavený', get_option(EAGS_OPT_LAST_SYNC) !== false);

$p1 = posts_for('e1')[0] ?? 0;
eq('e1 koncept', 'draft', get_post_status($p1));
eq('e1 dátum', '20261201', raw($p1, 'datum-eventu'));
eq('e1 čas', '19:00:00', raw($p1, 'cas-eventu'));
eq('e1 koniec', '21:00:00', raw($p1, 'cas-konce-eventu'));
eq('e1 miesto', 'Impact Hub', raw($p1, 'misto-eventu'));
eq('e1 mapa', 'https://maps.app.goo.gl/abc', raw($p1, 'misto-eventu-url'));
eq('e1 text mapy', 'Google maps', raw($p1, 'misto-eventu-text'));
eq('e1 jazyk', 'English', raw($p1, 'jazyk-eventu'));
eq('e1 organizátor (predvolený)', 'EA Czechia', raw($p1, 'organizator-eventu'));
eq('e1 kapacita (generické pole podľa labelu)', '30', (string) raw($p1, 'kapacita-eventu'));
eq('e1 prihlásenie (alias)', 'https://forms.gle/x', raw($p1, 'url-eventu'));
$content = get_post_field('post_content', $p1);
t('e1 obsah bez konvenčných riadkov', strpos($content, 'Príďte!') !== false && strpos($content, 'Jazyk') === false && strpos($content, 'Kapacita') === false, $content);

$p2 = posts_for('e2')[0] ?? 0;
eq('e2 predvolený jazyk', 'Česky, English', raw($p2, 'jazyk-eventu'));
eq('e3 nevytvorená', [], posts_for('e3'));
eq('e4 prevzatý post, nie nový', [$make_post], posts_for('e4'));
eq('e4 ručná hodnota ostala', 'Ručne zadané', raw($make_post, 'organizator-eventu'));
eq('e4 prázdne pole doplnené', '18:00:00', raw($make_post, 'cas-eventu'));
eq('e4 zostal publikovaný', 'publish', get_post_status($make_post));
$p5 = posts_for('e5')[0] ?? 0;
t('e5 zlá kapacita nezapísaná', in_array(raw($p5, 'kapacita-eventu'), [null, ''], true));
t('upozornenie na kapacitu', (bool) array_filter($stats['warnings'], static function ($w) { return strpos($w, 'veľa') !== false; }));

$mails = get_option('eags_test_mails');
t('1 súhrnný e-mail', count($mails) === 1, 'e-mailov: ' . count($mails));
t('e-mail spomína nové akcie a chýbajúci jazyk', strpos($mails[0]['message'] ?? '', 'Diskusný večer') !== false && strpos($mails[0]['message'] ?? '', 'Jazyk') !== false);

// --- 2. opakovaný beh, nič sa nezmenilo
echo "2. Opakovaný beh\n";
reset_mocks();
$stats = eags_sync();
eq('žiadne nové', 0, count($stats['created']));
eq('žiadne aktualizované', 0, count($stats['updated']));
t('dotaz s updatedMin', strpos(implode(' ', $GLOBALS['eags_test_requests']), 'updatedMin=') !== false);
eq('e1 stále jeden post', 1, count(posts_for('e1')));
eq('token z cache (1 výmena celkom)', 1, (int) get_option('eags_test_token_calls'));
eq('bez e-mailu', [], get_option('eags_test_mails'));

// --- 3. admin ručne upraví miesto, v kalendári sa zmení čas aj miesto
echo "3. Ručná úprava vs. zmena v kalendári\n";
update_field('field_test_misto_eventu', 'Opravené adminom', $p1);
wp_update_post(['ID' => $p1, 'post_status' => 'publish']);
$events = get_option('eags_test_events');
$events[0]['start']['dateTime'] = '2026-12-01T20:00:00+01:00';
$events[0]['location'] = 'Iné miesto, https://maps.app.goo.gl/zzz';
$events[0]['summary'] = 'Diskusný večer (presunuté)';
$events[1]['description'] = 'Jazyk: klingonsky';
update_option('eags_test_events', $events);
reset_mocks();
$stats = eags_sync();
eq('jedno nové upozornenie (zmenené), staré sa neopakuje', 1, count($stats['warnings']));
t('nové upozornenie je o jazyku e2', strpos($stats['warnings'][0] ?? '', 'klingonsky') !== false);
eq('čas aktualizovaný', '20:00:00', raw($p1, 'cas-eventu'));
eq('ručne upravené miesto zachované', 'Opravené adminom', raw($p1, 'misto-eventu'));
eq('URL mapy aktualizovaná', 'https://maps.app.goo.gl/zzz', raw($p1, 'misto-eventu-url'));
eq('názov aktualizovaný', 'Diskusný večer (presunuté)', get_the_title($p1));
eq('stav publikovaný zachovaný', 'publish', get_post_status($p1));
eq('metabox ukazuje ručnú úpravu', ['Místo eventu'], eags_manually_edited_fields($p1));

// --- 4. zrušenie udalosti
echo "4. Zrušená udalosť\n";
$events[0] = ['id' => 'e1', 'status' => 'cancelled'];
update_option('eags_test_events', $events);
reset_mocks();
$stats = eags_sync();
eq('zrušená → koncept', 'draft', get_post_status($p1));
eq('príspevok nezmazaný', 1, count(posts_for('e1')));
t('e-mail o zrušení', strpos(get_option('eags_test_mails')[0]['message'] ?? '', 'Zrušená') !== false);

// --- 5. odpojený a zmazaný príspevok
echo "5. Odpojený a zmazaný\n";
update_post_meta($p2, EAGS_META_DETACHED, 1);
wp_trash_post($p5);
$events[1]['start']['dateTime'] = '2026-12-02T10:00:00+01:00';
$events[4]['summary'] = 'Premenovaná';
update_option('eags_test_events', $events);
reset_mocks();
$stats = eags_sync();
eq('odpojený sa nezmenil', '18:00:00', raw($p2, 'cas-eventu'));
eq('z koša sa nevrátil ani nevznikol nový', 1, count(get_posts(['post_type' => 'all-events', 'post_status' => 'trash', 'meta_key' => '_gcal_event_id', 'meta_value' => 'e5', 'fields' => 'ids'])));
eq('e5 bez nového konceptu', 0, count(posts_for('e5')));

// --- 6. chyby
echo "6. Chyby\n";
$last_sync_before = get_option(EAGS_OPT_LAST_SYNC);
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%eags_token_%'");
wp_cache_flush();
update_option('eags_test_mode', 'token_fail');
reset_mocks();
$stats = eags_sync();
t('chyba tokenu zachytená', strpos($stats['error'] ?? '', 'Invalid JWT Signature') !== false, $stats['error'] ?? '');
eq('last_sync sa neposunul', $last_sync_before, get_option(EAGS_OPT_LAST_SYNC));
eq('posledný log je chyba', 'error', eags_get_log()[0]['level']);
eq('chybový e-mail odoslaný', 1, count(get_option('eags_test_mails')));
reset_mocks();
eags_sync();
eq('druhý chybový e-mail v ten istý deň neodíde', 0, count(get_option('eags_test_mails')));

update_option('eags_test_mode', 'not_found');
$stats = eags_sync();
t('404 radí zdieľať kalendár so service accountom', strpos($stats['error'] ?? '', 'sync@test.iam.gserviceaccount.com') !== false, $stats['error'] ?? '');

update_option('eags_test_mode', 'ok');
$stats = eags_sync();
t('po oprave beží', !isset($stats['error']), $stats['error'] ?? '');
t('úspech zruší chybový stav', eags_get_log()[0]['level'] !== 'error');

// --- 7. náhľad nič nezapisuje
echo "7. Náhľad\n";
$before = (array) wp_count_posts('all-events');
array_unshift($events, event('e9', 'Len v náhľade', '2026-12-09T18:00:00+01:00', '2026-12-09T19:00:00+01:00', ['description' => 'Jazyk: English']));
update_option('eags_test_events', $events);
$preview = eags_preview();
t('náhľad obsahuje novú udalosť', (bool) array_filter($preview, static function ($r) { return $r['title'] === 'Len v náhľade' && !$r['post_id']; }));
wp_cache_delete(_count_posts_cache_key('all-events'), 'counts');
eq('náhľad nič nevytvoril', $before, (array) wp_count_posts('all-events'));

// --- 8. administrácia sa vykreslí (s WP_DEBUG by sa ukázali notices)
echo "8. Administrácia\n";
wp_set_current_user(1);
ob_start();
eags_render_settings_page();
$html = ob_get_clean();
t('stránka nastavení', strpos($html, 'Synchronizovať teraz') !== false && strpos($html, 'sync@test.iam.gserviceaccount.com') !== false);
t('kľúč sa nevypisuje', strpos($html, 'PRIVATE KEY') === false);
t('výber typu príspevku', (bool) preg_match('/<option value="all-events"\s+selected=/', $html));
t('log sa zobrazuje', strpos($html, 'Hotovo') !== false);
ob_start();
eags_render_metabox(get_post($p1));
$box = ob_get_clean();
t('box Google kalendár', strpos($box, 'Ručne upravené') !== false && strpos($box, 'Místo eventu') !== false);

['fail' => $failures, 'count' => $count] = $GLOBALS['eags_t'];
echo $failures === 0 ? "\nOK ($count kontrol)\n" : "\n$failures z $count kontrol zlyhalo\n";
if ($failures) {
    exit(1);
}
