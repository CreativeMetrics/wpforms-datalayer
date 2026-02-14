<?php
/**
 * Plugin Name: WPForms DataLayer Integration
 * Plugin URI: https://www.tuosito.com/wpforms-datalayer-integration
 * Description: Integra WPForms con il dataLayer di Google Tag Manager, consentendo di pushare gli eventi di invio del modulo con tutti i valori dei campi.
 * Version: 2.1.0
 * Author: CreativeMetrics
 * Author URI: https://www.creativemetrics.it
 * License: GPL-2.0+
 * Text Domain: wpforms-datalayer
 */

// Prevenire l'accesso diretto al file
if (!defined('ABSPATH')) {
    exit;
}

class WPForms_DataLayer_Integration {
    
    public function __construct() {
        add_action('admin_init', array($this, 'check_wpforms_active'));
        add_filter('wpforms_builder_settings_sections', array($this, 'add_settings_section'), 20);
        add_action('wpforms_form_settings_panel_content', array($this, 'add_settings_content'), 20);
        add_filter('wpforms_save_form_args', array($this, 'save_datalayer_settings'), 10, 3);
        add_action('wpforms_process_complete', array($this, 'handle_form_submit'), 10, 4);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wpforms_frontend_output', array($this, 'register_form_for_js'), 10, 5);
        add_filter('wpforms_process_ajax_submit_success_response', array($this, 'add_datalayer_to_ajax_response'), 20, 3);
        add_filter('wpforms_frontend_confirmation_message', array($this, 'maybe_add_datalayer_script'), 10, 4);
    }
    
    public function check_wpforms_active() {
        if (!class_exists('WPForms')) {
            add_action('admin_notices', array($this, 'wpforms_inactive_notice'));
        }
    }
    
    public function wpforms_inactive_notice() {
        echo '<div class="notice notice-error"><p>' . __('Il plugin WPForms DataLayer Integration richiede che WPForms sia installato e attivato.', 'wpforms-datalayer') . '</p></div>';
    }
    
    public function add_settings_section($sections) {
        $sections['datalayer'] = __('DataLayer', 'wpforms-datalayer');
        return $sections;
    }
    
    public function add_settings_content($instance) {
        echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-datalayer">';
        echo '<div class="wpforms-panel-content-section-title">' . __('Impostazioni DataLayer', 'wpforms-datalayer') . '</div>';

        $form_data_safe = (isset($instance->form_data) && is_array($instance->form_data)) ? $instance->form_data : array();

        wpforms_panel_field('text', 'settings', 'datalayer_event', $form_data_safe, __('Nome Evento DataLayer', 'wpforms-datalayer'), [
            'default' => 'wpforms_submission',
            'tooltip' => __('Specifica il nome dell\'evento che verrà pushato nel dataLayer.', 'wpforms-datalayer')
        ]);

        // NUOVA FUNZIONE: Esclusione Campi (GDPR)
        wpforms_panel_field('text', 'settings', 'datalayer_exclude_ids', $form_data_safe, __('ID Campi da Escludere', 'wpforms-datalayer'), [
            'tooltip' => __('Inserisci gli ID dei campi sensibili da NON inviare al DataLayer, separati da virgola (es. 2, 5, 8).', 'wpforms-datalayer')
        ]);

        // NUOVA FUNZIONE: Modalità Debug
        wpforms_panel_field('checkbox', 'settings', 'datalayer_debug', $form_data_safe, __('Abilita Modalità Debug', 'wpforms-datalayer'), [
            'tooltip' => __('Se attivo, mostrerà il payload del DataLayer nella console del browser. Da disattivare in produzione.', 'wpforms-datalayer')
        ]);
        
        echo '</div>';
    }
    
