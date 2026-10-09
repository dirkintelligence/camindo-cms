<?php
// Core-Modul Trash: der Papierkorb jedes Admins - gelöschte Datensätze (Superuser
// auch Bereiche) ansehen und wiederherstellen.
// Nicht 'listed': jeder Gruppe offen, keine Rechteverwaltung (Access::module()).
// 'button' => true: trotzdem eine Kachel auf der Startseite (StartBackend).
return [

'version' => '9.0.0',
'type' => 'trash',
'label' => '#trash.module_label',
'description' => '#trash.module_description',
'icon' => 'delete',
'listed' => false,
'button' => true,
// trash.list (TrashApi); Wiederherstellen über record.restore
'api' => ['TrashApi'],
'widget' => [
    'class'  => 'TrashBackend',
    'method' => 'widget',
    'label'  => '#trash.module_label',
    'icon'   => 'delete',
],
'access' => [
]

];
