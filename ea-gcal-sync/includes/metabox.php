<?php
/**
 * Box „Google kalendár“ v editore akcie: odkiaľ príspevok pochádza,
 * ktoré polia sú ručne upravené (synchronizácia ich neprepíše) a odpojenie.
 */

defined('ABSPATH') || exit;

add_action('add_meta_boxes_' . EAGS_POST_TYPE, static function (WP_Post $post): void {
    if (!get_post_meta($post->ID, EAGS_META_EVENT_ID, true)) {
        return;
    }
    add_meta_box('eags-gcal', 'Google kalendár', 'eags_render_metabox', EAGS_POST_TYPE, 'side', 'default');
});

/**
 * Polia, ktoré sa líšia od poslednej importovanej hodnoty = ručne upravené.
 *
 * @return string[] labely polí
 */
function eags_manually_edited_fields(int $post_id): array
{
    $edited = [];
    $src_title = get_post_meta($post_id, EAGS_META_SRC_PREFIX . 'title', true);
    if ($src_title !== '' && $src_title !== get_post_field('post_title', $post_id, 'raw')) {
        $edited[] = 'Názov';
    }
    foreach (eags_acf_fields() as $name => $field) {
        $meta_key = EAGS_META_SRC_PREFIX . $name;
        if (!metadata_exists('post', $post_id, $meta_key)) {
            continue;
        }
        $current = get_field($field['key'], $post_id, false);
        if ((is_scalar($current) ? (string) $current : '') !== (string) get_post_meta($post_id, $meta_key, true)) {
            $edited[] = (string) $field['label'];
        }
    }
    return $edited;
}

function eags_render_metabox(WP_Post $post): void
{
    wp_nonce_field('eags_metabox', 'eags_metabox_nonce');
    $detached = (bool) get_post_meta($post->ID, EAGS_META_DETACHED, true);
    $edited = eags_manually_edited_fields($post->ID);

    echo '<p>Príspevok vznikol z udalosti v Google kalendári a pri zmene udalosti sa aktualizuje.</p>';
    if ($edited) {
        echo '<p><strong>Ručne upravené (synchronizácia ich neprepíše):</strong><br>' . esc_html(implode(', ', $edited)) . '</p>';
    }
    printf(
        '<p><label><input type="checkbox" name="eags_detached" value="1" %s> Odpojiť od kalendára – synchronizácia tento príspevok už nezmení</label></p>',
        checked($detached, true, false)
    );
}

add_action('save_post_' . EAGS_POST_TYPE, static function (int $post_id): void {
    if (!isset($_POST['eags_metabox_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['eags_metabox_nonce'])), 'eags_metabox')) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
        return;
    }
    if (!empty($_POST['eags_detached'])) {
        update_post_meta($post_id, EAGS_META_DETACHED, 1);
    } else {
        delete_post_meta($post_id, EAGS_META_DETACHED);
    }
});
