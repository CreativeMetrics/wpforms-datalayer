/**
 * WPForms DataLayer Integration Script
 * Gestisce l'invio dei dati al dataLayer per le sottomissioni
 */
(function() {
    'use strict';
    
    if (typeof jQuery === 'undefined') return;
    
    jQuery(function($) {
        window.dataLayer = window.dataLayer || [];
        
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('wp-admin/admin-ajax.php') !== -1 || settings.url.indexOf('wpforms/submit') !== -1)) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    // Cerca il datalayer nella risposta AJAX
                    if (response && response.data && response.data.datalayer) {
                        handleDataLayerData(response.data.datalayer);
                    } else if (response && response.datalayer) {
                        handleDataLayerData(response.datalayer);
                    }
                } catch (e) {}
            }
        });
        
        // Intercetta l'evento nativo di WPForms in caso l'AJAX standard venga bloccato
        $(document).on('wpformsAjaxSubmitSuccess', function(e, response) {
            if (response && response.data && response.data.datalayer) {
                handleDataLayerData(response.data.datalayer);
            } else if (response && response.datalayer) {
                handleDataLayerData(response.datalayer);
            }
        });
        
        function handleDataLayerData(datalayerData) {
            if (!datalayerData) return;
            
            var submissionId = datalayerData.submissionId || 'temp_' + new Date().getTime();
            
            if (!window.wpformsDataLayerPushed) {
                window.wpformsDataLayerPushed = {};
            }
            
            if (!window.wpformsDataLayerPushed[submissionId]) {
                // FIX: Controllo molto più permissivo sul flag di debug (cattura true, 1, "1", "true")
                var isDebugActive = (
                    datalayerData._debug === true || 
                    datalayerData._debug === '1' || 
                    datalayerData._debug == 1 || 
                    String(datalayerData._debug).toLowerCase() === 'true'
                );
                
                delete datalayerData._debug; // Pulizia della chiave prima dell'invio a Google
                
                // Push dei dati nel dataLayer
                window.dataLayer.push(datalayerData);
                
                // Ora il log apparirà correttamente!
                if (isDebugActive) {
                    console.log('🟢 [WPForms DataLayer AJAX] Push completato:', datalayerData);
                }
                
                window.wpformsDataLayerPushed[submissionId] = true;
            }
        }
    });
})();