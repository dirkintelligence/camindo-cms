<?php
/**
 * Dataset-Kindklasse für die Tabelle `privacy_policy` - Lösch-Policies (Modul Privacy).
 *
 * Jeder Inhaltsbereich (record, profile, ugc) hat genau eine Policy
 * (area.privacy_policy_id). Sie legt fest, was geschieht, wenn ein Datensatz des
 * Bereichs gelöscht wird - ausgeführt von PrivacyJobs.
 *
 * AUFBAU
 * --------------------
 *     label        Label (Text oder Snippet #prefix.key)
 *     instance_id  Instanz (Mandant), für die die Policy wählbar ist;
 *                  ALL_INSTANCES (-1) = für alle (die drei Standard-Policies). Nicht
 *                  NULL: Instanz 0 ist die Standard-Instanz, der Typ 'id' machte 0
 *                  zu NULL. Kein Fremdschlüssel - die Policy einer gelöschten
 *                  Instanz bleibt stehen, bis sie jemand löscht.
 *     standard     '' oder record | profile | ugc: Standard-Policy dieses
 *                  Bereichstyps - nicht löschbar, aber änderbar; neue Bereiche
 *                  bekommen sie vorbelegt (Area::hookOnSave())
 *     blocks       JSON-Liste der Blöcke, je Block:
 *         sources  Auslöser, mindestens einer von SOURCES:
 *                      record   der Datensatz wird gelöscht - gleich auf welchem Weg
 *                               (Maske, Stapelverarbeitung, API, CMS:USER_DELETE),
 *                               auch als Unterdatensatz (Kind im Baum, fest
 *                               verknüpfter Inhalt) eines gelöschten Datensatzes
 *                      profile  das Profil des Users (record.user_id) wurde gelöscht -
 *                               gilt immer für alle seine Datensätze, ohne eigene
 *                               Aktion in der Policy des Profils
 *                      area     der Bereich wurde gelöscht - seine Datensätze liegen
 *                               schon im Papierkorb, darum weder soft_delete noch
 *                               set_fields (AREA_ACTIONS)
 *         delay    Zeitpunkt ab dem Auslösen, 0 = sofort
 *         unit     days | years
 *         action   eine von ACTIONS (Gruppen in der Auswahl: ACTION_GROUPS):
 *                      soft_delete       in den Papierkorb - endgültig gelöscht nach
 *                                        admin.trash_retention_days (Zeitplaner, Typ trash)
 *                      set_fields        Felder des Datensatzes überschreiben
 *                      record_trigger    onPrivacyAction() des Trigger-Templates mit
 *                                        dem Datensatz aufrufen; was es an $data
 *                                        ändert, wird gespeichert
 *                      audit_set_fields  Felder im Audit-Log überschreiben
 *                      audit_trigger     onPrivacyAction() für jeden Eintrag im
 *                                        Audit-Log des Datensatzes; was es am Eintrag
 *                                        ändert, wird zurückgeschrieben
 *                      audit_delete      Audit-Log des Datensatzes löschen
 *                      purge             endgültig löschen, samt Audit-Log
 *                  Ein Audit-Log-Eintrag nennt Handelnde nur als Id (AuditLog). Die
 *                  Verbindung zum User bleibt deshalb immer bestehen; anonym wird sie,
 *                  sobald der Profil-Datensatz keine personenbezogenen Daten mehr
 *                  enthält bzw. endgültig gelöscht ist.
 *         category Kategorie des Blocks (CATEGORIES), nur zur Einordnung - ohne
 *                  Funktion beim Ausführen; '' = keine
 *         trigger  für record_trigger / audit_trigger: Kennung (TRIGGER_PATTERN), die
 *                  das Template als $action bekommt - daran unterscheidet es die
 *                  Blöcke
 *         fields   für set_fields / audit_set_fields: Feld => Wert. Feld ist der
 *                  Feldname (status_id, username …) - bei mehrsprachigen Feldern alle
 *                  Sprachen; ein Upload-Feld nimmt nur "" an (Datei samt Thumbnails
 *                  sofort entfernen). Im Audit-Log zusätzlich _label (Bezeichnung des
 *                  Eintrags) und * (alle übrigen Felder - gleich, an welcher Stelle es
 *                  steht). Im Wert: {record_id}, {user_id}.
 * Ob Blöcke einander widersprechen oder doppelt sind, prüft niemand - das ist Sache
 * dessen, der die Policy einrichtet.
 *
 * WER DARF WAS
 * --------------------
 * Policies einer Instanz pflegt, wer das Zusatzrecht policy_edit des Moduls Privacy
 * hat und die Instanz bearbeiten darf; Policies für alle Instanzen (darunter die
 * Standard-Policies) nur der Superuser (canEdit()). Gelöscht werden kann eine Policy
 * nur, wenn sie keine Standard-Policy ist und kein Bereich sie benutzt.
 *
 * @see Camindo\Core\Modules\Privacy\PrivacyJobs     Ausführung
 * @see Camindo\Core\Modules\Privacy\PrivacyBackend  Oberfläche
 */
