<?php
/**
 * Unit testy čistých funkcií (bez WordPressu).
 * Spustenie: php tests/unit.php   alebo   docker run --rm -v "$PWD":/app -w /app php:7.4-cli php tests/unit.php
 */

define('ABSPATH', __DIR__);
require __DIR__ . '/../ea-gcal-sync/includes/parse.php';

$failures = 0;
$count = 0;

function check(string $name, $expected, $actual): void
{
    global $failures, $count;
    $count++;
    if ($expected === $actual) {
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
}

// --- eags_normalize_key
check('normalize diakritika', 'prihlasit se', eags_normalize_key('  Přihlásit   se '));
check('normalize veľké', 'jazyk', eags_normalize_key('JAZYK'));
check('normalize slovenčina', 'organizator', eags_normalize_key('Organizátor'));

// --- eags_split_location
check('location s URL', ['Impact Hub, Praha', 'https://maps.app.goo.gl/abc'], eags_split_location('Impact Hub, Praha, https://maps.app.goo.gl/abc'));
check('location len text', ['Kavárna Liberál', ''], eags_split_location('Kavárna Liberál'));
check('location len URL', ['', 'https://maps.app.goo.gl/abc'], eags_split_location('https://maps.app.goo.gl/abc'));
check('location bodkočiarka', ['Online', 'https://meet.google.com/x'], eags_split_location('Online; https://meet.google.com/x'));
check('location prázdne', ['', ''], eags_split_location(''));
check('location URL v strede', ['Pri https://x.cz vchode', ''], eags_split_location('Pri https://x.cz vchode'));

// --- eags_description_to_text
check(
    'description HTML',
    "Úvod\nJazyk: English\nPřihlásit se: https://forms.gle/x",
    eags_description_to_text('Úvod<br>Jazyk: English<br/>Přihlásit se: <a href="https://forms.gle/x">https://forms.gle/x</a>')
);
check('description odkaz s textom', 'Registrace: tu (https://a.cz/?a=1&b=2)', eags_description_to_text('Registrace: <a href="https://a.cz/?a=1&amp;b=2" target="_blank">tu</a>'));
check('description entity a nbsp', 'A & B', eags_description_to_text("A&nbsp;&amp;&nbsp;B"));
check('description odstavce', "Prvý\nDruhý", eags_description_to_text('<p>Prvý</p><p>Druhý</p>'));

// --- eags_parse_description
$known = ['jazyk' => 'jazyk-eventu', 'prihlasit se' => 'url-eventu', 'kapacita' => 'kapacita-eventu'];
[$values, $rest] = eags_parse_description("Príďte na diskusiu.\n\nJazyk: English\nKapacita: 30\nPoznámka: niečo\nPřihlásit se: https://forms.gle/x", $known);
check('parse hodnoty', ['jazyk-eventu' => 'English', 'kapacita-eventu' => '30', 'url-eventu' => 'https://forms.gle/x'], $values);
check('parse zvyšok', "Príďte na diskusiu.\n\nPoznámka: niečo", $rest);
[$values] = eags_parse_description('https://example.com', $known);
check('parse URL nie je kľúč', [], $values);

// --- eags_normalize_language
check('jazyk česky', 'Česky', eags_normalize_language('Česky'));
check('jazyk čeština', 'Česky', eags_normalize_language('čeština'));
check('jazyk english', 'English', eags_normalize_language('english'));
check('jazyk oba', 'Česky, English', eags_normalize_language('Česky, English'));
check('jazyk oba slovom', 'Česky, English', eags_normalize_language('oba'));
check('jazyk CZ/EN', 'Česky, English', eags_normalize_language('CZ/EN'));
check('jazyk neznámy', null, eags_normalize_language('deutsch'));
check('jazyk chýba', null, eags_normalize_language(null));

// --- eags_coerce_value
check('coerce text', 'Ahoj', eags_coerce_value(['type' => 'text'], ' <b>Ahoj</b> '));
check('coerce url', 'https://forms.gle/x', eags_coerce_value(['type' => 'url'], 'tu (https://forms.gle/x)'));
check('coerce url zlá', null, eags_coerce_value(['type' => 'url'], 'niekde'));
check('coerce number čiarka', '2.5', eags_coerce_value(['type' => 'number'], '2,5'));
check('coerce number zlé', null, eags_coerce_value(['type' => 'number'], 'veľa'));
check('coerce date Ymd', '20260815', eags_coerce_value(['type' => 'date_picker'], '20260815'));
check('coerce date ISO', '20260815', eags_coerce_value(['type' => 'date_picker'], '2026-08-15'));
check('coerce date CZ', '20260805', eags_coerce_value(['type' => 'date_picker'], '5. 8. 2026'));
check('coerce date neplatný', null, eags_coerce_value(['type' => 'date_picker'], '31. 2. 2026'));
check('coerce time', '19:00:00', eags_coerce_value(['type' => 'time_picker'], '19:00'));
check('coerce time bodka', '09:30:00', eags_coerce_value(['type' => 'time_picker'], '9.30'));
check('coerce time zlý', null, eags_coerce_value(['type' => 'time_picker'], '25:00'));
check('coerce select label', 'en', eags_coerce_value(['type' => 'select', 'choices' => ['cs' => 'Česky', 'en' => 'English']], 'english'));
check('coerce select hodnota', 'cs', eags_coerce_value(['type' => 'select', 'choices' => ['cs' => 'Česky', 'en' => 'English']], 'CS'));
check('coerce select mimo', null, eags_coerce_value(['type' => 'select', 'choices' => ['cs' => 'Česky']], 'Deutsch'));
check('coerce true_false', 1, eags_coerce_value(['type' => 'true_false'], 'Áno'));
check('coerce image int', 6300, eags_coerce_value(['type' => 'image'], 6300));
check('coerce image string', 6300, eags_coerce_value(['type' => 'image'], '6300'));
check('coerce nepodporovaný', null, eags_coerce_value(['type' => 'repeater'], 'x'));

// --- eags_map_event
$tz = new DateTimeZone('Europe/Prague');
$organizers = static function (string $email, string $override): array {
    if ($override !== '') {
        return [$override, 0];
    }
    return $email === 'organizer@example.org' ? ['Anna Nováková', 6300] : ['EA Czechia', 4492];
};
$known = ['jazyk' => 'jazyk-eventu', 'prihlasit se' => 'url-eventu', 'organizator' => 'organizator-eventu', 'misto eventu' => 'misto-eventu'];

$event = [
    'id' => 'abc123',
    'summary' => ' Diskusný večer ',
    'description' => 'Téma: AI safety<br>Jazyk: English<br>Přihlásit se: <a href="https://forms.gle/x">https://forms.gle/x</a>',
    'location' => 'Impact Hub, https://maps.app.goo.gl/abc',
    'creator' => ['email' => 'organizer@example.org'],
    // UTC v zime (+1) aj v lete (+2) – overenie prevodu pásma a letného času
    'start' => ['dateTime' => '2026-07-15T16:00:00Z'],
    'end' => ['dateTime' => '2026-07-15T18:30:00Z'],
];
$m = eags_map_event($event, $known, $tz, $organizers);
check('map názov', 'Diskusný večer', $m['title']);
check('map obsah', 'Téma: AI safety', $m['content']);
check('map polia', [
    'datum-eventu' => '20260715',
    'cas-eventu' => '18:00:00',
    'cas-konce-eventu' => '20:30:00',
    'misto-eventu' => 'Impact Hub',
    'misto-eventu-url' => 'https://maps.app.goo.gl/abc',
    'misto-eventu-text' => 'Google maps',
    'jazyk-eventu' => 'English',
    'organizator-eventu' => 'Anna Nováková',
    'fotka-organizatora-eventu' => 6300,
    'url-eventu' => 'https://forms.gle/x',
], $m['fields']);
check('map bez upozornení', [], $m['warnings']);

$winter = array_merge($event, [
    'description' => 'Bez konvencií',
    'location' => '',
    'creator' => ['email' => 'someone@example.org'],
    'start' => ['dateTime' => '2026-12-01T18:00:00+01:00'],
    'end' => ['dateTime' => '2026-12-01T20:00:00+01:00'],
]);
$m = eags_map_event($winter, $known, $tz, $organizers);
check('map zima čas', '18:00:00', $m['fields']['cas-eventu']);
check('map predvolený jazyk', 'Česky, English', $m['fields']['jazyk-eventu']);
check('map predvolený organizátor', ['EA Czechia', 4492], [$m['fields']['organizator-eventu'], $m['fields']['fotka-organizatora-eventu']]);
check('map bez mapy', '', $m['fields']['misto-eventu-text']);
check('map upozornenie jazyk', 1, count($m['warnings']));

$override = array_merge($event, ['description' => "Organizátor: Petr Novák\nMísto eventu: Knihovna\nJazyk: klingonsky"]);
$m = eags_map_event($override, $known, $tz, $organizers);
check('map prepis organizátora', 'Petr Novák', $m['fields']['organizator-eventu']);
check('map prepis miesta z popisu', 'Knihovna', $m['fields']['misto-eventu']);
check('map neznámy jazyk upozornenie', true, strpos($m['warnings'][0], 'klingonsky') !== false);

$m = eags_map_event(array_merge($event, ['summary' => '']), $known, $tz, $organizers);
check('map prázdny názov', '(bez názvu)', $m['title']);

echo $failures === 0 ? "OK ($count testov)\n" : "$failures z $count testov zlyhalo\n";
exit($failures === 0 ? 0 : 1);
