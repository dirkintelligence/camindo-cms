<?php
// Core-Modul Docs: die Handbücher im Backend (Hauptmenü → Handbücher).
// Nicht 'listed' und ohne 'button': jeder Gruppe offen, keine Rechteverwaltung,
// keine Kachel auf der Startseite (Access::module(), StartBackend).
return [

'version' => '9.0.0',
'type' => 'docs',
'label' => '#docs.module_label',
'description' => '#docs.module_description',
'icon' => 'menu_book',
'listed' => false,
// API: Handbücher suchen und lesen (DocsApi)
'api' => ['DocsApi'],
'access' => [
]

];
