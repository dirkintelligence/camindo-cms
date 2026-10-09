<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index.php' => [
        'description' => 'Contents: tree of the instances, folders and content areas the administrator may read; starting point to open the records of an area.',
    ],
    'record_list.php' => [
        'description' => 'Records of an area as a list (columns, paging, sorting, menu with batch processing, export, import).',
        'params'      => ['area_id' => 'int'],
    ],
    'record_search.php' => [
        'description' => 'Search form of an area (fields, comparisons, languages).',
        'params'      => ['area_id' => 'int'],
    ],
    'record_global_search.php' => [
        'description' => 'Search across all readable areas (id, slug, URL or text).',
    ],
    'record_info.php' => [
        'description' => 'Detail view of a record with the tabs Contents, Meta data (status, owner, times) and Changes (tab=audit); buttons Edit, Copy, Delete, Preview.',
        'params'      => ['area_id' => 'int', 'id' => 'int', 'tab' => ['meta', 'audit']],
    ],
    'record_edit.php' => [
        'description' => 'Edit form of a record (checks the record out until saved or cancelled). function=create with area_id: new record; copy / delete with id.',
        'params'      => ['area_id' => 'int', 'id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
    'record_move.php' => [
        'description' => 'Move a record to another area: choose the target area and the field mapping.',
        'params'      => ['area_id' => 'int', 'id' => 'int'],
    ],
    'record_fulltext.php' => [
        'description' => 'Full-text fields of a record (what the full-text search finds).',
        'params'      => ['id' => 'int'],
    ],
    'record_batch.php' => [
        'description' => 'Batch processing of an area: change, move, delete or rebuild the full text of many records.',
        'params'      => ['area_id' => 'int'],
    ],
    'record_export.php' => [
        'description' => 'Export of the records of an area as CSV, DOCX (translation) or XML.',
        'params'      => ['area_id' => 'int'],
    ],
    'record_import.php' => [
        'description' => 'Import of records into an area from CSV, DOCX or XML.',
        'params'      => ['area_id' => 'int'],
    ],
    'record_media_import.php' => [
        'description' => 'Media import: create records from uploaded files (images, videos, documents).',
        'params'      => ['area_id' => 'int'],
    ],
    'record_second_factor.php' => [
        'description' => 'Second sign-in factor of a community user (profile record): state, reset.',
        'params'      => ['area_id' => 'int', 'id' => 'int'],
    ],
];
