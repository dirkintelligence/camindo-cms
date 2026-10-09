<?php
// Core-Extension Snippets: Snippet-Auswahl neben einem Textfeld - Suchfenster über
// alle Snippets des Caches (Core, Module, Prozessoren, Extensions, projekteigene
// Sets des Moduls Snippets); die Auswahl schreibt '#'+Snippet-Id ins Feld, das
// Suffix zeigt den aufgelösten Text (docs/developers/13-component-management.md, 3.7).
// Standard für die 'label'-Felder der Module.
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
