<?php
// Core-Modul Audit: das Audit-Log (Tabelle `audit_log`, Camindo\Core\AuditLog)
// durchsuchen - wer hat wann was geändert, mit Vorher/Nachher.
// 'superuser' => true hält alle anderen Gruppen fern (Camindo\Core\Access::module());
// den Verlauf eines einzelnen Datensatzes sieht jeder Leser im Reiter "Änderungen"
// der Datensatz-Info.
return [

'version' => '9.0.0',
'type' => 'audit',
'label' => '#audit.module_label',
'description' => '#audit.module_description',
'icon' => 'manage_history',
'listed' => true,
'superuser' => true,
// audit.search / audit.history - ohne Superuser nur Datensätze lesbarer Bereiche (AuditApi)
'api' => ['AuditApi'],
// Zusätzliche Modulrechte: keine - das Modul ist ohnehin dem Superuser vorbehalten
'access' => [
]

];
