<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'scheduler_list.php' => [
        'description' => 'Scheduler: recurring tasks (templates, cleanups, imports …) with interval and last run.',
    ],
    'scheduler_create.php' => [
        'description' => 'First step of a new task: choice of the task type.',
    ],
    'scheduler_info.php' => [
        'description' => 'Detail view of a task: type, interval, time window, administrator it runs as, last runs.',
        'params'      => ['id' => 'int'],
    ],
    'scheduler_edit.php' => [
        'description' => 'Edit form of a task. function=copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['copy', 'delete']],
    ],
];
