<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'language',
'label' => '#language.module_label',
'description' => '#language.module_description',
'icon' => 'flag',
'listed' => true,
// API: Sprachen lesen und anlegen/ändern (LanguageApi)
'api' => ['LanguageApi'],
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
'access' => [
]

];