    public function save_datalayer_settings($form_data, $form, $data) {
        if (isset($data['settings']['datalayer_event'])) {
            $form_data['settings']['datalayer_event'] = sanitize_text_field($data['settings']['datalayer_event']);
        }
        if (isset($data['settings']['datalayer_exclude_ids'])) {
            $form_data['settings']['datalayer_exclude_ids'] = sanitize_text_field($data['settings']['datalayer_exclude_ids']);
        }
        $form_data['settings']['datalayer_debug'] = isset($data['settings']['datalayer_debug']) ? '1' : '0';
        
        return $form_data;
    }
    
    private function generate_submission_id($form_id) {
        return 'wpforms_' . $form_id . '_' . time() . '_' . mt_rand(1000, 9999);
    }
    
    public function maybe_add_datalayer_script($confirmation_message, $form_data, $fields, $entry_id) {
        $submission_id = get_option('wpforms_datalayer_last_submission_id_' . $form_data['id']);
        if ($submission_id) {
            $datalayer_data = get_option('wpforms_datalayer_ajax_' . $submission_id);
            if ($datalayer_data) {
                $debug_log = !empty($datalayer_data['_debug']) ? 'console.log("DataLayer push eseguito da confirmation:", data);' : '';
                $script = '<script>
                    (function(){
                        window.dataLayer = window.dataLayer || [];
                        var data = ' . json_encode($datalayer_data) . ';
                        window.dataLayer.push(data);
                        ' . $debug_log . '
                    })();
                </script>';
                $confirmation_message .= $script;
            }
        }
        return $confirmation_message;
    }

