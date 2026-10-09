<?php
 // Views of the module that a tool may open (Core\BackendViews, e.g. the
 // assistant of the module Ai with ui__open): script => description (English, for the
 // language model) and allowed parameters ('int', 'string' or list of allowed values).
 // Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'The handbooks in the backend (editors, admins, templateprogrammers and those of installed components, e.g. module-ai): a page (page, e.g. \'admins/10-module.md\', optional anchor), the start page of a handbook (book), a full-text search (q) or the keyword index (view=index, book).',
        'params'      => ['page' => 'string', 'anchor' => 'string', 'q' => 'string', 'book' => 'string', 'view' => ['index']],
    ],
];
