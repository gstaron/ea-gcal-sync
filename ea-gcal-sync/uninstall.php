<?php
/**
 * Odinštalovanie: zmaže nastavenia, log a cache.
 * Meta _gcal_event_id na príspevkoch ostáva, aby opätovná inštalácia nevytvorila duplikáty.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('eags_settings');
delete_option('eags_log');
delete_option('eags_last_sync');
delete_option('eags_post_type');
delete_transient('eags_running');
delete_transient('eags_error_mailed');
delete_site_transient('eags_release');
wp_clear_scheduled_hook('eags_sync');

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eags\\_token\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eags\\_token\\_%'");
