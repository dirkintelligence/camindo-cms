<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'admin',
'label' => '#admin.module_label',
'description' => '#admin.module_description',
'icon' => 'person',
'listed' => true,
// Scripte, die andere Module ohne Modulzugriff als Lookup aufrufen dürfen (Access::script())
'shared' => ['admin_list.php', 'admin_search.php', 'ajax_lookup_preview.php'],
// Scripte, die jeder Admin ohne Modulzugriff aufrufen darf: die eigenen Einstellungen,
// Passwort, Zwei-Faktor-Anmeldung, Geräte und Sitzungen (Access::script(); fremde
// Konten nur als Superuser)
'personal' => ['settings.php', 'totp.php', 'password.php', 'devices.php'],
// Zusätzliche Modulrechte:
//     Admin-Namen sind immer lesbar, z.B. Datensatz erstellt von ... am ...
//     Ob die Admin-Details (Kontaktdaten) gelesen werden dürfen entscheidet admin_read*
//     Anlegen (admin_create) und Ändern/Sperren (admin_change) gelten für Admins der
//     eigenen Gruppe, die Gruppe ist dabei nicht änderbar. Das Passwort eines anderen
//     Admins setzt nur ein Superuser (bzw. das Anlegen das Startpasswort).
//     Der Rest ist dem Superuser vorbehalten.
'access' => [
    'admin_read_group' => 'admin.access_admin_read_group',    // aus eigener Gruppe
    'admin_read_other' => 'admin.access_admin_read_other',    // aus anderer Gruppe
    'admin_create' => 'admin.access_admin_create',            // anlegen, kopieren (eigene Gruppe)
    'admin_change' => 'admin.access_admin_change',            // ändern, sperren (eigene Gruppe)
]

];