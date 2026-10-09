<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'Trash: deleted records (one entry per delete operation) - restore or delete permanently.',
    ],
];
