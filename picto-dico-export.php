<?php
/**
 * Plugin Name: Export Médias CSV
 * Description: Exportez les titres des médias en CSV avec option d'exclusion de catégories.
 * Version: 1.0
 * Author: Antigravity
 */

if (!defined('ABSPATH')) {
    exit;
}

class Picto_Dico_Export {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_export'));
    }

    public function add_admin_menu() {
        add_media_page(
            'Export CSV',
            'Export CSV',
            'manage_options',
            'media-export-csv',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        $categories = get_categories(array('hide_empty' => 0));
        ?>
        <div class="wrap">
            <h1>Export des titres des médias</h1>
            <form method="post" action="">
                <?php wp_nonce_field('picto_dico_export_action', 'picto_dico_export_nonce'); ?>
                
                <h2>Exclure des catégories</h2>
                <p>Sélectionnez les catégories à exclure de l'export. Les médias attachés à des articles de ces catégories (ou ayant ces catégories si activé pour les médias) seront ignorés.</p>
                
                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff; margin-bottom: 20px;">
                    <?php foreach ($categories as $category) : ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="exclude_cats[]" value="<?php echo esc_attr($category->term_id); ?>">
                            <?php echo esc_html($category->name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <input type="submit" name="picto_dico_export_submit" class="button button-primary" value="Télécharger l'export CSV">
            </form>
        </div>
        <?php
    }

    public function handle_export() {
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

        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $attachment_ids = get_posts($args);
        $data = array();
        $data[] = array('ID', 'Titre', 'Nom du fichier', 'URL');

        foreach ($attachment_ids as $id) {
            $exclude = false;

            // 1. Check if the attachment itself has the excluded categories (if taxonomy is registered for media)
            $terms = wp_get_post_categories($id);
            if (!empty($terms) && !empty(array_intersect($terms, $excluded_cats))) {
                $exclude = true;
            }

            // 2. Check parent post categories if not already excluded
            if (!$exclude) {
                $post = get_post($id);
                if ($post->post_parent) {
                    $parent_terms = wp_get_post_categories($post->post_parent);
                    if (!empty($parent_terms) && !empty(array_intersect($parent_terms, $excluded_cats))) {
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

    private function generate_csv($data) {
        $filename = 'export-medias-' . date('Y-m-d-H-i') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        foreach ($data as $row) {
            fputcsv($output, $row, ';');
        }

        fclose($output);
    }
}

new Picto_Dico_Export();
