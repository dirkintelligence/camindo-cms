/*
 * backend-init.js – Zentrale Initialisierung
 *
 * Wird als letztes Script geladen.
 * Startet alle cApp-Module in der korrekten Reihenfolge.
 */
$(document).ready(() => {
    cApp.system.init  && cApp.system.init();     // Basis-Infrastruktur
    cApp.forms.init   && cApp.forms.init();      // Formular-Tracking
    cApp.ui.init      && cApp.ui.init();         // UI-Events
    cApp.aside.init   && cApp.aside.init();      // Aside-Panel
    cApp.ctxMenu.init && cApp.ctxMenu.init();    // Kontextmenüs
    cApp.polling.init && cApp.polling.init();    // Polling
    cApp.mail.init    && cApp.mail.init();       // Interne Nachrichten (Brief-Symbol)
    cApp.polling.init && cApp.smartrows.init();  // Zeilenmanipuation in Formularen
    cApp.embedded.init && cApp.embedded.init();  // eingebettete Inhalte (Feldtyp 'link')
    cApp.extensions && cApp.extensions.fieldForm.init(); // Feld-Extensions: Auswahl in der Feldmaske
    cApp.layers.init  && cApp.layers.init();     // Layer + Hash-Routing (zuletzt!)
});