    public function handle_form_submit($fields, $entry, $form_data, $entry_id) {
        $event_name = !empty($form_data['settings']['datalayer_event']) ? $form_data['settings']['datalayer_event'] : 'wpforms_submission';
        $submission_id = $this->generate_submission_id($form_data['id']);
        
        // Recupero impostazioni Debug ed Esclusioni
        $is_debug = !empty($form_data['settings']['datalayer_debug']) && $form_data['settings']['datalayer_debug'] === '1';
        $exclude_ids = array();
        if (!empty($form_data['settings']['datalayer_exclude_ids'])) {
            $exclude_ids = array_map('trim', explode(',', $form_data['settings']['datalayer_exclude_ids']));
        }

        $datalayer_data = array(
            'event' => $event_name,
            'formId' => $form_data['id'],
            'formTitle' => $form_data['settings']['form_title'],
            'submissionId' => $submission_id,
            'timestamp' => time(),
            '_debug' => $is_debug, // Passato al JS
            'formFields' => array()
        );
        
        foreach ($fields as $field) {
            $field_id = $field['id'];
            
            // CONTROLLO ESCLUSIONI PII
            if (in_array((string)$field_id, $exclude_ids)) {
                continue; // Salta questo campo, non va nel DataLayer
            }

            $field_name = isset($form_data['fields'][$field_id]['label']) ? $form_data['fields'][$field_id]['label'] : 'Field ' . $field_id;
            $field_value = $field['value'];
            $field_type = isset($form_data['fields'][$field_id]['type']) ? $form_data['fields'][$field_id]['type'] : '';
            
            // Controllo trasversale: Formattazione Facebook per "Città" o "Data di Nascita" generici
            $this->apply_facebook_formatting_if_needed($field_name, $field_value, $datalayer_data);

            if (stripos($field_name, 'nome e cognome') !== false || stripos($field_name, 'nome completo') !== false || stripos($field_name, 'full name') !== false) {
                $this->handle_name_field($field, $field_name, $datalayer_data);
                continue;
            }
            
            switch ($field_type) {
                case 'email':
                    $clean_email = sanitize_email($field_value);
                    $datalayer_data['formFields'][$field_name] = $clean_email;
                    // Hash SHA-256 Email
                    $datalayer_data['formFields'][$field_name . '_hash'] = hash('sha256', strtolower(trim($clean_email)));
                    if (strpos($field_value, '@') !== false) {
                        $parts = explode('@', $field_value);
                        if (isset($parts[1])) {
                            $datalayer_data['formFields'][$field_name . '_domain'] = sanitize_text_field($parts[1]);
                        }
                    }
                    break;
                    
                case 'phone':
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    $numeric_phone = preg_replace('/[^0-9+]/', '', $field_value);
                    if ($numeric_phone !== $field_value) {
                        $datalayer_data['formFields'][$field_name . '_numeric'] = $numeric_phone;
                    }
                    // Hash SHA-256 Telefono (solo numeri)
                    $pure_numbers = ltrim(preg_replace('/[^0-9]/', '', $field_value), '0');
                    if (!empty($pure_numbers)) {
                        $datalayer_data['formFields'][$field_name . '_hash'] = hash('sha256', $pure_numbers);
                    }
                    break;

                case 'address':
                    if (is_array($field_value)) {
                        foreach ($field_value as $address_key => $address_part) {
                            $datalayer_data['formFields'][$field_name . '_' . $address_key] = sanitize_text_field($address_part);
                        }
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field(implode(', ', $field_value));
                        
                        // CAPI Facebook Città
                        if (isset($field_value['city']) && !empty($field_value['city'])) {
                            $fb_city = str_replace(' ', '', strtolower(remove_accents(trim($field_value['city']))));
                            $datalayer_data['formFields'][$field_name . '_city_fb'] = $fb_city;
                            $datalayer_data['formFields'][$field_name . '_city_hash'] = hash('sha256', $fb_city);
                        }
                    } else {
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    }
                    break;
                
                // ... [Mantenuta identica la logica per gli altri campi: checkbox, file, date-time, payment, ecc.] ...
                case 'checkbox':
                case 'select':
                case 'radio':
                case 'payment-checkbox':
                case 'payment-multiple':
                    if (is_array($field_value)) {
                        $field_value = array_map('sanitize_text_field', $field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                        $datalayer_data['formFields'][$field_name . '_text'] = implode(', ', $field_value);
                    } else {
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    }
                    break;
                    
                case 'file-upload':
                    if (is_array($field_value)) {
                        $field_value = array_map('esc_url_raw', $field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                        $datalayer_data['formFields'][$field_name . '_count'] = count($field_value);
                    } else {
                        $field_value = esc_url_raw($field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                    }
                    break;
                    
                case 'date-time':
                case 'date':
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    $timestamp = strtotime(str_replace('/', '-', $field_value)); // Fix per date IT
                    if ($timestamp) {
                        $datalayer_data['formFields'][$field_name . '_timestamp'] = $timestamp;
                        $datalayer_data['formFields'][$field_name . '_iso'] = date('Y-m-d', $timestamp);
                    }
                    break;
                    
                case 'time':
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    break;
                    
                case 'payment-single':
                case 'payment-total':
                case 'credit-card':
                case 'payment-quantity':
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    $numeric_value = preg_replace('/[^0-9.,]/', '', $field_value);
                    $numeric_value = str_replace(',', '.', $numeric_value);
                    $datalayer_data['formFields'][$field_name . '_numeric'] = (float) $numeric_value;
                    break;
                    
                case 'name':
                    $this->handle_name_field($field, $field_name, $datalayer_data);
                    break;
                    
                default:
                    if (is_array($field_value)) {
                        $field_value = array_map('sanitize_text_field', $field_value);
                    } else {
                        $field_value = sanitize_text_field($field_value);
                    }
                    $datalayer_data['formFields'][$field_name] = $field_value;
                    break;
            }
        }
        
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        
        if (!$is_ajax) {
            if (!session_id()) session_start();
            $_SESSION['wpforms_datalayer_submission'] = $datalayer_data;
        }
        
        if ($is_ajax) {
            update_option('wpforms_datalayer_ajax_' . $submission_id, $datalayer_data, false);
            set_transient('wpforms_datalayer_cleanup_' . $submission_id, true, 5 * MINUTE_IN_SECONDS);
            
            $debug_log = $is_debug ? 'console.log("DataLayer push fallback eseguito:", ' . json_encode($datalayer_data) . ');' : '';

            add_action('wp_footer', function() use ($datalayer_data, $debug_log) {
                echo '<script>
                    (function(){
                        window.dataLayer = window.dataLayer || [];
                        window.addEventListener("beforeunload", function() {
                            window.dataLayer.push(' . json_encode($datalayer_data) . ');
                            ' . $debug_log . '
                        });
                        setTimeout(function() {
                            var pushDone = false;
                            if (window.wpformsDataLayerPushed) { pushDone = window.wpformsDataLayerPushed["' . $datalayer_data['submissionId'] . '"]; }
                            if (!pushDone) {
                                window.dataLayer.push(' . json_encode($datalayer_data) . ');
                                ' . $debug_log . '
                                window.wpformsDataLayerPushed = window.wpformsDataLayerPushed || {};
                                window.wpformsDataLayerPushed["' . $datalayer_data['submissionId'] . '"] = true;
                            }
                        }, 2000);
                    })();
                </script>';
            });
        }
        
        update_option('wpforms_datalayer_last_submission_id_' . $form_data['id'], $submission_id, false);
    }

    /**
     * NUOVA FUNZIONE: Controlla se un campo è "Città" o "Data di nascita" e crea il formato Facebook CAPI
     */
    private function apply_facebook_formatting_if_needed($field_name, $field_value, &$datalayer_data) {
        if (is_array($field_value)) return; // Evita errori se è un array complesso
        
        // CAPI: Data di nascita (Formato YYYYMMDD + SHA-256)
        if (stripos($field_name, 'nascita') !== false || stripos($field_name, 'birth') !== false || stripos($field_name, 'dob') !== false) {
            $timestamp = strtotime(str_replace('/', '-', $field_value));
            if ($timestamp) {
                $dob_fb = date('Ymd', $timestamp);
                $datalayer_data['formFields'][$field_name . '_fb'] = $dob_fb;
                $datalayer_data['formFields'][$field_name . '_hash'] = hash('sha256', $dob_fb);
            }
        }
        
        // CAPI: Città da campo di testo singolo (tutto attaccato, senza accenti, minuscolo + SHA-256)
        if (stripos($field_name, 'città') !== false || stripos($field_name, 'citta') !== false || stripos($field_name, 'city') !== false) {
            $fb_city = str_replace(' ', '', strtolower(remove_accents(trim($field_value))));
            $datalayer_data['formFields'][$field_name . '_fb'] = $fb_city;
            $datalayer_data['formFields'][$field_name . '_hash'] = hash('sha256', $fb_city);
        }
    }

    private function handle_name_field($field, $field_name, &$datalayer_data) {
        $field_value = $field['value'];
        if (is_array($field_value)) {
            $name_parts = array();
            if (!empty($field_value['first'])) {
                $name_parts[] = $field_value['first'];
                $datalayer_data['formFields']['nome'] = sanitize_text_field($field_value['first']);
                $datalayer_data['formFields']['nome_hash'] = hash('sha256', strtolower(trim($field_value['first']))); // Hash
            }
            if (!empty($field_value['middle'])) {
                $name_parts[] = $field_value['middle'];
                if (isset($datalayer_data['formFields']['nome'])) {
                    $datalayer_data['formFields']['nome'] .= ' ' . sanitize_text_field($field_value['middle']);
                    $datalayer_data['formFields']['nome_hash'] = hash('sha256', strtolower(trim($datalayer_data['formFields']['nome']))); // Ricalcola Hash
                }
            }
            if (!empty($field_value['last'])) {
                $name_parts[] = $field_value['last'];
                $datalayer_data['formFields']['cognome'] = sanitize_text_field($field_value['last']);
                $datalayer_data['formFields']['cognome_hash'] = hash('sha256', strtolower(trim($field_value['last']))); // Hash
            }
            $datalayer_data['formFields'][$field_name] = sanitize_text_field(implode(' ', $name_parts));
        } else {
            $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
            $name_parts = explode(' ', trim($field_value));
            if (count($name_parts) >= 2) {
                $datalayer_data['formFields']['nome'] = sanitize_text_field($name_parts[0]);
                $datalayer_data['formFields']['nome_hash'] = hash('sha256', strtolower(trim($name_parts[0])));
                $last_name = implode(' ', array_slice($name_parts, 1));
                $datalayer_data['formFields']['cognome'] = sanitize_text_field($last_name);
                $datalayer_data['formFields']['cognome_hash'] = hash('sha256', strtolower(trim($last_name)));
            }
        }
    }
        
    public function add_datalayer_to_ajax_response($response, $form_data, $entry_id) {
        $form_id = $form_data['id'];
        $submission_id = get_option('wpforms_datalayer_last_submission_id_' . $form_id);
        if ($submission_id) {
            $datalayer_data = get_option('wpforms_datalayer_ajax_' . $submission_id);
            if ($datalayer_data) {
                if (!isset($response['data'])) {
                    $response['data'] = array();
                }
                $response['data']['datalayer'] = $datalayer_data;
            }
        }
        return $response;
    }

    public function push_to_datalayer($data, $echo = false) {
        $json_data = wp_json_encode($data);
        $script = "<script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({$json_data});
        </script>";
        if ($echo) echo $script;
        return $script;
    }
        
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'wpforms-datalayer-integration',
            plugin_dir_url(__FILE__) . 'js/wpforms-datalayer.js',
            array('jquery'),
            '2.1.0', 
            true
        );
        wp_localize_script('wpforms-datalayer-integration', 'wpformsDataLayer', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'forms' => array()
        ));
        
        if (!session_id()) session_start();
        if (isset($_SESSION['wpforms_datalayer_submission'])) {
            $data = $_SESSION['wpforms_datalayer_submission'];
            add_action('wp_footer', function() use ($data) {
                echo $this->push_to_datalayer($data);
                if (!empty($data['_debug'])) {
                    echo "<script>console.log('DataLayer PHP Fallback:', " . wp_json_encode($data) . ");</script>";
                }
            });
            unset($_SESSION['wpforms_datalayer_submission']);
        }
    }

