<?php
// Core-Modul Logfiles: die Protokolle unter var/log im Backend recherchieren.
// 'superuser' => true hält alle anderen Gruppen fern (Camindo\Core\Access::module());
// das Widget zeigt die wichtigsten Meldungen der letzten Tage auf der Startseite.
return [

'version' => '9.0.0',
'type' => 'logfiles',
'label' => '#logfiles.module_label',
'description' => '#logfiles.module_description',
'icon' => 'receipt_long',
'listed' => true,
'superuser' => true,
'widget' => [
    'class'  => 'LogfilesBackend',
    'method' => 'widget',
    'label'  => '#logfiles.module_label',
    'icon'   => 'receipt_long',
],
// Zusätzliche Modulrechte: keine - das Modul ist ohnehin dem Superuser vorbehalten
'access' => [
]

];
