<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'record',
'label' => '#record.module_label',
'description' => '#record.module_description',
'icon' => 'draft',
'listed' => true,
// Scripte, die andere Module ohne Modulzugriff als Lookup aufrufen dürfen (Access::script())
'shared' => ['record_list.php', 'record_search.php', 'ajax_ext_lookup.php'],
// Zusatzrechte (Backend-Benutzergruppen): interner Name => Snippet der Beschriftung.
// Durchgesetzt werden sie in den Backend-Klassen des Moduls (Access::moduleRight()).
// XML-Import/-Export (Backup-Format, auch global) bleiben dem Superuser vorbehalten.
// API-Klassen (Camindo\Core\Api\Api, relativ zum Modul-Namespace)
'api' => ['RecordApi'],
'access' => [
    'import_csv' => 'record.access_import_csv',
    'import_media' => 'record.access_import_media',
    'export_csv' => 'record.access_export_csv',
    'import_docx' => 'record.access_import_docx',
    'export_docx' => 'record.access_export_docx',
    'batch' => 'record.access_batch',
    // Datenexport personenbezogener Daten: Modul Privacy, Zusatzrecht 'export'
],

];