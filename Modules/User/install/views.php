<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'Community: content to review - one button per content area with the number of records waiting for review.',
    ],
];
