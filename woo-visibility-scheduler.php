<?php
/**
 * Plugin Name: WooCommerce Product Visibility Scheduler
 * Description: Scheduled change of product visibility from private visibility to public and from draft state to public
 * Version: 1.0.1
 * Author: Brigitta Varga
 */

// Biztonsági ellenőrzés
if (!defined('ABSPATH')) {
    exit;
}

// Plugin osztály definíció
class WC_Product_Visibility_Scheduler {
    private $log_enabled = true;
    private static $instance = null;
    private $log_file;

    /**
     * Singleton instance létrehozása/lekérése
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        // Log fájl inicializálása
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/visibility-scheduler-log.txt';

        // Admin menü és meta boxok
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_scheduling_meta_box'));
        add_action('save_post', array($this, 'save_scheduling_meta'));


        // Admin értesítések
        add_action('admin_notices', array($this, 'display_admin_notices'));

        // Admin assets és cron ellenőrzés
        add_action('admin_init', array($this, 'ensure_cron_is_scheduled'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // WooCommerce beállítások
        add_filter('woocommerce_get_settings_pages', array($this, 'add_wc_settings_tab'));

        // AJAX handlers
        add_action('wp_ajax_delete_product_schedule', array($this, 'handle_delete_schedule'));

           // Cron intervallum hozzáadása
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_interval'));

        
        // Cron hook hozzáadása
        add_action('visibility_scheduler_cron', array($this, 'process_scheduled_changes'));
  
}

    /**
     * Clone tiltása
     */
    public function __clone() {
        _doing_it_wrong(__FUNCTION__, __('Nem másolható a Visibility Scheduler instance.', 'woocommerce'), '1.0');
    }

    /**
     * Unserialize tiltása
     */
    public function __wakeup() {
        _doing_it_wrong(__FUNCTION__, __('Nem szerializálható a Visibility Scheduler instance.', 'woocommerce'), '1.0');
    }

    // További osztály metódusok...

    public function add_wc_settings_tab($settings) {
        $settings[] = include plugin_dir_path(__FILE__) . 'includes/class-wc-settings-visibility-scheduler.php';
        return $settings;
    }

    public function enqueue_admin_assets($hook) {
        // Csak a plugin admin oldalán és a termék szerkesztő oldalon töltjük be
        if ($hook === 'toplevel_page_visibility-scheduler' || 
            $hook === 'post.php' || 
            $hook === 'post-new.php') {

            wp_enqueue_style(
                'visibility-scheduler-tailwind',
                plugin_dir_url(__FILE__) . 'assets/css/tailwind-local.css',
                array(),
                filemtime(plugin_dir_path(__FILE__) . 'assets/css/tailwind-local.css')
            );

            // Saját admin stílusok betöltése
            wp_enqueue_style(
                'visibility-scheduler-admin',
                plugin_dir_url(__FILE__) . 'assets/css/admin-styles.css',
                array('visibility-scheduler-tailwind'),
                filemtime(plugin_dir_path(__FILE__) . 'assets/css/admin-styles.css')
            );
        }
    }

    public function ensure_cron_is_scheduled() {
        $next_run = wp_next_scheduled('visibility_scheduler_cron');
        
        if (!$next_run) {
            wp_schedule_event(time(), 'every_15_minutes', 'visibility_scheduler_cron');
            $this->log_message('Cron ütemezés beállítva: every_15_minutes');
        }
    }

    public function display_admin_notices() {
        // Jogosultság ellenőrzés
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $notices = get_option('visibility_scheduler_notices', array());
        if (!empty($notices)) {
            foreach ($notices as $key => $notice) {
                // Csak az elmúlt 1 órában történt értesítéseket mutatjuk
                if (!get_user_meta(get_current_user_id(), 'dismissed_notice_' . sanitize_key($key), true)) {
                    if (time() - absint($notice['time']) < 3600) {
                        $message = isset($notice['message']) ? wp_kses_post($notice['message']) : '';
                        $type = isset($notice['type']) ? sanitize_key($notice['type']) : 'success';
                        
                        printf(
                            '<div class="notice notice-%s is-dismissible" data-notice-key="%s">',
                            esc_attr($type),
                            esc_attr($key)
                        );
                        echo '<p>' . wp_kses_post($message) . '</p>';
                        echo '</div>';
                    }
                }
            }
            // Töröljük a régi értesítéseket
            $new_notices = array_filter($notices, function($notice) {
                return time() - absint($notice['time']) < 3600;
            });
            update_option('visibility_scheduler_notices', $new_notices);
        }
    }

