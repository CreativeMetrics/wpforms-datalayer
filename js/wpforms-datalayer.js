/**
 * WPForms DataLayer Integration Script
 * Gestisce l'invio dei dati al dataLayer per le sottomissioni
 */
(function() {
    'use strict';
    
    if (typeof jQuery === 'undefined') {
        return; // Sostituito il console.error per pulizia
    }
    
    jQuery(function($) {
        window.dataLayer = window.dataLayer || [];
        var processedForms = {};
        
        // Custom logger che rispetta l'impostazione "Debug" di WPForms
        function logDebug(message, data) {
            // Controlla se la modalità debug è attiva nel payload del modulo inviato
            if (data && data._debug === true) {
                console.log('🟢 [WPForms DataLayer]: ' + message, data);
            }
        }
        
        $(document).on('submit', '.wpforms-form', function(e) {
            var formId = $(this).data('formid');
            // Logica base mantenuta, log silenziato
        });
        
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('wp-admin/admin-ajax.php') !== -1 || settings.url.indexOf('wpforms/submit') !== -1)) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.data && response.data.datalayer) {
                        handleDataLayerData(response.data.datalayer);
                    } 
                    else if (response && response.datalayer) {
                        handleDataLayerData(response.datalayer);
                    }
                } catch (e) {
                    // Errore parsing ignorato silenziosamente in produzione
                }
            }
        });
        
        $(document).on('wpformsAjaxSubmitSuccess', function(e, details, response) {
            if (response && response.datalayer) {
                handleDataLayerData(response.datalayer);
            }
        });
        
        function handleDataLayerData(datalayerData) {
            if (!datalayerData) return;
            
            var submissionId = datalayerData.submissionId;
            
            if (!submissionId) {
                submissionId = 'temp_' + new Date().getTime();
            }
            
            if (!window.wpformsDataLayerPushed) {
                window.wpformsDataLayerPushed = {};
            }
            
            if (!window.wpformsDataLayerPushed[submissionId]) {
                // Rimuoviamo la chiave di servizio _debug prima di mandarla a Google
                var isDebugActive = datalayerData._debug === true;
                delete datalayerData._debug; 

                // Push dei dati nel dataLayer
                window.dataLayer.push(datalayerData);
                
                // Stampa nella console SOLO se il checkbox "Debug" è spuntato su WPForms
                if (isDebugActive) {
                    console.log('🟢 [WPForms DataLayer] Push completato:', datalayerData);
                }
                
                window.wpformsDataLayerPushed[submissionId] = true;
            }
        }
    });
})();