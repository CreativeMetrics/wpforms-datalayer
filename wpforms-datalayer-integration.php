<?php
/**
 * Plugin Name: WPForms DataLayer Integration
 * Plugin URI: https://www.tuosito.com/wpforms-datalayer-integration
 * Description: Integra WPForms con il dataLayer di Google Tag Manager, consentendo di pushare gli eventi di invio del modulo con tutti i valori dei campi.
 * Version: 2.0.0
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
    
    /**
     * Inizializza il plugin
     */
    public function __construct() {
        // Verificare se WPForms è attivo
        add_action('admin_init', array($this, 'check_wpforms_active'));
        
        // Aggiunge una nuova sezione "DataLayer" alle impostazioni del modulo
        add_filter('wpforms_builder_settings_sections', array($this, 'add_settings_section'), 20);
        
        // Aggiunge i campi nella sezione "DataLayer"
        add_action('wpforms_form_settings_panel_content', array($this, 'add_settings_content'), 20);
        
        // Salva le impostazioni personalizzate del modulo
        add_filter('wpforms_save_form_args', array($this, 'save_datalayer_settings'), 10, 3);
        
        // Gestisci l'invio del modulo
        add_action('wpforms_process_complete', array($this, 'handle_form_submit'), 10, 4);
        
        // Aggiungi script per gestire invii
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Memorizza tutti gli ID dei form nella pagina corrente
        add_action('wpforms_frontend_output', array($this, 'register_form_for_js'), 10, 5);
        
        // Aggiungi informazioni del form all'output JSON per invii AJAX
        add_filter('wpforms_process_ajax_submit_success_response', array($this, 'add_datalayer_to_ajax_response'), 20, 3);
        
        // Aggiungi questo nel costruttore
        add_filter('wpforms_frontend_confirmation_message', array($this, 'maybe_add_datalayer_script'), 10, 4);
    }
    
    /**
     * Verifica che WPForms sia attivo
     */
    public function check_wpforms_active() {
        if (!class_exists('WPForms')) {
            add_action('admin_notices', array($this, 'wpforms_inactive_notice'));
        }
    }
    
    /**
     * Mostra notifica se WPForms non è attivo
     */
    public function wpforms_inactive_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Il plugin WPForms DataLayer Integration richiede che WPForms sia installato e attivato.', 'wpforms-datalayer'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Aggiunge una sezione DataLayer nel pannello delle impostazioni
     */
    public function add_settings_section($sections) {
        $sections['datalayer'] = __('DataLayer', 'wpforms-datalayer');
        return $sections;
    }
    
    /**
     * Aggiunge i campi alla sezione DataLayer
     */
    public function add_settings_content($instance) {
        echo '<div class="wpforms-panel-content-section wpforms-panel-content-section-datalayer">';
        echo '<div class="wpforms-panel-content-section-title">';
        echo __('Impostazioni DataLayer', 'wpforms-datalayer');
        echo '</div>';

        // FIX CHIRURGICO: Previene il Fatal Error (Schermata Bianca) se form_data non è ancora un array valido
        $form_data_safe = (isset($instance->form_data) && is_array($instance->form_data)) ? $instance->form_data : array();

        wpforms_panel_field(
            'text',
            'settings',
            'datalayer_event',
            $form_data_safe,
            __('Nome Evento DataLayer', 'wpforms-datalayer'),
            [
                'default' => 'wpforms_submission',
                'tooltip' => __('Specifica il nome dell\'evento che verrà pushato nel dataLayer quando questo modulo viene inviato.', 'wpforms-datalayer')
            ]
        );
        
        echo '</div>';
    }
    
    /**
     * Salva le impostazioni del dataLayer
     */
    public function save_datalayer_settings($form_data, $form, $data) {
        if (isset($data['settings']['datalayer_event'])) {
            $form_data['settings']['datalayer_event'] = sanitize_text_field($data['settings']['datalayer_event']);
        }
        
        return $form_data;
    }
    
    /**
     * Genera un ID univoco per ogni invio di form
     */
    private function generate_submission_id($form_id) {
        return 'wpforms_' . $form_id . '_' . time() . '_' . mt_rand(1000, 9999);
    }
    
    public function maybe_add_datalayer_script($confirmation_message, $form_data, $fields, $entry_id) {
        $submission_id = get_option('wpforms_datalayer_last_submission_id_' . $form_data['id']);
        if ($submission_id) {
            $datalayer_data = get_option('wpforms_datalayer_ajax_' . $submission_id);
            if ($datalayer_data) {
                // Aggiungi uno script inline al messaggio di conferma
                $script = '<script>
                    (function(){
                        window.dataLayer = window.dataLayer || [];
                        var data = ' . json_encode($datalayer_data) . ';
                        window.dataLayer.push(data);
                        console.log("DataLayer push eseguito da confirmation:", data);
                    })();
                </script>';
                $confirmation_message .= $script;
            }
        }
        return $confirmation_message;
    }

    /**
     * Gestisce l'invio del modulo sia per AJAX che non-AJAX
     * Con gestione migliorata dei diversi tipi di campi
     */
    public function handle_form_submit($fields, $entry, $form_data, $entry_id) {
        // Debug - verifica che il metodo sia chiamato
        error_log('WPForms DataLayer: handle_form_submit chiamato per form ID ' . $form_data['id']);
        
        $event_name = !empty($form_data['settings']['datalayer_event']) ? $form_data['settings']['datalayer_event'] : 'wpforms_submission';
        
        // Genera un ID univoco per questo invio
        $submission_id = $this->generate_submission_id($form_data['id']);
        
        // Prepara l'array per il dataLayer
        $datalayer_data = array(
            'event' => $event_name,
            'formId' => $form_data['id'],
            'formTitle' => $form_data['settings']['form_title'],
            'submissionId' => $submission_id,
            'timestamp' => time(),
            'formFields' => array()
        );
        
        // Aggiungi tutti i campi del modulo e i loro valori
        foreach ($fields as $field) {
            $field_id = $field['id'];
            $field_name = isset($form_data['fields'][$field_id]['label']) ? $form_data['fields'][$field_id]['label'] : 'Field ' . $field_id;
            $field_value = $field['value'];
            $field_type = isset($form_data['fields'][$field_id]['type']) ? $form_data['fields'][$field_id]['type'] : '';
            
            // Gestione speciale per campo nome e cognome unito
            if (stripos($field_name, 'nome e cognome') !== false || 
                stripos($field_name, 'nome completo') !== false || 
                stripos($field_name, 'full name') !== false) {
                $this->handle_name_field($field, $field_name, $datalayer_data);
                continue; // Passa al campo successivo
            }
            
            // Gestione speciale per tipi di campo specifici
            switch ($field_type) {
                case 'checkbox':
                case 'select':
                case 'radio':
                case 'payment-checkbox':
                case 'payment-multiple':
                    // Gestisci array di valori
                    if (is_array($field_value)) {
                        $field_value = array_map('sanitize_text_field', $field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                        // Aggiungi anche come stringa separata da virgole per compatibilità
                        $datalayer_data['formFields'][$field_name . '_text'] = implode(', ', $field_value);
                    } else {
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    }
                    break;
                    
                case 'file-upload':
                    // Gestisci URL di file
                    if (is_array($field_value)) {
                        // Array di URL
                        $field_value = array_map('esc_url_raw', $field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                        $datalayer_data['formFields'][$field_name . '_count'] = count($field_value);
                    } else {
                        // URL singolo
                        $field_value = esc_url_raw($field_value);
                        $datalayer_data['formFields'][$field_name] = $field_value;
                    }
                    break;
                    
                case 'date-time':
                case 'date':
                    // Sanitizza normalmente e aggiungi anche una versione formattata
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    
                    // Prova a convertire in timestamp per analisi
                    $timestamp = strtotime($field_value);
                    if ($timestamp) {
                        $datalayer_data['formFields'][$field_name . '_timestamp'] = $timestamp;
                        // Formato ISO per interoperabilità
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
                    // Converti in formato numerico per analisi
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    
                    // Rimuovi simboli di valuta e separatori per avere un valore numerico
                    $numeric_value = preg_replace('/[^0-9.,]/', '', $field_value);
                    // Converti virgole in punti per essere sicuri
                    $numeric_value = str_replace(',', '.', $numeric_value);
                    $datalayer_data['formFields'][$field_name . '_numeric'] = (float) $numeric_value;
                    break;
                    
                case 'name':
                    // Gestione speciale per campo nome
                    $this->handle_name_field($field, $field_name, $datalayer_data);
                    break;
                    
                case 'address':
                    // Gestione indirizzo (normalmente è già un array con chiavi specifiche)
                    if (is_array($field_value)) {
                        foreach ($field_value as $address_key => $address_part) {
                            $datalayer_data['formFields'][$field_name . '_' . $address_key] = sanitize_text_field($address_part);
                        }
                        // Aggiungi anche l'indirizzo completo
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field(implode(', ', $field_value));
                    } else {
                        $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    }
                    break;
                    
                case 'email':
                    // Sanitizza email
                    $datalayer_data['formFields'][$field_name] = sanitize_email($field_value);
                    
                    // Aggiungi anche il dominio dell'email
                    if (strpos($field_value, '@') !== false) {
                        $parts = explode('@', $field_value);
                        if (isset($parts[1])) {
                            $datalayer_data['formFields'][$field_name . '_domain'] = sanitize_text_field($parts[1]);
                        }
                    }
                    break;
                    
                case 'phone':
                    // Sanitizza e aggiungi versione numerica
                    $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
                    $numeric_phone = preg_replace('/[^0-9+]/', '', $field_value);
                    if ($numeric_phone !== $field_value) {
                        $datalayer_data['formFields'][$field_name . '_numeric'] = $numeric_phone;
                    }
                    break;
                    
                default:
                    // Gestione predefinita
                    if (is_array($field_value)) {
                        $field_value = array_map('sanitize_text_field', $field_value);
                    } else {
                        $field_value = sanitize_text_field($field_value);
                    }
                    $datalayer_data['formFields'][$field_name] = $field_value;
                    break;
            }
        }
        
        // Determina se questa è una richiesta AJAX
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        error_log('WPForms DataLayer: Richiesta AJAX: ' . ($is_ajax ? 'Sì' : 'No'));
        
        // Solo per invii non-AJAX, memorizza nella sessione
        if (!$is_ajax) {
            // Usa una sessione invece di un'opzione per evitare problemi con il refresh della pagina
            if (!session_id()) {
                session_start();
            }
            $_SESSION['wpforms_datalayer_submission'] = $datalayer_data;
            error_log('WPForms DataLayer: Dati salvati nella sessione');
        }
        
        // Per invii AJAX, mantieni nell'opzione temporanea
        if ($is_ajax) {
            update_option('wpforms_datalayer_ajax_' . $submission_id, $datalayer_data, false);
            // Imposta un'opzione che scadrà dopo 5 minuti per pulizia
            set_transient('wpforms_datalayer_cleanup_' . $submission_id, true, 5 * MINUTE_IN_SECONDS);
            error_log('WPForms DataLayer: Dati salvati in opzione per AJAX: ' . json_encode($datalayer_data));
            
            // FALLBACK: Aggiungi script inline per eseguire il push direttamente
            add_action('wp_footer', function() use ($datalayer_data) {
                echo '<script>
                    (function(){
                        // Inizializza il dataLayer se non esiste
                        window.dataLayer = window.dataLayer || [];
                        
                        // Aggiungi un evento per salvare i dati prima della chiusura della pagina
                        window.addEventListener("beforeunload", function() {
                            // Push dei dati nel dataLayer (fallback)
                            window.dataLayer.push(' . json_encode($datalayer_data) . ');
                            console.log("DataLayer push fallback eseguito:", ' . json_encode($datalayer_data) . ');
                        });
                        
                        // Timeout per eseguire il push se la risposta AJAX non lo fa
                        setTimeout(function() {
                            // Controlla se è già stato fatto un push con questo ID
                            var pushDone = false;
                            if (window.wpformsDataLayerPushed) {
                                pushDone = window.wpformsDataLayerPushed["' . $datalayer_data['submissionId'] . '"];
                            }
                            
                            if (!pushDone) {
                                // Push dei dati nel dataLayer (fallback con timeout)
                                window.dataLayer.push(' . json_encode($datalayer_data) . ');
                                console.log("DataLayer push fallback (timeout) eseguito:", ' . json_encode($datalayer_data) . ');
                                
                                // Segna come fatto
                                window.wpformsDataLayerPushed = window.wpformsDataLayerPushed || {};
                                window.wpformsDataLayerPushed["' . $datalayer_data['submissionId'] . '"] = true;
                            }
                        }, 2000); // Attendi 2 secondi
                    })();
                </script>';
            });
        }
        
        // Memorizza l'ID di sottomissione per accedervi più tardi
        update_option('wpforms_datalayer_last_submission_id_' . $form_data['id'], $submission_id, false);
        error_log('WPForms DataLayer: ID sottomissione salvato: ' . $submission_id);
    }

    /**
     * Gestisce il campo nome e lo separa in nome e cognome quando possibile
     */
    private function handle_name_field($field, $field_name, &$datalayer_data) {
        $field_value = $field['value'];
        
        // Gestisci formato completo del campo nome (array con first, last, etc.)
        if (is_array($field_value)) {
            // WPForms già fornisce campi separati
            $name_parts = array();
            
            // Componi il nome completo dall'array
            if (!empty($field_value['first'])) {
                $name_parts[] = $field_value['first'];
                $datalayer_data['formFields']['nome'] = sanitize_text_field($field_value['first']);
            }
            
            if (!empty($field_value['middle'])) {
                $name_parts[] = $field_value['middle'];
                // Aggiungi secondo nome al nome
                if (isset($datalayer_data['formFields']['nome'])) {
                    $datalayer_data['formFields']['nome'] .= ' ' . sanitize_text_field($field_value['middle']);
                } else {
                    $datalayer_data['formFields']['nome'] = sanitize_text_field($field_value['middle']);
                }
            }
            
            if (!empty($field_value['last'])) {
                $name_parts[] = $field_value['last'];
                $datalayer_data['formFields']['cognome'] = sanitize_text_field($field_value['last']);
            }
            
            // Salva il valore completo
            $datalayer_data['formFields'][$field_name] = sanitize_text_field(implode(' ', $name_parts));
        } 
        // Gestisci formato stringa singola
        else {
            // Salva il valore originale
            $datalayer_data['formFields'][$field_name] = sanitize_text_field($field_value);
            
            // Prova a dividere in nome e cognome
            $name_parts = explode(' ', trim($field_value));
            
            if (count($name_parts) >= 2) {
                // Prendi il primo elemento come nome
                $datalayer_data['formFields']['nome'] = sanitize_text_field($name_parts[0]);
                
                // Prendi tutti gli altri elementi come cognome
                $last_name = implode(' ', array_slice($name_parts, 1));
                $datalayer_data['formFields']['cognome'] = sanitize_text_field($last_name);
            }
        }
    }
        
    // FIX CHIRURGICO: WPForms passa $entry_id come 3° parametro, non $form_id. Recuperiamo l'ID esatto.
    public function add_datalayer_to_ajax_response($response, $form_data, $entry_id) {
        $form_id = $form_data['id']; // Ricava l'ID del form dai dati array
        error_log('WPForms DataLayer: add_datalayer_to_ajax_response chiamato per form ID ' . $form_id);
        
        $submission_id = get_option('wpforms_datalayer_last_submission_id_' . $form_id);
        if ($submission_id) {
            $datalayer_data = get_option('wpforms_datalayer_ajax_' . $submission_id);
            if ($datalayer_data) {
                // Modifica qui: assicurati che i dati siano inseriti in data.datalayer
                if (!isset($response['data'])) {
                    $response['data'] = array();
                }
                $response['data']['datalayer'] = $datalayer_data;
                error_log('WPForms DataLayer: dati aggiunti alla risposta AJAX: ' . json_encode($datalayer_data));
            }
        }
        
        return $response;
    }

    /**
     * Genera il codice JavaScript per pushare nel dataLayer
     */
    public function push_to_datalayer($data, $echo = false) {
        $json_data = wp_json_encode($data);
        
        $script = "<script>
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({$json_data});
        </script>";
        
        if ($echo) {
            echo $script;
        }
        
        return $script;
    }
        
    /**
     * Carica gli script per gestire invii
     */
    public function enqueue_scripts() {
        // Assicurati che jQuery sia caricato
        wp_enqueue_script('jquery');
        
        // Registra e carica lo script
        wp_enqueue_script(
            'wpforms-datalayer-integration',
            plugin_dir_url(__FILE__) . 'js/wpforms-datalayer.js',
            array('jquery'), // Assicurati che jquery sia una dipendenza
            '1.0.2', // Aggiorna la versione per forzare il refresh della cache
            true  // Carica nel footer
        );
        
        // Passa dati all'oggetto JavaScript
        wp_localize_script(
            'wpforms-datalayer-integration',
            'wpformsDataLayer',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'forms' => array()
            )
        );
        
        // Gestisci invii non-AJAX dopo il refresh della pagina
        if (!session_id()) {
            session_start();
        }
        
        if (isset($_SESSION['wpforms_datalayer_submission'])) {
            $data = $_SESSION['wpforms_datalayer_submission'];
            
            // Aggiungi lo script in pagina
            add_action('wp_footer', function() use ($data) {
                echo $this->push_to_datalayer($data);
            });
            
            // Rimuovi dalla sessione per evitare duplicati
            unset($_SESSION['wpforms_datalayer_submission']);
        }
    }

    /**
     * Registra l'ID del form per il JavaScript
     */
    public function register_form_for_js($form_data, $form, $title, $description, $errors) {
        // Aggiungi informazioni sul modulo allo script
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

// Inizializza il plugin
$wpforms_datalayer_integration = new WPForms_DataLayer_Integration();

// --- INIZIO INTEGRAZIONE PLUGIN UPDATE CHECKER ---

$puc_path = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

if (file_exists($puc_path)) {
    require_once $puc_path;

    // FIX: Richiamo il namespace completo direttamente qui senza usare "use"
    $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/CreativeMetrics/wpforms-datalayer',
        __FILE__,
        'wpforms-datalayer-integration'
    );

    $myUpdateChecker->setBranch('main');

    // AGGIUNTA ICONA E BANNER
    $myUpdateChecker->addResultFilter(function ($info) {
        $plugin_url = plugin_dir_url(__FILE__);
        
        // 1. Definisci le icone
        $info->icons = array(
            '1x' => $plugin_url . 'assets/icon-128x128.png',
            '2x' => $plugin_url . 'assets/icon-256x256.png'
        );
        
        // 2. Definisci i banner
        $info->banners = array(
            '1x' => $plugin_url . 'assets/banner-772x250.jpg',
            '2x' => $plugin_url . 'assets/banner-1544x500.jpg'
        );
        
        return $info;
    });
}

// --- FINE INTEGRAZIONE PLUGIN UPDATE CHECKER ---



// Aggiungi un cron job per pulire le vecchie sottomissioni AJAX
add_action('init', function() {
    global $wpdb;
    
    $options = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wpforms_datalayer_ajax_%'");
    
    foreach ($options as $option) {
        $submission_id = str_replace('wpforms_datalayer_ajax_', '', $option->option_name);
        
        // Se il transient è scaduto, elimina i dati
        if (!get_transient('wpforms_datalayer_cleanup_' . $submission_id)) {
            delete_option($option->option_name);
        }
    }
});

// FIX CHIRURGICO: Eliminata la parentesi graffa } in eccesso che rompeva il file