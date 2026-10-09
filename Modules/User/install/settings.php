<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'user',
'label' => '#user.module_label',
'description' => '#user.module_description',
'icon' => 'person',
'listed' => false,
/*
   Das Modul zeigt sich nur als Widget
   'listed' bleibt gesetzt, damit es weiter der Rechteverwaltung unterliegt
   nur wer das Modul darf, bekommt das Widget.
*/
'widget' => [
    'class'  => 'UserBackend',
    'method' => 'widget',
    'label'  => '#user.headline_unreviewed',
    'icon'   => 'emergency',
],
// Zusätzliche Modulrechte:
// keine
'access' => [
]

];
