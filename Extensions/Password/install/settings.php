<?php
// Core-Extension Password: Passwort-Richtlinie als Sprechblase und Stärke-Balken
// (rot/orange/grün) unter einem Passwort-Feld. Die Regeln kommen aus
// security.password_policy, je Feld überschreibbar; geprüft wird zusätzlich auf dem
// Server (Camindo\Core\PasswordPolicy, developer_docs/16-sicherheit.md §12).
// password.ext.js/.css laufen auch ohne Backend - im Frontend-Template inline
// eingefügt (docs/templateprogrammers).
return [
    'version'          => '9.0.0',
    'type'             => 'validator',
    'label'            => '#ext.password.label',
    'description'      => '#ext.password.description',
    'display_types'    => ['password'],
    'field_types'      => ['password'],
    'options_provider' => 'Camindo\\Core\\PasswordPolicy',
    'assets' => [
        'js'  => ['js/password.ext.js'],
        'css' => ['css/password.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];