namespace Camindo\Core\Modules\Privacy;

use Camindo\Core\Access;
use Camindo\Core\Database;
use Camindo\Core\Dataset;

class DeletePolicy extends Dataset {

    public const SOURCES = ['record', 'profile', 'area'];

    /** Aktionen im Audit-Log */
    public const AUDIT_ACTIONS = ['audit_trigger', 'audit_set_fields', 'audit_delete'];

    /** Aktionen in der Reihenfolge, in der sie bei gleichem Zeitpunkt laufen */
    public const ACTIONS = ['record_trigger', 'set_fields', 'soft_delete', 'purge',
        'audit_trigger', 'audit_set_fields', 'audit_delete'];

    /** Aktionen in der Auswahl des Formulars, gruppiert (Snippet privacy.action_group_{gruppe}) */
    public const ACTION_GROUPS = [
        'record' => ['soft_delete', 'set_fields', 'record_trigger'],
        'audit'  => ['audit_set_fields', 'audit_trigger', 'audit_delete'],
        'all'    => ['purge'],
    ];

    /** Aktionen, die den Datensatz selbst entfernen - bei Unterdatensätzen übernimmt sie der Hauptdatensatz */
    public const REMOVE_ACTIONS = ['soft_delete', 'purge'];

    /** Aktionen für den Auslöser 'area': die Datensätze liegen schon im Papierkorb */
    public const AREA_ACTIONS = ['record_trigger', 'purge', 'audit_trigger', 'audit_set_fields', 'audit_delete'];

    /**
     * Kategorien eines Blocks (Einordnung ohne Funktion): in den Papierkorb, nicht mehr
     * benötigte Daten entfernen (Datenminimierung), vollständig anonymisieren,
     * endgültig löschen
     */
    public const CATEGORIES = ['trash', 'minimize', 'anonymize', 'delete'];

    /** Aktionen, die die Datenbank betreffen (Hinweis im Löschdialog) */
    public const DB_ACTIONS = ['record_trigger', 'set_fields', 'soft_delete', 'purge'];

    /** Aktionen, die die Methode onPrivacyAction() des Trigger-Templates aufrufen */
    public const TRIGGER_ACTIONS = ['record_trigger', 'audit_trigger'];

    /** Kennung, die eine Trigger-Aktion dem Template übergibt ($action) */
    public const TRIGGER_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const UNITS = ['days', 'years'];

    /** instance_id einer Policy für alle Instanzen */
    public const ALL_INSTANCES = -1;

    /** Bereichstypen mit Standard-Policy */
    public const TYPES = ['record', 'profile', 'ugc'];

