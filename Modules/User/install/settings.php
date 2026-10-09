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
   The module only shows up as a widget
   'listed' stays set so that it remains subject to permission management
   only whoever may use the module gets the widget.
*/
'widget' => [
    'class'  => 'UserBackend',
    'method' => 'widget',
    'label'  => '#user.headline_unreviewed',
    'icon'   => 'emergency',
],
// Additional module permissions:
// none
'access' => [
]

];
