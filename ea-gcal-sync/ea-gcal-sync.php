<?php
/**
 * Plugin Name:       EA GCal Sync
 * Description:       Importuje udalosti zo zdieľaného Google kalendára do príspevkov all-events (ACF). Bez Make.com, bez prichádzajúcich požiadaviek na WordPress.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            EA Czechia
 * License:           GPL-2.0-or-later
 * Text Domain:       ea-gcal-sync
 */

defined('ABSPATH') || exit;

define('EAGS_VERSION', '0.1.0');
define('EAGS_FILE', __FILE__);
define('EAGS_SLUG', 'ea-gcal-sync');
define('EAGS_CRON_HOOK', 'eags_sync');
define('EAGS_OPT_SETTINGS', 'eags_settings');
define('EAGS_OPT_LOG', 'eags_log');
define('EAGS_OPT_LAST_SYNC', 'eags_last_sync');
define('EAGS_ORGANIZERS_ID', 'eags_organizers'); // ACF "post_id" stránky Organizátori

// Typ príspevku akcií – voliteľný v Nastavenia → EA GCal Sync (predvolene all-events).
if (!defined('EAGS_POST_TYPE')) {
    define('EAGS_POST_TYPE', (string) get_option('eags_post_type', 'all-events'));
}

// GitHub repo s releasmi, napr. 'ea-czechia/ea-gcal-sync'. Prázdne = automatické aktualizácie vypnuté.
if (!defined('EAGS_GITHUB_REPO')) {
    define('EAGS_GITHUB_REPO', '');
}

require_once __DIR__ . '/includes/parse.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/google.php';
require_once __DIR__ . '/includes/fields.php';
require_once __DIR__ . '/includes/organizers.php';
require_once __DIR__ . '/includes/sync.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/metabox.php';
require_once __DIR__ . '/includes/updater.php';

register_activation_hook(__FILE__, 'eags_schedule');
register_deactivation_hook(__FILE__, 'eags_unschedule');

// Aktualizácia nahraním ZIP nespúšťa aktivačný hook – plán si preto obnovíme sami.
add_action('init', 'eags_schedule');
add_action(EAGS_CRON_HOOK, 'eags_sync');

function eags_schedule(): void
{
    if (!wp_next_scheduled(EAGS_CRON_HOOK)) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', EAGS_CRON_HOOK);
    }
}

function eags_unschedule(): void
{
    wp_clear_scheduled_hook(EAGS_CRON_HOOK);
}
