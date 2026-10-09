<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'record',
'label' => '#record.module_label',
'description' => '#record.module_description',
'icon' => 'draft',
'listed' => true,
// Scripts that other modules may call as a lookup without module access (Access::script())
'shared' => ['record_list.php', 'record_search.php', 'ajax_ext_lookup.php'],
// Additional rights (backend user groups): internal name => snippet of the label.
// They are enforced in the backend classes of the module (Access::moduleRight()).
// XML import/export (backup format, also global) stays reserved for the superuser.
// API classes (Camindo\Core\Api\Api, relative to the module namespace)
'api' => ['RecordApi'],
'access' => [
    'import_csv' => 'record.access_import_csv',
    'import_media' => 'record.access_import_media',
    'export_csv' => 'record.access_export_csv',
    'import_docx' => 'record.access_import_docx',
    'export_docx' => 'record.access_export_docx',
    'batch' => 'record.access_batch',
    // Export of personal data: module Privacy, additional right 'export'
],

];