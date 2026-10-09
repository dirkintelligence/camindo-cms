<?php
 // Views of the module that a tool may open (Core\BackendViews, e.g. the
 // assistant of the module Ai with ui__open): script => description (English, for the
 // language model) and allowed parameters ('int', 'string' or list of allowed values).
 // Only scripts that change nothing when opened - no actions, no ajax scripts.
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