    public function register_form_for_js($form_data, $form, $title, $description, $errors) {
        $event_name = !empty($form_data['settings']['datalayer_event']) ? $form_data['settings']['datalayer_event'] : 'wpforms_submission';
        ?>
        <script type="text/javascript">
            (function($){
                if (typeof wpformsDataLayer !== 'undefined') {
                    wpformsDataLayer.forms[<?php echo $form_data['id']; ?>] = '<?php echo esc_js($event_name); ?>';
                }
            })(jQuery);
        </script>
        <?php
    }
}

$wpforms_datalayer_integration = new WPForms_DataLayer_Integration();

// --- INIZIO INTEGRAZIONE PLUGIN UPDATE CHECKER ---
$puc_path = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

if (file_exists($puc_path)) {
    require_once $puc_path;
    $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/tuo-username/nome-repo/', // <-- Ricordati di ripristinare la tua repository qui!
        __FILE__,
        'wpforms-datalayer-integration'
    );
    $myUpdateChecker->setBranch('main');

    $myUpdateChecker->addResultFilter(function ($info) {
        $plugin_url = plugin_dir_url(__FILE__);
        $info->icons = array(
            'default' => $plugin_url . 'assets/icon-128x128.png',
            '1x'      => $plugin_url . 'assets/icon-128x128.png',
            '2x'      => $plugin_url . 'assets/icon-256x256.png'
        );
        $info->banners = array(
            'low'  => $plugin_url . 'assets/banner-772x250.jpg',
            'high' => $plugin_url . 'assets/banner-1544x500.jpg'
        );
        return $info;
    });
}
// --- FINE INTEGRAZIONE PLUGIN UPDATE CHECKER ---

add_action('init', function() {
    global $wpdb;
    $options = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wpforms_datalayer_ajax_%'");
    foreach ($options as $option) {
        $submission_id = str_replace('wpforms_datalayer_ajax_', '', $option->option_name);
        if (!get_transient('wpforms_datalayer_cleanup_' . $submission_id)) {
            delete_option($option->option_name);
        }
    }
});