<?php
/**
 * Organizátori (e-mail v kalendári → meno + fotka) ako ACF Options stránka.
 * Polia registruje plugin sám, admin ich v ACF nemusí zakladať.
 */

defined('ABSPATH') || exit;

add_action('acf/init', 'eags_register_organizers_page');

function eags_register_organizers_page(): void
{
    if (!function_exists('acf_add_options_page') || !function_exists('acf_add_local_field_group')) {
        return; // ACF bez Pro – použije sa len predvolený organizátor
    }

    acf_add_options_page([
        'page_title' => 'Organizátori akcií',
        'menu_title' => 'Organizátori',
        'menu_slug' => 'eags-organizatori',
        'parent_slug' => 'edit.php?post_type=' . EAGS_POST_TYPE,
        'capability' => 'edit_others_posts',
        'post_id' => EAGS_ORGANIZERS_ID,
        'autoload' => false,
        'updated_message' => 'Organizátori uložení.',
    ]);

    acf_add_local_field_group([
        'key' => 'group_eags_organizers',
        'title' => 'Organizátori akcií',
        'fields' => [
            [
                'key' => 'field_eags_intro',
                'label' => '',
                'name' => '',
                'type' => 'message',
                'message' => 'Autor udalosti v Google kalendári sa hľadá podľa e-mailu. Kto tu nie je, dostane predvoleného organizátora. Meno sa dá v popise udalosti prepísať riadkom „Organizátor: Meno“.',
            ],
            [
                'key' => 'field_eags_default_name',
                'label' => 'Predvolený organizátor',
                'name' => 'predvoleny_organizator',
                'type' => 'text',
                'default_value' => 'EA Czechia',
            ],
            [
                'key' => 'field_eags_default_photo',
                'label' => 'Predvolená fotka',
                'name' => 'predvolena_fotka',
                'type' => 'image',
                'return_format' => 'id',
                'preview_size' => 'thumbnail',
            ],
            [
                'key' => 'field_eags_organizers',
                'label' => 'Organizátori',
                'name' => 'organizatori',
                'type' => 'repeater',
                'layout' => 'block',
                'button_label' => 'Pridať organizátora',
                'sub_fields' => [
                    [
                        'key' => 'field_eags_org_email',
                        'label' => 'E-mail v Google kalendári',
                        'name' => 'email',
                        'type' => 'email',
                        'required' => 1,
                    ],
                    [
                        'key' => 'field_eags_org_name',
                        'label' => 'Meno na webe',
                        'name' => 'meno',
                        'type' => 'text',
                        'required' => 1,
                    ],
                    [
                        'key' => 'field_eags_org_photo',
                        'label' => 'Fotka',
                        'name' => 'fotka',
                        'type' => 'image',
                        'return_format' => 'id',
                        'preview_size' => 'thumbnail',
                    ],
                ],
            ],
        ],
        'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'eags-organizatori']]],
    ]);
}

/**
 * @param mixed $image ACF image hodnota (ID, pole alebo prázdne)
 */
function eags_image_id($image): int
{
    if (is_array($image)) {
        return (int) ($image['ID'] ?? $image['id'] ?? 0);
    }
    return is_numeric($image) ? (int) $image : 0;
}

/**
 * @return array{0: string, 1: int} meno, ID fotky (0 = bez fotky)
 */
function eags_find_organizer(string $creator_email, string $name_override = ''): array
{
    $rows = function_exists('get_field') ? get_field('organizatori', EAGS_ORGANIZERS_ID) : [];
    $rows = is_array($rows) ? $rows : [];

    // 1. meno z popisu udalosti, 2. e-mail autora udalosti
    if ($name_override !== '') {
        foreach ($rows as $row) {
            if (eags_normalize_key((string) ($row['meno'] ?? '')) === eags_normalize_key($name_override)) {
                return [(string) $row['meno'], eags_image_id($row['fotka'] ?? 0)];
            }
        }
    } elseif ($creator_email !== '') {
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['email'] ?? '')), trim($creator_email)) === 0) {
                return [(string) $row['meno'], eags_image_id($row['fotka'] ?? 0)];
            }
        }
    }

    $default_name = function_exists('get_field') ? (string) get_field('predvoleny_organizator', EAGS_ORGANIZERS_ID) : '';
    $default_photo = function_exists('get_field') ? eags_image_id(get_field('predvolena_fotka', EAGS_ORGANIZERS_ID)) : 0;
    $name = $name_override !== '' ? $name_override : ($default_name !== '' ? $default_name : 'EA Czechia');
    return [$name, $default_photo];
}
