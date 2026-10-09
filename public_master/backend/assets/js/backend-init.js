/*
 * backend-init.js – Central initialization
 *
 * Loaded as the last script.
 * Starts all cApp modules in the correct order.
 */
$(document).ready(() => {
    cApp.system.init  && cApp.system.init();     // Base infrastructure
    cApp.forms.init   && cApp.forms.init();      // Form tracking
    cApp.ui.init      && cApp.ui.init();         // UI events
    cApp.aside.init   && cApp.aside.init();      // Aside panel
    cApp.ctxMenu.init && cApp.ctxMenu.init();    // Context menus
    cApp.polling.init && cApp.polling.init();    // Polling
    cApp.mail.init    && cApp.mail.init();       // Internal messages (envelope icon)
    cApp.polling.init && cApp.smartrows.init();  // Row manipulation in forms
    cApp.embedded.init && cApp.embedded.init();  // Embedded content (field type 'link')
    cApp.extensions && cApp.extensions.fieldForm.init(); // Field extensions: selection in the field mask
    cApp.layers.init  && cApp.layers.init();     // Layers + hash routing (last!)
});
