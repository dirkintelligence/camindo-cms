<?php
 // Views of the module that a tool may open (Core\BackendViews, e.g. the
 // assistant of the module Ai with ui__open): script => description (English, for the
 // language model) and allowed parameters ('int', 'string' or list of allowed values).
 // Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'Start page of the backend: available modules and the widgets (e.g. log files, last changes, messages).',
    ],
    'info.php' => [
        'description' => 'About camindo CMS: installed version and, for the superuser, the result of the update check.',
    ],
    'bgproc_list.php' => [
        'description' => 'Own background processes (batch processing, exports, imports) with progress.',
    ],
];
