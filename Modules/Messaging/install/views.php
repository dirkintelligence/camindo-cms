<?php
 // Views of the module that a tool may open (Core\BackendViews, e.g. the
 // assistant of the module Ai with ui__open): script => description (English, for the
 // language model) and allowed parameters ('int', 'string' or list of allowed values).
 // Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'message_list.php' => [
        'description' => 'Internal messages of the administrator: inbox, or tab=outbox for sent messages.',
        'params'      => ['tab' => ['outbox']],
    ],
    'message_info.php' => [
        'description' => 'One internal message.',
        'params'      => ['id' => 'int'],
    ],
    'message_edit.php' => [
        'description' => 'Write an internal message: function=create (optional record_id: message about a record), forward with id.',
        'params'      => ['id' => 'int', 'record_id' => 'int', 'function' => ['create', 'forward']],
    ],
];