    /**
     * Die drei Standard-Policies - bei der Installation (seed.sql) und mit der
     * Migration 9.1.0 angelegt (ensureStandard()).
     */
    public const STANDARD = [
        'record' => [
            'label' => '#privacy.policy_record',
            'blocks' => [
                ['sources' => ['record', 'profile'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'profile', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
        'profile' => [
            'label' => '#privacy.policy_profile',
            'blocks' => [
                ['sources' => ['record'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'area'], 'delay' => 7, 'unit' => 'days', 'action' => 'audit_set_fields', 'category' => 'anonymize',
                    'fields' => ['_label' => 'user_{record_id}', '*' => '']],
                ['sources' => ['record', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
        'ugc' => [
            'label' => '#privacy.policy_ugc',
            'blocks' => [
                ['sources' => ['record', 'profile'], 'delay' => 0, 'unit' => 'days', 'action' => 'soft_delete', 'category' => 'trash', 'fields' => []],
                ['sources' => ['record', 'profile', 'area'], 'delay' => 1, 'unit' => 'years', 'action' => 'audit_delete', 'category' => 'delete', 'fields' => []],
            ],
        ],
    ];

    /**
     * Felder eines Blocks im Formular (Backend::formField() mit varName 'blocks',
     * Name '{i}.{feld}' -> blocks[{i}][{feld}]). Optionen setzt PrivacyBackend.
     */
    public const BLOCK_SCHEMA = [
        'sources' => [
            'type' => 'json', 'display_type' => 'checkbox',
            'label' => '#privacy.label_sources',
            'tooltip' => '#privacy.tooltip_sources',
        ],
        'delay' => [
            'type' => 'integer', 'display_type' => 'text',
            'label' => '#privacy.label_when',
            'note' => '#privacy.note_delay',
            'min' => 0, 'max' => 1000,
        ],
        'unit' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_unit',
        ],
        'category' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_category',
            'tooltip' => '#privacy.tooltip_category',
        ],
        'action' => [
            'type' => 'chars', 'display_type' => 'select',
            'label' => '#privacy.label_action',
            'flags' => ['not_empty'],
        ],
        'fields' => [
            'type' => 'text', 'display_type' => 'textarea',
            'label' => '#privacy.label_fields',
            'note' => '#privacy.note_fields_short',
            'tooltip' => '#privacy.note_fields',
            'height' => 3,
        ],
        'trigger' => [
            'type' => 'chars', 'display_type' => 'text',
            'label' => '#privacy.label_trigger',
            'note' => 'a..z 0..9 _',
            'tooltip' => '#privacy.note_trigger',
            'pattern' => '[a-z][a-z0-9_]*',
            'max' => 64,
        ],
    ];

    protected function hookDefinition(): void
    {
        $this->tableName  = 'privacy_policy';
        $this->moduleName = 'Privacy';
        $this->defaultSortOrder = 'label ASC';
        $this->schema = [
            'privacy_policy_id' => [
                'type'  => 'integer',
                'label' => 'privacy_policy_id',
            ],
            'instance_id' => [
                'type'  => 'integer',
                'display_type' => 'select',
                'label' => '#privacy.field_instance_id',
                'tooltip' => '#privacy.tooltip_field_instance_id',
                'min'   => self::ALL_INSTANCES,
                'default' => self::ALL_INSTANCES,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#privacy.field_label',
                'max'   => 128,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'standard' => [
                'type'  => 'chars',
                'label' => '#privacy.field_standard',
                'max'   => 8,
            ],
            'blocks' => [
                'type'  => 'json',
                'label' => '#privacy.field_blocks',
            ],
        ];
    }

    /**
     * Blöcke vor dem Speichern in die feste Form bringen (normalizeBlocks()); eine
     * Standard-Policy bleibt Standard-Policy und gilt für alle Instanzen.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (in_array('blocks', $fields, true)) {
            $data['blocks'] = self::normalizeBlocks((array)($data['blocks'] ?? []));
        }
        if (!empty($previousData['standard'])) {
            $data['standard'] = (string)$previousData['standard'];
            $data['instance_id'] = self::ALL_INSTANCES;
            foreach (['standard', 'instance_id'] as $name) {
                if (!in_array($name, $fields, true)) $fields[] = $name;
            }
        }
    }

    /**
     * Löschen nur ohne Verwendung und nicht für Standard-Policies.
     */
    protected function hookOnDelete(array $data): void
    {
        if ((string)($data['standard'] ?? '') !== '') {
            throw new \Exception($this->cms->snippet('privacy.error_delete_standard'));
        }
        if ($this->usageCount((int)$data['privacy_policy_id']) > 0) {
            throw new \Exception($this->cms->snippet('privacy.error_delete_used'));
        }
    }

    /**
     * Blöcke bereinigen: nur bekannte Auslöser, Einheiten und Aktionen ('area' nur
     * mit AREA_ACTIONS); Zeitpunkt
     * ganzzahlig ab 0; Felder nur bei den Aktionen, die welche brauchen. Blöcke ohne
     * Auslöser oder Aktion fallen weg, ebenso Trigger-Aktionen ohne gültige Kennung
     * (das Formular meldet das vorher - PrivacyBackend::edit()).
     *
     * @param array $blocks  Liste wie in der Tabelle oder aus dem Formular
     * @return array
     */
    public static function normalizeBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) continue;
            $sources = array_values(array_intersect(self::SOURCES, array_map('strval', (array)($block['sources'] ?? []))));
            $action = (string)($block['action'] ?? '');
            // Bereich gelöscht: die Datensätze liegen schon im Papierkorb
            if (!in_array($action, self::AREA_ACTIONS, true)) $sources = array_values(array_diff($sources, ['area']));
            if (!$sources || !in_array($action, self::ACTIONS, true)) continue;
            $unit = in_array($block['unit'] ?? '', self::UNITS, true) ? (string)$block['unit'] : 'days';
            $fields = [];
            $trigger = '';
            if (in_array($action, self::TRIGGER_ACTIONS, true)) {
                $trigger = strtolower(trim((string)($block['trigger'] ?? '')));
                if (!preg_match(self::TRIGGER_PATTERN, $trigger)) continue;
            }
            if (in_array($action, ['set_fields', 'audit_set_fields'], true)) {
                $fields = is_array($block['fields'] ?? null) ? $block['fields'] : self::parseFields((string)($block['fields'] ?? ''));
                $clean = [];
                foreach ($fields as $name => $value) {
                    $name = trim((string)$name);
                    if ($name === '' || !preg_match('/^(\*|_label|[a-z][a-z0-9_]*)$/', $name)) continue;
                    $clean[$name] = is_scalar($value) ? (string)$value : '';
                }
                $fields = $clean;
            }
            $category = (string)($block['category'] ?? '');
            $out[] = [
                'sources' => $sources,
                'category' => in_array($category, self::CATEGORIES, true) ? $category : '',
                'delay'   => max(0, min(1000, (int)($block['delay'] ?? 0))),
                'unit'    => $unit,
                'action'  => $action,
                'fields'  => $fields,
                'trigger' => $trigger,
            ];
        }
        return $out;
    }

    /**
     * Felder aus der Eingabe "feld = wert" (eine Zeile je Feld; Wert ohne oder in
     * Anführungszeichen) - Formular der Policy.
     */
    public static function parseFields(string $text): array
    {
        $fields = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_contains($line, '=')) continue;
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) $value = substr($value, 1, -1);
            $fields[$name] = $value;
        }
        return $fields;
    }

    /** Gegenstück zu parseFields(): Felder als Text für das Formular */
    public static function fieldsText(array $fields): string
    {
        $lines = [];
        foreach ($fields as $name => $value) $lines[] = $name . ' = "' . $value . '"';
        return implode("\n", $lines);
    }

    /** Bezeichnung einer Aktion - "In den Papierkorb" mit der Frist des Papierkorbs */
    public function actionLabel(string $action): string
    {
        return strtr($this->cms->snippet('privacy.action_' . $action),
            ['{days}' => (string)max(0, (int)($this->config->get('admin.trash_retention_days') ?? 7))]);
    }

    /** Wie viele Bereiche (auch im Papierkorb) benutzen die Policy? */
    public function usageCount(int $policyId): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT COUNT(*) FROM `area` WHERE `privacy_policy_id` = ?", 'i', [$policyId]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /** Standard-Policy eines Bereichstyps (privacy_policy_id, 0 = keine) */
    public function standardId(string $type): int
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_policy_id` FROM `privacy_policy` WHERE `standard` = ? ORDER BY `privacy_policy_id` LIMIT 1", 's', [$type]);
            return (int)($this->db->fetchNum()[0] ?? 0);
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Policies, die ein Bereich der Instanz wählen kann: die für alle Instanzen und
     * die der Instanz selbst.
     *
     * @return array  privacy_policy_id => Bezeichnung (Snippets aufgelöst)
     */
    public function optionsFor(int $instanceId): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `privacy_policy_id`, `label` FROM `privacy_policy` WHERE `instance_id` IN (?, ?)"
                . " ORDER BY `standard` = '', `label`", 'ii', [self::ALL_INSTANCES, $instanceId]);
            $options = [];
            while ($row = $this->db->fetch()) {
                $options[(int)$row['privacy_policy_id']] = $this->cms->textOrSnippet((string)$row['label']);
            }
            return $options;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Darf der angemeldete Admin die Policy ändern (bzw. eine für diese Instanz
     * anlegen)? Siehe Klassenkommentar "Wer darf was".
     *
     * @param int $instanceId  Instanz der Policy, ALL_INSTANCES = alle
     */
    public function canEdit(int $instanceId): bool
    {
        $access = $this->container->get(Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return true;
        if ($instanceId === self::ALL_INSTANCES) return false;
        return $access->moduleRight('Privacy', 'policy_edit') && $access->instance($instanceId);
    }

    /**
     * Die Standard-Policies anlegen, wo sie fehlen (Migration 9.1.0). Direkt per
     * SQL: läuft auch ohne Backend und vor dem Aufbau der Laufzeitlisten.
     *
     * @return array  Bereichstyp => privacy_policy_id
     */
    public static function ensureStandard(Database $db): array
    {
        $ids = [];
        foreach (self::STANDARD as $type => $definition) {
            $db->query("SELECT `privacy_policy_id` FROM `privacy_policy` WHERE `standard` = ? ORDER BY `privacy_policy_id` LIMIT 1", 's', [$type]);
            $id = (int)($db->fetchNum()[0] ?? 0);
            if ($id < 1) {
                $db->query(
                    "INSERT INTO `privacy_policy` (`instance_id`, `label`, `standard`, `blocks`) VALUES (?, ?, ?, ?)",
                    'isss', [self::ALL_INSTANCES, $definition['label'], $type, (string)json_encode($definition['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                );
                $id = (int)$db->insert_id;
            }
            $ids[$type] = $id;
        }
        return $ids;
    }
}
