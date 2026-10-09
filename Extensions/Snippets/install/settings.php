<?php
// Core extension Snippets: snippet picker next to a text field - search window over
// all snippets in the cache (core, modules, processors, extensions, project-specific
// sets of the module Snippets); the selection writes '#'+snippet id into the field, the
// suffix shows the resolved text (developer docs, chapter 08 (Components and extensions)).
// Default for the 'label' fields of the modules.
return [
    'version'       => '9.0.0',
    'type'          => 'picker',
    'label'         => '#ext.snippets.label',
    'description'   => '#ext.snippets.description',
    'display_types' => ['text'],
    'assets' => [
        'js'  => ['js/snippets.ext.js'],
        'css' => ['css/snippets.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
