<?php
// Core module Logfiles: search the logs under var/log in the backend.
// 'superuser' => true keeps all other groups out (Camindo\Core\Access::module());
// the widget shows the most important messages of the last few days on the start page.
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
// Additional module permissions: none - the module is reserved for superusers anyway
'access' => [
]

];
