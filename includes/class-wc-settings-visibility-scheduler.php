<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Settings_Visibility_Scheduler.
 */
class WC_Settings_Visibility_Scheduler extends WC_Settings_Page {
    
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id    = 'visibility_scheduler';
        $this->label = __('Láthatóság Ütemező', 'woocommerce');
        
        parent::__construct();
    }
    
    /**
     * Get settings array
     */
    public function get_settings() {
        $settings = array(
            array(
                'title' => __('Láthatóság Ütemező Beállítások', 'woocommerce'),
                'type'  => 'title',
                'desc'  => '',
                'id'    => 'visibility_scheduler_options',
            ),
            
            array(
                'title'   => __('Adatok törlése', 'woocommerce'),
                'desc'    => __('Adatok törlése a plugin eltávolításakor', 'woocommerce'),
                'desc_tip' => __('Ha be van jelölve, a plugin eltávolításakor minden kapcsolódó adat (adatbázis táblák, beállítások) törlésre kerül.', 'woocommerce'),
                'id'      => 'visibility_scheduler_delete_data',
                'default' => 'no',
                'type'    => 'checkbox',
            ),
            
            array(
                'title'   => __('Alapértelmezett időzóna', 'woocommerce'),
                'desc'    => __('Válassza ki az alapértelmezett időzónát az ütemezésekhez', 'woocommerce'),
                'id'      => 'visibility_scheduler_timezone',
                'default' => 'UTC',
                'type'    => 'select',
                'options' => $this->get_timezone_options(),
            ),
            
            array(
                'type' => 'sectionend',
                'id'   => 'visibility_scheduler_options',
            ),
        );
        
        return apply_filters('woocommerce_get_settings_' . $this->id, $settings);
    }
    
    /**
     * Időzóna opciók lekérése
     */
    private function get_timezone_options() {
        $timezones = array();
        $timestamp = time();
        
        foreach (DateTimeZone::listIdentifiers() as $timezone) {
            $zone = new DateTimeZone($timezone);
            $time = new DateTime('now', $zone);
            
            $offset = $zone->getOffset($time);
            $offset_prefix = $offset < 0 ? '-' : '+';
            $offset_formatted = gmdate('H:i', abs($offset));
            
            $pretty_offset = "UTC{$offset_prefix}{$offset_formatted}";
            
            $timezones[$timezone] = "({$pretty_offset}) $timezone";
        }
        
        return $timezones;
    }
    
    /**
     * Output the settings
     */
    public function output() {
        $settings = $this->get_settings();
        
        // Figyelmeztető üzenet az adattörlési opcióhoz
        if (isset($_GET['section']) && $_GET['section'] === $this->id) {
            echo '<div class="notice notice-warning inline"><p>';
            echo '<strong>' . __('Figyelmeztetés:', 'woocommerce') . '</strong> ';
            echo __('Az adatok törlése végleges és nem visszafordítható művelet!', 'woocommerce');
            echo '</p></div>';
        }
        
        WC_Admin_Settings::output_fields($settings);
    }
    
    /**
     * Save settings
     */
    public function save() {
        $settings = $this->get_settings();
        WC_Admin_Settings::save_fields($settings);
    }
}

// Visszatérés az osztály példányával
return new WC_Settings_Visibility_Scheduler();