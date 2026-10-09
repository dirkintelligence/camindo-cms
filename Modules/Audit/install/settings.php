<?php
// Core module Audit: the audit log (table `audit_log`, Camindo\Core\AuditLog)
// search - who changed what and when, with before/after.
// 'superuser' => true keeps all other groups out (Camindo\Core\Access::module());
// every reader sees the history of a single record in the "Changes" tab
// of the record info.
return [

'version' => '9.0.0',
'type' => 'audit',
'label' => '#audit.module_label',
'description' => '#audit.module_description',
'icon' => 'manage_history',
'listed' => true,
'superuser' => true,
// audit.search / audit.history - without superuser only records of readable areas (AuditApi)
'api' => ['AuditApi'],
// Additional module permissions: none - the module is reserved for superusers anyway
'access' => [
]

];
