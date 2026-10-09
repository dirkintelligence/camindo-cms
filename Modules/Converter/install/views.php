<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'converter_list.php' => [
        'description' => 'Media converters: rules that create thumbnails and other variants of uploaded images and videos.',
    ],
    'converter_search.php' => [
        'description' => 'Search form for converters.',
    ],
    'converter_create.php' => [
        'description' => 'First step of a new converter: choice of the processor (e.g. ImageMagick, GD, ffmpeg).',
    ],
    'converter_info.php' => [
        'description' => 'Detail view of a converter; tab=usage: which fields use it.',
        'params'      => ['id' => 'int', 'tab' => ['usage']],
    ],
    'converter_edit.php' => [
        'description' => 'Edit form of a converter. function=copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['copy', 'delete']],
    ],
];
