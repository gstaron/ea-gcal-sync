<?php
/**
 * Aktualizácie z GitHub releases: WordPress ukáže bežné „Je dostupná nová verzia“
 * a aktualizuje jedným kliknutím. Vyžaduje verejný repozitár a v release asset
 * ea-gcal-sync.zip (vytvára ho .github/workflows/release.yml).
 */

defined('ABSPATH') || exit;

if (EAGS_GITHUB_REPO === '') {
    return;
}

/**
 * @return array{version: string, zip: string, url: string}|null
 */
function eags_latest_release(bool $force = false): ?array
{
    $cached = $force ? false : get_site_transient('eags_release');
    if (is_array($cached)) {
        return $cached ?: null;
    }

    $response = wp_remote_get('https://api.github.com/repos/' . EAGS_GITHUB_REPO . '/releases/latest', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json'],
    ]);
    $release = [];
    if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === EAGS_SLUG . '.zip') {
                $release = [
                    'version' => ltrim((string) $data['tag_name'], 'vV'),
                    'zip' => (string) $asset['browser_download_url'],
                    'url' => (string) $data['html_url'],
                ];
                break;
            }
        }
    }
    // Aj neúspech cacheujeme, aby sme nenarážali na limit GitHub API (60 požiadaviek/h z IP).
    set_site_transient('eags_release', $release, 6 * HOUR_IN_SECONDS);
    return $release ?: null;
}

add_filter('pre_set_site_transient_update_plugins', static function ($transient) {
    if (!is_object($transient) || empty($transient->checked)) {
        return $transient;
    }
    $release = eags_latest_release();
    if (!$release) {
        return $transient;
    }
    $plugin = plugin_basename(EAGS_FILE);
    $item = (object) [
        'id' => EAGS_SLUG,
        'slug' => EAGS_SLUG,
        'plugin' => $plugin,
        'new_version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['zip'],
    ];
    if (version_compare($release['version'], EAGS_VERSION, '>')) {
        $transient->response[$plugin] = $item;
    } else {
        $item->new_version = EAGS_VERSION;
        $transient->no_update[$plugin] = $item;
    }
    return $transient;
});

// Okno „Zobraziť podrobnosti“ pri aktualizácii.
add_filter('plugins_api', static function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== EAGS_SLUG) {
        return $result;
    }
    $release = eags_latest_release();
    return (object) [
        'name' => 'EA GCal Sync',
        'slug' => EAGS_SLUG,
        'version' => $release['version'] ?? EAGS_VERSION,
        'homepage' => $release['url'] ?? 'https://github.com/' . EAGS_GITHUB_REPO,
        'download_link' => $release['zip'] ?? '',
        'sections' => ['changelog' => '<p><a href="' . esc_url($release['url'] ?? '') . '" target="_blank">Poznámky k verzii na GitHube</a></p>'],
    ];
}, 10, 3);

// Po aktualizácii zahodiť cache, aby sa ďalšia verzia ukázala hneď.
add_action('upgrader_process_complete', static function ($upgrader, array $options): void {
    if (($options['type'] ?? '') === 'plugin') {
        delete_site_transient('eags_release');
    }
}, 10, 2);