    private function add_admin_notice($message, $type = 'success') {
        if (!is_string($message)) {
            return;
        }

        $notices = get_option('visibility_scheduler_notices', array());
        $notice_key = sanitize_key('notice_' . time());
        $notices[$notice_key] = array(
            'message' => wp_kses_post($message),
            'time' => time(),
            'type' => sanitize_key($type)
        );
        update_option('visibility_scheduler_notices', $notices);
    }

    public static function activate_plugin() {
        global $wpdb;
    
        // Tábla létrehozása ha még nem létezik
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';
    
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
    
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                product_id bigint(20) NOT NULL,
                scheduled_time datetime NOT NULL,
                completed tinyint(1) DEFAULT 0,
                PRIMARY KEY  (id)
            ) $charset_collate;";
    
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    
     
    
        // Cron ütemezés beállítása
        if (!wp_next_scheduled('visibility_scheduler_cron')) {
            add_filter('cron_schedules', array(__CLASS__, 'add_cron_interval'));
            wp_schedule_event(time(), 'every_15_minutes', 'visibility_scheduler_cron');
            remove_filter('cron_schedules', array(__CLASS__, 'add_cron_interval'));
        }
    }

    public static function add_cron_interval($schedules) {
        $schedules['every_15_minutes'] = array(
            'interval' => 900,
            'display'  => '15 percenként'
        );
        return $schedules;
    }

    public static function deactivate_plugin() {
        // Cron ütemezés törlése
        $timestamp = wp_next_scheduled('visibility_scheduler_cron');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'visibility_scheduler_cron');
        }
    }

    public static function uninstall_plugin() {
        global $wpdb;
    
        // Minden cron törlése a hookra
        wp_clear_scheduled_hook('visibility_scheduler_cron');

        $delete_data = get_option('visibility_scheduler_delete_data', 'no');
        if ($delete_data !== 'yes') {
            return;
        }
    
        // Tábla törlése
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    
        // Opciók törlése
        delete_option('visibility_scheduler_notices');
        delete_option('visibility_scheduler_timezone'); // ezt is töröljük
    
        // Post meta törlése
        delete_post_meta_by_key('_schedule_type');
        delete_post_meta_by_key('_scheduled_visibility_change');
        delete_post_meta_by_key('_visibility_scheduler_timezone');
    
        // Log fájl törlése
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/visibility-scheduler-log.txt';
        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }

    private function find_main_product_id($post_id) {
        // Nézzük meg, van-e szülő posztja
        $parent_id = wp_get_post_parent_id($post_id);
        if ($parent_id) {
            // Ellenőrizzük a szülő státuszát
            $parent_status = get_post_status($parent_id);
            if ($parent_status === 'draft' || $parent_status === 'private') {
                return $parent_id;
            }
        }

        // Ha nincs megfelelő szülő, használjuk az eredeti posztot
        return $post_id;
    }

    public function process_scheduled_changes() {
        if (!is_admin() && !defined('DOING_CRON')) {
            return;
        }
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';
    
        $this->log_message('Ütemezett változtatások feldolgozása kezdődik...');
    
        $wp_timezone = new DateTimeZone(wp_timezone_string());
        $utc_timezone = new DateTimeZone('UTC');
        $current_time = new DateTime('now', $utc_timezone);
        
        $scheduled_changes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name 
                WHERE completed = 0 
                AND scheduled_time <= %s",
                $current_time->format('Y-m-d H:i:s')
            )
        );
    
        if (empty($scheduled_changes)) {
            $this->log_message('Nincs feldolgozandó ütemezett változtatás.');
            return;
        }
    
        $success_products = array();
        $failed_products = array();
    
        foreach ($scheduled_changes as $change) {
            $product = wc_get_product($change->product_id);
            if (!$product) {
                $this->log_message(sprintf('A termék nem található: %d', $change->product_id));
                $failed_products[] = sprintf('Termék ID: %d (nem található)', $change->product_id);
                continue;
            }
    
            $scheduled_time = new DateTime($change->scheduled_time, $utc_timezone);
            $scheduled_time->setTimezone($wp_timezone);
    
            $schedule_type = get_post_meta($change->product_id, '_schedule_type', true);
            if ($schedule_type !== 'visibility' && $schedule_type !== 'status') {
                $schedule_type = 'visibility';
            }
            
            try {
                // Először ellenőrizzük a jelenlegi státuszt
                $current_status = $product->get_status();
                $current_visibility = $product->get_catalog_visibility();
                
                $this->log_message(sprintf(
                    'Feldolgozás kezdése - Termék ID: %d, Jelenlegi státusz: %s, Jelenlegi láthatóság: %s',
                    $change->product_id,
                    $current_status,
                    $current_visibility
                ));
    
                if ($schedule_type === 'visibility') {
                    // Láthatóság változtatása
                    $product->set_status('publish');
                    $product->set_catalog_visibility('visible');
                    $product->set_featured(false);
                    
                    // Extra ellenőrzés a private post_status-hoz
                    wp_update_post(array(
                        'ID' => $change->product_id,
                        'post_status' => 'publish'
                    ));
                    
                    // WooCommerce termék cache törlése
                    wc_delete_product_transients($change->product_id);
                    
                } else {
                    // Státusz változtatása draft-ról publish-ra
                    $product->set_status('publish');
                }
    
                // Mentés és további cache törlés
                $product->save();
                
                // WordPress cache törlése
                clean_post_cache($change->product_id);
                wp_cache_delete($change->product_id, 'posts');
                
                // Ellenőrizzük a változtatás sikerességét
                $product = wc_get_product($change->product_id); // Újra betöltjük
                $new_status = $product->get_status();
                $new_visibility = $product->get_catalog_visibility();
                
                if ($new_status === 'publish' && ($schedule_type === 'status' || $new_visibility === 'visible')) {
                    $success_products[] = sprintf('%s (ID: %d)', $product->get_name(), $change->product_id);
                    
                    // Jelöljük a változtatást befejezettként
                    $wpdb->update(
                        $table_name,
                        array('completed' => 1),
                        array('id' => $change->id),
                        array('%d'),
                        array('%d')
                    );
                    
                    $this->log_message(sprintf(
                        'Sikeres módosítás - Termék ID: %d, Új státusz: %s, Új láthatóság: %s',
                        $change->product_id,
                        $new_status,
                        $new_visibility
                    ));
                } else {
                    throw new Exception(sprintf(
                        'A változtatás nem volt sikeres. Új státusz: %s, Új láthatóság: %s',
                        $new_status,
                        $new_visibility
                    ));
                }
    
            } catch (Exception $e) {
                $failed_products[] = sprintf('%s (ID: %d)', $product->get_name(), $change->product_id);
                $this->log_message(sprintf(
                    'Hiba történt a termék módosítása közben (%d): %s',
                    $change->product_id,
                    $e->getMessage()
                ));
            }
        }
    
        // Értesítések kezelése
        if (!empty($success_products) || !empty($failed_products)) {
            $notice = '';
    
            if (!empty($success_products)) {
                $notice .= 'Sikeresen módosított termékek: ' . implode(', ', $success_products) . '. ';
            }
    
            if (!empty($failed_products)) {
                $notice .= 'Nem sikerült módosítani: ' . implode(', ', $failed_products) . '.';
            }
    
            $this->add_admin_notice($notice);
        }
    
        $this->log_message('Feldolgozás befejezve');
    }


    public function add_admin_menu() {
        // Jogosultság ellenőrzés
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        add_menu_page(
            'Láthatóság Ütemező',
            'Láthatóság Ütemező',
            'manage_woocommerce',
            'visibility-scheduler',
            array($this, 'render_admin_page'),
            'dashicons-calendar-alt'
        );
    }

    public function add_scheduling_meta_box() {
        // Jogosultság ellenőrzés
        if (!current_user_can('edit_products')) {
            return;
        }
        add_meta_box(
            'visibility_scheduler_meta',
            'Láthatóság Ütemezés',
            array($this, 'render_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public function render_meta_box($post)
    {
        // Meta box nonce
        wp_nonce_field('visibility_scheduler_meta', 'visibility_scheduler_nonce');
        
        // AJAX műveletek nonce-ai
        $delete_nonce = wp_create_nonce('delete_schedule_nonce');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';
        
        // Lekérjük az aktuális ütemezést
        $scheduled = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE product_id = %d AND completed = 0",
            $post->ID
        ));
        
        $timezones = DateTimeZone::listIdentifiers();
        $current_timezone = get_post_meta($post->ID, '_visibility_scheduler_timezone', true);
        if (empty($current_timezone)) {
            $current_timezone = get_option('visibility_scheduler_timezone', '');
            if (empty($current_timezone)) {
                $current_timezone = wp_timezone_string();
            }
            if (empty($current_timezone)) {
                $current_timezone = 'UTC';
            }
        }
        
        $datetime = new DateTime('now', new DateTimeZone($current_timezone));
        $current_date = $datetime->format('Y-m-d');
        
        $schedule_type = $scheduled ? get_post_meta($post->ID, '_schedule_type', true) : 'visibility';

        $scheduled_display = '';
        $scheduled_date = '';
        $scheduled_clock = '';
        if ($scheduled && !empty($scheduled->scheduled_time)) {
            $scheduled_dt = new DateTime($scheduled->scheduled_time, new DateTimeZone('UTC'));
            $scheduled_dt->setTimezone(new DateTimeZone($current_timezone));
            $scheduled_display = $scheduled_dt->format('Y-m-d H:i');
            $scheduled_date = $scheduled_dt->format('Y-m-d');
            $scheduled_clock = $scheduled_dt->format('H:i');
        }
        ?>
        <div class="visibility-scheduler-settings">
            <?php 
            // Csak akkor mutatjuk a beállításokat, ha draft vagy private a státusz
            $current_status = get_post_status($post->ID);
            $parent_id = wp_get_post_parent_id($post->ID);
            
            if ($current_status === 'draft' || ($current_status === 'private' && !$parent_id)) :
            ?>
                <p>
                    <label for="schedule_type">Mit szeretne ütemezni?</label><br>
                    <select name="schedule_type" id="schedule_type">
                        <?php if ($current_status === 'private'): ?>
                            <option value="visibility" <?php selected($schedule_type, 'visibility'); ?>>
                                Private -> Public (láthatóság)
                            </option>
                        <?php endif; ?>
                        <?php if ($current_status === 'draft'): ?>
                            <option value="status" <?php selected($schedule_type, 'status'); ?>>
                                Draft -> Public (státusz)
                            </option>
                        <?php endif; ?>
                    </select>
                </p>

                <p>
                    <label for="scheduled_timezone">Időzóna:</label><br>
                    <select name="scheduled_timezone" id="scheduled_timezone">
                        <?php foreach ($timezones as $timezone): ?>
                            <option value="<?php echo esc_attr($timezone); ?>" 
                                    <?php selected($timezone, $current_timezone); ?>>
                                <?php echo esc_html($timezone); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p>
                    <label for="scheduled_visibility_date">Publikálás időpontja:</label><br>
                    <input type="date"
                           id="scheduled_visibility_date"
                           name="scheduled_visibility_date"
                           value="<?php echo esc_attr($scheduled_date); ?>"
                           min="<?php echo esc_attr($current_date); ?>"
                    />
                    <input type="time"
                           id="scheduled_visibility_clock"
                           name="scheduled_visibility_clock"
                           value="<?php echo esc_attr($scheduled_clock); ?>"
                           step="60"
                    />
                </p>

                <?php if ($scheduled) : ?>
                    <p class="description">
                        Jelenlegi ütemezés: <?php echo esc_html($scheduled_display); ?> 
                        (<?php echo $schedule_type === 'visibility' ? 'Láthatóság' : 'Státusz'; ?> változtatás)
                    </p>
                    <button type="button" class="button delete-schedule" 
                            data-nonce="<?php echo esc_attr($delete_nonce); ?>"
                            data-product-id="<?php echo esc_attr($post->ID); ?>">
                        Ütemezés törlése
                    </button>
                <?php endif; ?>

                
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Ütemezés törlése
                        $('.delete-schedule').on('click', function(e) {
                            e.preventDefault();
                            var button = $(this);
                            if(confirm('Biztosan törli az ütemezést?')) {
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'delete_product_schedule',
                                        nonce: button.data('nonce'),
                                        product_id: button.data('product-id')
                                    },
                                    beforeSend: function() {
                                        button.prop('disabled', true);
                                    },
                                    success: function(response) {
                                        if(response.success) {
                                            location.reload();
                                        } else {
                                            alert('Hiba történt az ütemezés törlésekor: ' + response.data);
                                            button.prop('disabled', false);
                                        }
                                    },
                                    error: function() {
                                        alert('Hiba történt a szerverrel való kommunikáció során.');
                                        button.prop('disabled', false);
                                    }
                                });
                            }
                        });
                    });
                </script>
            <?php else : ?>
                <p>Ütemezés csak draft vagy private státuszú termékekhez állítható be.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // Ajax handler metódus:
    public function handle_delete_schedule()
    {
        // Jogosultság és nonce ellenőrzés
        if (!current_user_can('edit_products') || 
            !check_ajax_referer('delete_schedule_nonce', 'nonce', false)) {
            wp_send_json_error('Nem megfelelő jogosultság');
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';
        
        // Ütemezés törlése
        $result = $wpdb->delete(
            $table_name,
            array(
                'product_id' => intval($_POST['product_id']),
                'completed' => 0
            ),
            array('%d', '%d')
        );

        if ($result !== false) {
            // Post meta törlése
            delete_post_meta(intval($_POST['product_id']), '_schedule_type');
            wp_send_json_success();
        } else {
            wp_send_json_error('Database error');
        }
    }

    public function save_scheduling_meta($post_id) {
        // Autosave ellenőrzés
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Jogosultság ellenőrzés
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Nonce ellenőrzés
        if (!isset($_POST['visibility_scheduler_nonce']) || 
            !wp_verify_nonce($_POST['visibility_scheduler_nonce'], 'visibility_scheduler_meta')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';

        // Időzóna mentése
        if (isset($_POST['scheduled_timezone'])) {
            $timezone = sanitize_text_field($_POST['scheduled_timezone']);
            // Validate timezone
            if (in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                update_post_meta($post_id, '_visibility_scheduler_timezone', $timezone);
            } else {
                $this->log_message('Invalid timezone provided: ' . $timezone);
            }
        }

        $scheduled_time = '';
        if (!empty($_POST['scheduled_visibility_date']) && !empty($_POST['scheduled_visibility_clock'])) {
            $scheduled_time = sanitize_text_field($_POST['scheduled_visibility_date']) . 'T' . sanitize_text_field($_POST['scheduled_visibility_clock']);
        } elseif (!empty($_POST['scheduled_visibility_time'])) {
            $scheduled_time = sanitize_text_field($_POST['scheduled_visibility_time']);
        }

        if (!empty($scheduled_time)) {
            $schedule_type = sanitize_text_field($_POST['schedule_type']);

            // Időzóna konvertálás
            $timezone = get_post_meta($post_id, '_visibility_scheduler_timezone', true);
            if (empty($timezone)) {
                $timezone = get_option('visibility_scheduler_timezone', '');
                if (empty($timezone)) {
                    $timezone = wp_timezone_string();
                }
                if (empty($timezone)) {
                    $timezone = 'UTC';
                }
            }
            $datetime = DateTime::createFromFormat('Y-m-d\\TH:i', $scheduled_time, new DateTimeZone($timezone));
            if (!$datetime) {
                return;
            }
            $datetime->setTimezone(new DateTimeZone('UTC'));
            $utc_time = $datetime->format('Y-m-d H:i:s');

            // Korábbi ütemezések törlése
            $wpdb->delete(
                $table_name,
                array('product_id' => $post_id, 'completed' => 0),
                array('%d', '%d')
            );

            // Új ütemezés mentése
            $wpdb->insert(
                $table_name,
                array(
                    'product_id' => $post_id,
                    'scheduled_time' => $utc_time,
                    'completed' => 0
                ),
                array('%d', '%s', '%d')
            );

            // Ütemezés típusának mentése
            update_post_meta($post_id, '_schedule_type', $schedule_type);

            $this->log_message("Új ütemezés hozzáadva: Termék ID: $post_id, Időpont: $utc_time, Típus: $schedule_type");
        }
    }

    public function schedule_visibility_change($product_id, $scheduled_time)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';

        // Töröljük a korábbi ütemezéseket ehhez a termékhez
        $wpdb->delete(
            $table_name,
            array('product_id' => $product_id, 'completed' => 0),
            array('%d', '%d')
        );

        // Új ütemezés beszúrása
        $wpdb->insert(
            $table_name,
            array(
                'product_id' => $product_id,
                'scheduled_time' => $scheduled_time,
                'completed' => 0
            ),
            array('%d', '%s', '%d')
        );
    }

    public function render_admin_page() {

       

        global $wpdb;
        $table_name = $wpdb->prefix . 'scheduled_visibility_changes';

        // Törlés feldolgozása
        if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['schedule_id'])) {
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Nem megfelelő jogosultság');
            }

            $schedule_id = absint($_POST['schedule_id']);
            check_admin_referer('delete_schedule_' . $schedule_id);

            $product_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT product_id FROM $table_name WHERE id = %d",
                    $schedule_id
                )
            );

            $deleted = $wpdb->delete(
                $table_name,
                array('id' => $schedule_id),
                array('%d')
            );

            if ($deleted !== false) {
                if ($product_id > 0) {
                    delete_post_meta($product_id, '_schedule_type');
                }
                echo '<div class="notice notice-success is-dismissible"><p>Az ütemezés sikeresen törölve!</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Hiba történt az ütemezés törlésekor.</p></div>';
            }
        }
    
        // Kézi futtatás feldolgozása
        if (isset($_POST['run_scheduler_now']) && check_admin_referer('run_scheduler_now')) {
            $utc_timezone = new DateTimeZone('UTC');
            $now_utc = new DateTime('now', $utc_timezone);
            $due_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE completed = 0 AND scheduled_time <= %s",
                    $now_utc->format('Y-m-d H:i:s')
                )
            );

            if ($due_count === 0) {
                $next_scheduled_time = $wpdb->get_var(
                    "SELECT MIN(scheduled_time) FROM $table_name WHERE completed = 0"
                );

                $next_display = '';
                if (!empty($next_scheduled_time)) {
                    $wp_timezone = new DateTimeZone(wp_timezone_string());
                    $next_dt = new DateTime($next_scheduled_time, $utc_timezone);
                    $next_dt->setTimezone($wp_timezone);
                    $next_display = $next_dt->format('Y-m-d H:i:s') . ' (' . $wp_timezone->getName() . ')';
                }

                echo '<div class="notice notice-info is-dismissible"><p>Nincs esedékes ütemezés (0 db). ';
                if (!empty($next_display)) {
                    echo 'Következő esedékes: ' . esc_html($next_display) . '.';
                }
                echo '</p></div>';
            } else {
                $this->process_scheduled_changes();
                echo '<div class="notice notice-success is-dismissible"><p>Az ütemezett feladatok feldolgozása megtörtént! (' . esc_html((string) $due_count) . ' db)</p></div>';
            }
        }
    
        // Lekérjük az összes aktív ütemezést
        $scheduled_changes = $wpdb->get_results(
            "SELECT sc.*, p.post_title, p.post_status 
            FROM $table_name sc 
            JOIN {$wpdb->posts} p ON sc.product_id = p.ID 
            WHERE sc.completed = 0 
            ORDER BY sc.scheduled_time ASC"
        );
        ?>
        <div class="wrap tw-container">
            <div class="tw-card">
                <div class="tw-card-header">
                    <div class="tw-flex tw-justify-between tw-items-center">
                        <h1 class="tw-text-xl tw-font-bold">Ütemezett Láthatóság Változtatások</h1>
                    </div>
                </div>
    
                <?php
                // Státusz információk megjelenítése
                $next_run = wp_next_scheduled('visibility_scheduler_cron');
                $this->log_message('Admin megjelenítés - next_run érték: ' . var_export($next_run, true));
                $wp_timezone = new DateTimeZone(wp_timezone_string());

                ?>
                <div class="tw-bg-blue-50 tw-border-l-4 tw-border-blue-500 tw-p-4 tw-rounded tw-mb-4">
                    <div class="tw-flex tw-justify-between tw-items-center">
                        <div>
                            <div class="tw-mb-2">
                                <span class="tw-font-bold">Következő automatikus futás:</span>
                                <?php 
                                if ($next_run) {
                                    $date = new DateTime();
                                    $date->setTimestamp($next_run);
                    $date->setTimezone($wp_timezone);
                    echo $date->format('Y-m-d H:i:s') . ' (' . $wp_timezone->getName() . ')';
                } else {
                    echo 'Nincs ütemezve';
                } 
                ?>
            </div>
            <div>
                <span class="tw-font-bold">Jelenlegi szerver idő:</span>
                <?php 
                $current_time = new DateTime('now', $wp_timezone);
                echo $current_time->format('Y-m-d H:i:s') . ' (' . $wp_timezone->getName() . ')';
                ?>
            </div>
               </div>
                  
                        <div>
                            <form method="post">
                               <?php wp_nonce_field('run_scheduler_now'); ?>
                               <button type="submit" name="run_scheduler_now" 
                                       class="tw-btn tw-btn-blue run_scheduler_now_btn">
                                Ütemezett feladatok azonnali futtatása
                               </button>
                            </form>
                        </div>
              
                </div>
                <table class="tw-table">
                    <thead>
                        <tr>
                            <th>Termék</th>
                            <th>Jelenlegi státusz</th>
                            <th>Ütemezett időpont</th>
                            <th>Időzóna</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($scheduled_changes)) : ?>
                            <tr>
                                <td colspan="5" class="tw-text-gray-600 tw-text-center">
                                    Nincs aktív ütemezés.
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($scheduled_changes as $change): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo get_edit_post_link($change->product_id); ?>" 
                                           class="tw-text-blue-600 hover:tw-text-blue-800">
                                            <?php echo esc_html($change->post_title); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($change->post_status); ?>">
                                            <?php echo esc_html($change->post_status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $product_timezone = get_post_meta($change->product_id, '_visibility_scheduler_timezone', true);
                                        if (empty($product_timezone)) {
                                            $product_timezone = get_option('visibility_scheduler_timezone', '');
                                            if (empty($product_timezone)) {
                                                $product_timezone = wp_timezone_string();
                                            }
                                            if (empty($product_timezone)) {
                                                $product_timezone = 'UTC';
                                            }
                                        }

                                        $date = new DateTime($change->scheduled_time, new DateTimeZone('UTC'));
                                        $date->setTimezone(new DateTimeZone($product_timezone));
                                        echo esc_html($date->format('Y-m-d H:i:s'));
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($product_timezone); ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <?php wp_nonce_field('delete_schedule_' . $change->id); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="schedule_id" value="<?php echo esc_attr($change->id); ?>">
                                            <button type="submit" 
                                                    class="tw-btn tw-btn-red"
                                                    onclick="return confirm('Biztosan törli ezt az ütemezést?');">
                                                Törlés
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Naplózás
     */
    private function log_message($message) {
        if (!$this->log_enabled) {
            return;
        }

        $timestamp = current_time('mysql');
        $log_message = sprintf("[%s] %s\n", $timestamp, $message);

        if (!file_exists($this->log_file)) {
            // Létrehozzuk a log fájlt ha nem létezik
            $header = "=== WooCommerce Product Visibility Scheduler Log ===\n\n";
            file_put_contents($this->log_file, $header);
        }

        // Hozzáfűzzük az új log bejegyzést
        file_put_contents($this->log_file, $log_message, FILE_APPEND);
    }

    /**
     * Log fájl törlése
     */
    public function clear_log() {
        if (file_exists($this->log_file)) {
            unlink($this->log_file);
            $this->log_message('Log fájl törölve');
        }
    }

    /**
     * Log fájl tartalmának lekérése
     */
    public function get_log_contents() {
        if (file_exists($this->log_file)) {
            return file_get_contents($this->log_file);
        }
        return 'Nincs elérhető log.';
    }

    /**
     * Naplózás be/kikapcsolása
     */
    public function set_logging($enabled) {
        $this->log_enabled = (bool) $enabled;
        if ($enabled) {
            $this->log_message('Naplózás engedélyezve');
        }
    }
}

// Plugin inicializálása
function wc_visibility_scheduler() {
    return WC_Product_Visibility_Scheduler::instance();
}

// Aktiválási hook
register_activation_hook(__FILE__, array('WC_Product_Visibility_Scheduler', 'activate_plugin'));

// Deaktiválási hook
register_deactivation_hook(__FILE__, array('WC_Product_Visibility_Scheduler', 'deactivate_plugin'));

// Plugin eltávolítási hook
register_uninstall_hook(__FILE__, array('WC_Product_Visibility_Scheduler', 'uninstall_plugin'));

// Csak akkor indítjuk el a plugint, ha a WooCommerce is aktív
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('plugins_loaded', function() {
        $GLOBALS['visibility_scheduler'] = wc_visibility_scheduler();
    });
}