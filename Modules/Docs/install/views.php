<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index.php' => [
        'description' => 'The handbooks in the backend (editors, admins, templateprogrammers and those of installed components, e.g. module-ai): a page (page, e.g. \'admins/10-module.md\', optional anchor), the start page of a handbook (book), a full-text search (q) or the keyword index (view=index, book).',
        'params'      => ['page' => 'string', 'anchor' => 'string', 'q' => 'string', 'book' => 'string', 'view' => ['index']],
    ],
];
