<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'status_list.php' => [
        'description' => 'Record status values (e.g. online, draft) with their meaning for the websites.',
    ],
    'status_info.php' => [
        'description' => 'Detail view of a status.',
        'params'      => ['id' => 'int'],
    ],
    'status_edit.php' => [
        'description' => 'Edit form of a status. function=create: new status; copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
];
