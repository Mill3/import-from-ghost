<?php
/*
Plugin Name: Ghost Importer
Plugin URI: http://tysonarmstrong.com
Description: Import blog posts from a Ghost blog export file
Author: Tyson Armstrong
Author URI: http://work.tysonarmstrong.com
Version: 0.1.0
Text Domain: ghost-importer
*/

if (! defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

// composer autoload class
require_once(__DIR__ . '/vendor/autoload.php');

use Sunra\PhpSimple\HtmlDomParser;

/**
 * Returns the main instance of Ghost Importer to prevent the need to use globals.
 */
function Ghost_Importer()
{
    return Ghost_Importer::instance();
}
add_action('plugins_loaded', 'Ghost_Importer');
register_activation_hook(__FILE__, array( 'Ghost_Importer', 'install' ));
register_deactivation_hook(__FILE__, array( 'Ghost_Importer', 'uninstall' ));
require_once(ABSPATH . 'wp-admin/includes/image.php');

class Ghost_Importer
{
    private static $_instance = null;

    private $ghost_url = null;
    private $tags = array();
    private $json = false;
    private $dryrun = false;
    private $tablename = "ghostimporter_log";
    private $import_id = null;
    private $up_to = 0;
    private $total_posts = 0;

    public function __construct()
    {
        add_action('admin_menu', array($this,'addAdminPage'));
        add_action('wp_ajax_ghost_importer_trigger', array($this,'ghost_importer_ajax_import'));
        add_action('wp_ajax_ghost_importer_log', array($this,'ghost_importer_get_log_progress'));
    }

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public static function install()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "ghostimporter_log";

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          import_id int(10) NOT NULL,
          time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
          text text NOT NULL,
          is_error tinyint(1) DEFAULT 0 NOT NULL,
          up_to mediumint(9) DEFAULT 0,
          total_posts mediumint(9) DEFAULT 0,
          finished tinyint(1) DEFAULT 0,
          PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function uninstall()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "ghostimporter_log";
        $sql = "DROP TABLE IF EXISTS $table_name;";
        $wpdb->query($sql);
    }

    public function ghost_importer_get_log_progress()
    {
        global $wpdb; // this is how you get access to the database
        $import_id = intval($_POST['gi_import_id']);
        $last_log_id = intval($_POST['gi_last_log_id']);
        $results = $wpdb->get_results('SELECT * from '.$wpdb->prefix.'ghostimporter_log WHERE import_id = "'.$import_id.'" AND id > "'.$last_log_id.'"');

        echo json_encode($results);
        wp_die(); // this is required to terminate immediately and return a proper response
    }

    public function ghost_importer_ajax_import()
    {
        // Check file exists and nonce is valid
        if (!isset($_POST['gi_import_id'])) {
            wp_die();
        }
        $this->import_id = $_POST['gi_import_id'];
        $importfile = $_POST['gi_import_file'];
        $this->ghost_url = htmlspecialchars_decode($_POST['gi_ghost_url']);
        if ($_POST['gi_dryrun'] == "1") {
            $this->dryrun = true;
        }
        check_ajax_referer('gi-trigger-import', 'gi-trigger-import');

        // All good, let's start
        $this->runImport($importfile);
    }

    public function addAdminPage()
    {
        add_submenu_page('tools.php', 'Ghost Importer', 'Ghost Importer', 'manage_options', 'ghost_importer', array($this,'showAdminPage'));
    }

    public function showAdminPage()
    {
        echo '<div class="wrap">
                <h2>Ghost Importer</h2>';
        if (isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'ghost_json_import')) {
            $dryrun = (isset($_POST['ghost_dryrun']) && $_POST['ghost_dryrun'] == "on") ? true : false;
            $this->import_id = time();
            $importfile = $this->fileUpload();
            $ghost_url = htmlspecialchars($_POST['ghost_url']);
            if ($importfile) {
                echo '<h3 class="gi-running-import" data-gi-trigger-import="'.$this->import_id.'" data-gi-dryrun="'.$dryrun.'" data-gi-import-file="'.$importfile.'" data-gi-nonce="'.wp_create_nonce('gi-trigger-import').'" data-gi-ghost-url="'.$ghost_url.'">Running import. <span class="gi-progress" style="display:none;"><span class="gi-upto">0</span> of <span class="gi-totalposts">0</span> posts processed.</span></h3><h3 class="gi-finished-import" style="display: none;color:#4BB543;">Import completed. <span class="gi-upto"></span> posts imported.</h3><h3 class="gi-errored-import" style="display:none;">Error during import. Check the log below for information.</h3><div class="gi-progress-log"></div>';
                echo '<style>.gi-log-entry.error-msg{color:red}</style>';
                wp_register_script('ghost_importer', plugin_dir_url(__FILE__) . 'ghost-importer.js', array('jquery'), '1.0', true);
                wp_enqueue_script('ghost_importer');
            }
        } else {
            echo '<form class="" method="post" enctype="multipart/form-data">';
            wp_nonce_field('ghost_json_import');
            echo '  <p>
                        <label for="ghost_json_file">Select your Ghost JSON export file:</label><br>
                        <input name="ghost_json_file" id="ghost_json_file" type="file" value="">
                    </p>
                    <p>
                        <label for="ghost_url">Enter your Ghost blog URL (for downloading images)</label><br>
                        <input name="ghost_url" id="ghost_url" type="text" value="" placeholder="http://yourghostblog.com">
                    </p>
                    <p><input type="checkbox" name="ghost_dryrun" id="ghost_dryrun"/> <label for="ghost_dryrun">Perform a dry run</label></p>
                    <p class="submit">
                        <input type="submit" class="button" name="submit" value="Next">
                    </p>
                </form>';
        }
        echo '</div>';
    }

    public function fileUpload()
    {
        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'];
        if (isset($_FILES['ghost_json_file'])) {
            if ($_FILES['ghost_json_file']['error'] == UPLOAD_ERR_OK) {
                $name = basename($_FILES['ghost_json_file']['name']);
                $saveto = $upload_dir.'/'.$name;
                move_uploaded_file($_FILES['ghost_json_file']['tmp_name'], $saveto);
                return $saveto;
            } else {
                $this->error('There was a problem saving your uploaded file.');
            }
        }
        return false;
    }

    public function runImport($importfile)
    {
        $this->json = json_decode(file_get_contents($importfile));
        if (!$this->json) {
            $this->error('Please check your export file is valid JSON and try again.');
        }

        $this->total_posts = count($this->json->db[0]->data->posts);

        // Import blog settings
        if (isset($this->json->db[0]->data->settings) && count($this->json->db[0]->data->settings)) {
            foreach ($this->json->db[0]->data->settings as $setting) {
                $this->importSetting($setting);
            }
        }

        // Generate array of tags
        if (isset($this->json->db[0]->data->tags) && count($this->json->db[0]->data->tags)) {
            foreach ($this->json->db[0]->data->tags as $tag) {
                $this->tags[$tag->id] = $tag->name;
            }
        }

        // Import posts, tags & images
        if (isset($this->json->db[0]->data->posts) && count($this->json->db[0]->data->posts)) {
            $i = 1;
            foreach ($this->json->db[0]->data->posts as $post) {
                $this->up_to = $i++;
                $this->importPost($post);
            }
        } else {
            $this->error('No posts found in your export file.');
        }

        unlink($importfile);
        $this->log('Import completed.', 0, 1);
    }

    private function importSetting($setting)
    {
        switch ($setting->key) {
            case "title":
                $this->log("Setting blog name to '".$setting->value."'");
                if (!$this->dryrun) {
                    update_option('blogname', $setting->value);
                }
                break;

            case "description":
                $this->log("Setting blog description to '".$setting->value."'");
                if (!$this->dryrun) {
                    update_option('blogdescription', $setting->value);
                }
                break;

            case "postsPerPage":
                $this->log("Setting posts-per-page to '".$setting->value."'");
                if (!$this->dryrun) {
                    update_option('posts_per_page', $setting->value);
                }
                break;

            // TODO: "permalinks"

            case "activeTimezone":
                $this->log("Setting timezone to '".$setting->value."'");
                if (!$this->dryrun) {
                    update_option('timezone_string', $setting->value);
                }
                break;
        }
    }

    private function importPost($post)
    {
        $status = ($post->status == "published") ? 'publish' : $post->status;

        $newpost = array(
            'ID'=>0,
            'post_date'=>$post->published_at,
            'post_content'=> $post->html,
            'post_title'=>$post->title,
            'post_status'=>$status,
            'post_type'=>'post',
            'post_name'=>$post->slug,
            'tags_input'=>$this->getTagsForPost($post->id),
            'meta_input'=>array()
        );

        // go to next if post exists
        if (get_page_by_path($post->slug, OBJECT, 'post')) {
            $this->log("Found post with slug <strong>'$post->slug'</strong>");
            return true;
        } else {
            $this->log("No post found with slug <strong>'$post->slug'</strong>.. Moving on!");
        }

        if ($post->meta_description) {
            $newpost['meta_input']['_yoast_wpseo_metadesc'] = $post->meta_description;
        }
        if ($post->meta_title) {
            $newpost['meta_input']['_yoast_wpseo_title'] = $post->meta_title;
        }

        $this->log("Adding post '$post->title'");
        if (!$this->dryrun) {
            $inserted = wp_insert_post($newpost);
        } else {
            $inserted = 983;
        }

        if (!$this->dryrun && $post->image && $inserted) {
            $attach_id = $this->uploadRemoteImageAndAttach($this->ghost_url . $post->image, $inserted);
            set_post_thumbnail( $inserted, $attach_id );
        }

        if (is_wp_error($inserted)) {
            $this->error("We couldn't insert post \"$post->title\"");
        }

        // Copy images and update content
        $this->migrateImages($inserted);

        return true;
    }

    private function getTagsForPost($id)
    {
        $tags = array();
        foreach ($this->json->db[0]->data->posts_tags as $post_tag) {
            if ($post_tag->post_id !== $id) {
                continue;
            }
            foreach ($this->json->db[0]->data->tags as $tag) {
                if ($tag->id !== $post_tag->tag_id) {
                    continue;
                }
                $tags[] = $tag->name;
            }
        }
        return $tags;
    }

    private function uploadRemoteImageAndAttach($image_url, $parent_id)
    {
        $this->log("Importing image : " . $image_url);
        $image = $image_url;

        $get = wp_remote_get($image);
        $type = wp_remote_retrieve_header($get, 'content-type');

        if (!$type) {
            $this->log("Error downloading image");
            return false;
        }

        $mirror = wp_upload_bits(basename($image), '', wp_remote_retrieve_body($get));
        $attachment = array(
            'post_title'=> basename($image),
            'post_mime_type' => $type
        );
        $attach_id = wp_insert_attachment($attachment, $mirror['file'], $parent_id);
        $attach_data = wp_generate_attachment_metadata($attach_id, $mirror['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    private function migrateImages($postID)
    {
        $post = get_post($postID);
        if ($post && !$this->dryrun) {
            $content = $post->post_content;
            $html = HtmlDomParser::str_get_html($content);

            // find img tags
            if ($html) {
                foreach ($html->find('img') as $key => $img) {
                    set_time_limit(30);
                    $attach_id = $this->uploadRemoteImageAndAttach($this->ghost_url . $img->src, $postID);
                    if ($attach_id) {
                        $this->log("Imported new image for post : ". $attach_id);
                        $newImageSRC = wp_get_attachment_image_src($attach_id, "large")[0];
                        $content = str_replace($img->src, $newImageSRC, $content);
                        wp_update_post(
                            array(
                                'ID'=>$post->ID,
                                'post_content'=>$content
                            )
                        );
                    }

                }
            }
        }
    }

    private function error($msg)
    {
        $this->log($msg, 1, 1);
        die($msg);
    }

    private function log($msg, $error=0, $finished=0)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->tablename;
        $wpdb->insert($table_name, array(
            'import_id'=>$this->import_id,
            'text'=>$msg,
            'is_error'=>$error,
            'up_to'=>$this->up_to,
            'total_posts'=>$this->total_posts,
            'finished'=>$finished
            ), array(
            '%d',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d'
            ));
    }

}
