<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'messaging',
'label' => '#messaging.module_label',
'description' => '#messaging.module_description',
'icon' => 'mail',
'listed' => false,
// Zusätzliche Modulrechte:
// keine
// Jeder Admin hat nur Zugriff auf seine In- und Outbox
'access' => [
]

];
