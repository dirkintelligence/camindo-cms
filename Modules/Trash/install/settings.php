<?php
// Core module Trash: each administrator's trash - view and restore deleted records (superusers
// also areas).
// Not 'listed': open to every group, no permission management (Access::module()).
// 'button' => true: still a tile on the start page (StartBackend).
return [

'version' => '9.0.0',
'type' => 'trash',
'label' => '#trash.module_label',
'description' => '#trash.module_description',
'icon' => 'delete',
'listed' => false,
'button' => true,
// trash.list (TrashApi); restoring via record.restore
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
