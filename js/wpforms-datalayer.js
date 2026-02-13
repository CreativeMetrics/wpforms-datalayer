/**
 * WPForms DataLayer Integration Script
 * Gestisce l'invio dei dati al dataLayer per le sottomissioni
 */
(function() {
    'use strict';
    
    // Verifica che jQuery sia disponibile
    if (typeof jQuery === 'undefined') {
        console.error('WPForms DataLayer Integration: jQuery non è disponibile. Impossibile inizializzare il plugin.');
        return;
    }
    
    // Usa jQuery in modo sicuro
    jQuery(function($) {
        // Inizializza il dataLayer se non esiste
        window.dataLayer = window.dataLayer || [];
        
        // Tieni traccia dei form già processati (per evitare duplicati)
        var processedForms = {};
        
        console.log('WPForms DataLayer Integration Script inizializzato');
        
        // Metodo per intercettare direttamente il submit del form
        $(document).on('submit', '.wpforms-form', function(e) {
            var formId = $(this).data('formid');
            console.log('Form WPForms con ID ' + formId + ' è stato inviato');
            
            // Non fermiamo l'invio, solo registriamo l'evento
        });
        
$(document).ajaxComplete(function(event, xhr, settings) {
    console.log('AJAX completato:', settings.url);
    
    // Verifica se è una richiesta di WPForms
    if (settings.url && 
        (settings.url.indexOf('wp-admin/admin-ajax.php') !== -1 || 
         settings.url.indexOf('wpforms/submit') !== -1)) {
        
        console.log('Rilevata risposta AJAX potenzialmente da WPForms');
        
        try {
            var response = JSON.parse(xhr.responseText);
            console.log('Risposta AJAX parsata:', response);
            
            // Controllo più completo della struttura della risposta
            if (response && response.data && response.data.datalayer) {
                console.log('Trovati dati dataLayer in response.data.datalayer');
                handleDataLayerData(response.data.datalayer);
            } 
            else if (response && response.datalayer) {
                console.log('Trovati dati dataLayer in response.datalayer');
                handleDataLayerData(response.datalayer);
            }
            else if (response && response.success && response.data) {
                console.log('Risposta success ma nessun dato dataLayer, controlla la struttura:', response.data);
                // A volte i dati potrebbero essere in una posizione diversa
                // o aggiunti come parte del messaggio di conferma
            }
        } catch (e) {
            console.log('Errore nel parsing della risposta AJAX:', e);
        }
    }
});
        
        // Gestisci anche l'evento specifico di WPForms (come fallback)
        $(document).on('wpformsAjaxSubmitSuccess', function(e, details, response) {
            console.log('Evento wpformsAjaxSubmitSuccess rilevato', details, response);
            
            // Se abbiamo una risposta con i dati dataLayer
            if (response && response.datalayer) {
                handleDataLayerData(response.datalayer);
            }
        });
        
// Funzione centralizzata per gestire i dati del dataLayer
function handleDataLayerData(datalayerData) {
    if (!datalayerData) {
        console.log('Nessun dato dataLayer trovato nella risposta');
        return;
    }
    
    console.log('Dati dataLayer trovati:', datalayerData);
    
    // Evita push duplicati controllando l'ID di sottomissione
    var submissionId = datalayerData.submissionId;
    
    if (!submissionId) {
        console.log('Nessun submissionId trovato nei dati del dataLayer');
        submissionId = 'temp_' + new Date().getTime();
    }
    
    if (!window.wpformsDataLayerPushed) {
        window.wpformsDataLayerPushed = {};
    }
    
    if (!window.wpformsDataLayerPushed[submissionId]) {
        // Push dei dati nel dataLayer
        console.log('Esecuzione push nel dataLayer:', datalayerData);
        window.dataLayer.push(datalayerData);
        
        // Segna come processato
        window.wpformsDataLayerPushed[submissionId] = true;
        console.log('Form con submissionId ' + submissionId + ' processato');
    } else {
        console.log('Form già processato per submissionId ' + submissionId);
    }
}
    });
})();