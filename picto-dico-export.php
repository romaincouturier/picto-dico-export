<?php
/**
 * Plugin Name: Picto Dico Export CSV
 * Plugin URI:  https://github.com/romaincouturier/picto-dico-export
 * Description: Un outil professionnel pour exporter les titres de vos médias au format CSV. Comprend des options avancées pour exclure des catégories spécifiques de l'export.
 * Version:     1.0.1
 * Author:      Romain Couturier, Antigravity (Google DeepMind)
 * Author URI:  https://www.supertilt.fr
 * License:     GPL2
 * Text Domain: picto-dico-export
 */

if (!defined('ABSPATH')) {
    exit;
}

class Picto_Dico_Export
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_export'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
    }

    public function add_settings_link($links)
    {
        $settings_link = '<a href="upload.php?page=media-export-csv">' . __('Aller vers l\'extension', 'picto-dico-export') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function add_admin_menu()
    {
        add_media_page(
            'Export CSV',
            'Export CSV',
            'manage_options',
            'media-export-csv',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page()
    {
        $categories = get_categories(array('hide_empty' => 0));
        ?>
        <div class="wrap">
            <h1>Export des titres des médias</h1>
            <form method="post" action="">
                <?php wp_nonce_field('picto_dico_export_action', 'picto_dico_export_nonce'); ?>

                <h2>Exclure des catégories</h2>
                <p>Sélectionnez les catégories à exclure de l'export. Les médias attachés à des articles de ces catégories (ou
                    ayant ces catégories si activé pour les médias) seront ignorés.</p>

                <div
                    style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff; margin-bottom: 20px;">
                    <?php foreach ($categories as $category): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="exclude_cats[]" value="<?php echo esc_attr($category->term_id); ?>">
                            <?php echo esc_html($category->name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <input type="submit" name="picto_dico_export_submit" class="button button-primary"
                    value="Télécharger l'export CSV">
            </form>
        </div>
        <?php
    }

    public function handle_export()
    {
        if (!isset($_POST['picto_dico_export_submit'])) {
            return;
        }

        if (!isset($_POST['picto_dico_export_nonce']) || !wp_verify_nonce($_POST['picto_dico_export_nonce'], 'picto_dico_export_action')) {
            wp_die('Action non autorisée.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Droit insuffisant.');
        }

        $excluded_cats = isset($_POST['exclude_cats']) ? array_map('intval', $_POST['exclude_cats']) : array();

        // Get the uncategorized term ID
        $uncategorized_term = get_term_by('slug', 'uncategorized', 'category');
        $uncategorized_id = $uncategorized_term ? $uncategorized_term->term_id : 1;

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        $attachment_ids = get_posts($args);
        $data = array();
        $data[] = array('ID', 'Titre', 'Nom du fichier', 'URL');

        foreach ($attachment_ids as $id) {
            $exclude = false;

            // 1. Check if the attachment itself has the excluded categories
            $terms = wp_get_post_terms($id, 'category', array('fields' => 'ids'));
            if (!is_wp_error($terms) && !empty($terms)) {
                $term_count = count($terms);

                // Special handling if uncategorized (Non classé) is in excluded list
                if (in_array($uncategorized_id, $excluded_cats)) {
                    // If attachment has ONLY uncategorized → include it (don't exclude)
                    if ($term_count === 1 && in_array($uncategorized_id, $terms)) {
                        // Keep exclude = false
                    } elseif ($term_count > 1 && in_array($uncategorized_id, $terms)) {
                        // Has uncategorized + other categories → exclude
                        $exclude = true;
                    } else {
                        // Check other excluded categories (without uncategorized)
                        $other_excluded = array_diff($excluded_cats, array($uncategorized_id));
                        if (!empty($other_excluded) && !empty(array_intersect($terms, $other_excluded))) {
                            $exclude = true;
                        }
                    }
                } else {
                    // Normal exclusion logic
                    if (!empty(array_intersect($terms, $excluded_cats))) {
                        $exclude = true;
                    }
                }
            }

            // 2. Check parent post categories if not already excluded
            if (!$exclude) {
                $post = get_post($id);
                if ($post->post_parent) {
                    $parent_terms = wp_get_post_terms($post->post_parent, 'category', array('fields' => 'ids'));
                    if (!is_wp_error($parent_terms) && !empty($parent_terms) && !empty(array_intersect($parent_terms, $excluded_cats))) {
                        $exclude = true;
                    }
                }
            }

            if (!$exclude) {
                $data[] = array(
                    $id,
                    get_the_title($id),
                    basename(get_attached_file($id)),
                    wp_get_attachment_url($id)
                );
            }
        }

        $this->generate_csv($data);
        exit;
    }

    private function generate_csv($data)
    {
        $filename = 'export-medias-' . date('Y-m-d-H-i') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        foreach ($data as $row) {
            fputcsv($output, $row, ';');
        }

        fclose($output);
    }
}

new Picto_Dico_Export();
