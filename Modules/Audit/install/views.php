<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
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
