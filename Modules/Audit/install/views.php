<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index.php' => [
        'description' => 'Audit log: all changes (who changed what when, from which channel such as backend, api, llm), filterable by object type, object id, administrator, action, period (from/to as Y-m-d), text in label or name and changed field.',
        'params'      => ['object_type' => 'string', 'object_id' => 'int', 'admin_id' => 'int',
            'action' => ['create', 'update', 'delete', 'restore', 'purge', 'login', 'login_failed', 'logout', 'export'],
            'from' => 'string', 'to' => 'string', 'search' => 'string', 'field' => 'string'],
    ],
    'audit_info.php' => [
        'description' => 'One audit log entry with the values before and after the change.',
        'params'      => ['id' => 'int'],
    ],
];
