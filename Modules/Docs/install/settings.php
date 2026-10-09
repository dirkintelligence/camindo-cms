<?php
 // Core module Docs: the manuals in the backend (main menu → manuals).
 // Not 'listed' and without 'button': open to every group, no rights management,
 // no tile on the start page (Access::module(), StartBackend).
return [

'version' => '9.0.0',
'type' => 'docs',
'label' => '#docs.module_label',
'description' => '#docs.module_description',
'icon' => 'menu_book',
'listed' => false,
 // API: search and read manuals (DocsApi)
'api' => ['DocsApi'],
'access' => [
]

];
