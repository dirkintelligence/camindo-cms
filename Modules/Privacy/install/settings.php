<?php
// Core-Modul Privacy (Datenschutz): Lösch-Policies der Inhaltsbereiche verwalten und
// personenbezogene Daten von Website-Usern und Benutzern exportieren, gelöschte
// Benutzer anonymisieren.
// Ohne Zusatzrecht sieht die Gruppe die Policies nur; Superuser dürfen alles.
return [

'version' => '9.1.1',
'type' => 'privacy',
'label' => '#privacy.module_label',
'description' => '#privacy.module_description',
'icon' => 'shield',
'listed' => true,
// Zusatzrechte (Backend-Benutzergruppen): interner Name => Snippet der Beschriftung.
'access' => [
    // Policies anlegen, ändern, löschen - nur für Instanzen, die die Gruppe bearbeiten
    // darf; Policies für alle Instanzen (Standard-Policies) nur der Superuser
    'policy_edit' => 'privacy.access_policy_edit',
    // Datenexport personenbezogener Daten: Website-User aus lesbaren Profil-Bereichen,
    // Benutzer aus Gruppen, deren Benutzer die Gruppe im Modul Benutzer sehen darf
    'export' => 'privacy.access_export',
    // Vorlagen der Datenauskunft anlegen, ändern, löschen - nur für Instanzen, die
    // die Gruppe bearbeiten darf
    'template_edit' => 'privacy.access_template_edit',
    // Gelöschte Benutzer anonymisieren (Reiter "Gelöschte Benutzer", AdminAnonymizer) -
    // nur Benutzer aus Gruppen, die die Gruppe im Modul Benutzer sehen darf
    'admin_anonymize' => 'privacy.access_admin_anonymize',
],
// Reiter, die ein Zusatzrecht verlangen (Access::script())
'script_access' => [
    'export.php' => 'export',
    'export_download.php' => 'export',
    'admin_anonymize.php' => 'admin_anonymize',
],

];
