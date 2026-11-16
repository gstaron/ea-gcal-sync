<?php
/**
 * Nastavenia a log (oboje vo wp_options, bez autoloadu).
 */

defined('ABSPATH') || exit;

/**
 * @return array{calendar_id: string, sa_json: string, post_type: string, new_status: string, notify_email: string, notify_new: int}
 */
function eags_settings(): array
{
    $defaults = [
        'calendar_id' => '',
        'sa_json' => '',
        'post_type' => 'all-events',
        'new_status' => 'draft',
        'notify_email' => (string) get_option('admin_email'),
        'notify_new' => 1,
    ];
    $saved = get_option(EAGS_OPT_SETTINGS, []);
    $settings = array_merge($defaults, is_array($saved) ? $saved : []);
    $settings['post_type'] = (string) get_option('eags_post_type', 'all-events');
    return $settings;
}

function eags_update_settings(array $settings): void
{
    // Typ príspevku je v malej autoloadovanej voľbe – číta sa pri každom načítaní pluginu.
    update_option('eags_post_type', $settings['post_type'] ?? 'all-events', true);
    unset($settings['post_type']);
    update_option(EAGS_OPT_SETTINGS, $settings, false);
}

/**
 * @param string $level ok|info|warn|error
 */
function eags_log(string $level, string $message): void
{
    $log = get_option(EAGS_OPT_LOG, []);
    $log = is_array($log) ? $log : [];
    array_unshift($log, ['t' => time(), 'level' => $level, 'msg' => $message]);
    update_option(EAGS_OPT_LOG, array_slice($log, 0, 100), false);
}

/**
 * @return array<int, array{t: int, level: string, msg: string}>
 */
function eags_get_log(): array
{
    $log = get_option(EAGS_OPT_LOG, []);
    return is_array($log) ? $log : [];
}
