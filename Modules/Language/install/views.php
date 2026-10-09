<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'language_list.php' => [
        'description' => 'Content languages of the instances (code, label, backend language, fallback).',
    ],
    'language_info.php' => [
        'description' => 'Detail view of a content language.',
        'params'      => ['id' => 'int'],
    ],
    'language_edit.php' => [
        'description' => 'Edit form of a content language. function=create: new language; copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
];
