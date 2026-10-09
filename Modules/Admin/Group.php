<?php
/**
 * Dataset-Kindklasse für die Tabelle `group` - die Benutzergruppen der Backend-Admins
 * und ihre Rechte (docs/developers/12-group-rights.md).
 *
 * WAS EINE GRUPPE FESTLEGT
 * --------------------
 *     details.modules      Modulname => gewährte Zusatzrechte (JSON in `details`):
 *                          {"Record": ["import"], "Tools": []} - Schlüssel vorhanden =
 *                          Zugriff auf das Modul; Zusatzrechte sind die Namen aus
 *                          {Modul}/install/settings.php 'access'
 *     details.interfaces   Oberflächen, über die sich Admins der Gruppe anmelden
 *                          dürfen: backend, cli, php (Access::INTERFACES); ohne
 *                          Angabe nur das Backend
 *     instance_ids         `rel_group_instance` - Instanzen, deren Bereiche die Gruppe
 *                          im Backend sieht (Feld vom Typ 'reltable')
 *     languages            `rel_group_language` - language_id => 1 (lesen) | 2 (lesen
 *                          und schreiben); Sprachen ohne Eintrag sind unsichtbar
 *                          ('reltable', instanzübergreifend)
 *     statuses             `rel_group_status` - status_ids, die die Gruppe einem
 *                          Datensatz geben darf (flacher Workflow); lesen darf jede
 *                          Gruppe alle Status. Eine neue Gruppe darf alle setzen.
 *                          Gepflegt auch im Modul Status (Feld group_ids)
 *     (Bereichsrechte)     `rel_group_area` - je Bereich Lesen und die Flags; gepflegt
 *                          über saveAreaRights() (GroupBackend::access(): alle
 *                          Bereiche einer Gruppe) und im Modul Area (Reiter "Zugriff":
 *                          alle Gruppen eines Bereichs); bei Instanzzuweisung
 *                          vorbelegt und abgeräumt, s. u.
 *
 * INSTANZ ZUWEISEN = ALLE BEREICHE FREIGEBEN
 * --------------------
 * Bekommt eine Gruppe eine Instanz neu, legt hookOnSaved() für jeden Bereich der
 * Instanz (außer Ordnern) eine Zeile in `rel_group_area` mit allen Rechten außer
 * set_owner an, und für jede Sprache der Instanz - soweit noch keine Zeile besteht -
 * das Leserecht. Der Superuser nimmt danach im Bereich weg, was die Gruppe nicht
 * darf. Wird eine Instanz entzogen, fallen die Bereichszeilen der Instanz weg;
 * Sprachzeilen bleiben (sie gelten instanzübergreifend).
 *
 * DIE SUPERUSER-GRUPPE
 * --------------------
 * group_id 1 (InstanceCache::SUPERUSER_GROUP_ID) darf alles, ohne dass dafür Zeilen
 * geführt werden (Camindo\Core\Access). Sie lässt sich weder ändern noch löschen;
 * hookOnSave()/hookOnDelete() weisen das ab. Gelöscht werden kann außerdem keine
 * Gruppe, der noch Admins angehören (der Fremdschlüssel admin.group_id ist RESTRICT,
 * die Meldung hier ist die lesbare Fassung davon).
 *
 * SIGNATUREN (Integrity)
 * --------------------
 * `group`.`sig` deckt die details (Module), jede Zeile in rel_group_instance,
 * rel_group_area, rel_group_language und rel_group_status trägt ihre eigene Signatur. Alle Schreibpfade
 * hier und in Area (saveAccess(), grantGroups(), copyAccess()) schreiben sie mit;
 * InstanceCache::readGroups() lässt Zeilen ohne gültige Signatur weg - eine per SQL
 * eingetragene Berechtigung landet damit nie in den Gruppendateien.
 *
 * CACHE UND SESSIONS
 * --------------------
 * Jede Änderung schreibt die Gruppendateien var/cache/group-{id}.php neu
 * (InstanceCache::rebuild()) und die Session-Details der Admins der Gruppe nach
 * (Session::refreshDetails()) - ein angemeldeter Admin arbeitet ab seinem nächsten
 * Request mit den neuen Rechten.
 *
 * @see Camindo\Core\Modules\Admin\GroupBackend
 * @see Camindo\Core\Access
 * @see Camindo\Core\InstanceCache   Gruppendateien
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Admin;

use Camindo\Core\Access;
use Camindo\Core\AuditLog;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;

class Group extends Dataset {

    /** Werte des Feldes 'languages' je Sprache */
    public const LANGUAGE_NONE  = 0;
    public const LANGUAGE_READ  = 1;
    public const LANGUAGE_WRITE = 2;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'group';
        $this->moduleName = 'Admin';
        $this->defaultSortOrder = 'label ASC';
        $this->schema = [
            'group_id' => [
                'type'  => 'integer',
                'label' => 'group_id',
                'min' => 1
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#admin.field_group_label',
                'max'   => 64,
                'flags' => ['trim', 'not_empty', 'unique'],
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
            ],
            // Modulrechte und Oberflächen - siehe Klassenkommentar. Das Formular
            // schickt sie als data[modules][{Modul}][access],
            // data[modules][{Modul}][rights][] und data[interfaces][]; hookOnSave()
            // macht daraus details.modules und details.interfaces.
            'details' => [
                'type'  => 'json',
                'label' => '#admin.field_group_details',
            ],
            // Tabelle `rel_group_instance`
            'instance_ids' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_instance_ids',
                'tooltip' => '#admin.tooltip_group_instance_ids',
            ],
            // Tabelle `rel_group_language`: language_id => LANGUAGE_*
            'languages' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_languages',
                'tooltip' => '#admin.tooltip_group_languages',
            ],
            // Tabelle `rel_group_status`: setzbare status_ids
            'statuses' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#admin.field_group_statuses',
                'tooltip' => '#admin.tooltip_group_statuses',
            ],
        ];
    }

    protected function hookOnCreate(array &$data): void
    {
        $data['details'] = ['modules' => [], 'interfaces' => Access::DEFAULT_INTERFACES];
        $data['instance_ids'] = [];
        $data['languages'] = [];
        // Voreinstellung: alle Status setzbar
        $this->db->query("SELECT `status_id` FROM `status` ORDER BY `position` ASC");
        $data['statuses'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * Nach dem Laden: die Relation-Tabellen nachreichen.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if (!is_array($data['details'] ?? null)) $data['details'] = [];
        $data['details']['modules'] = (array)($data['details']['modules'] ?? []);
        $data['details']['interfaces'] = Access::normalizeInterfaces(
            isset($data['details']['interfaces']) ? (array)$data['details']['interfaces'] : null
        );

        $this->db->query("SELECT `instance_id` FROM `rel_group_instance` WHERE `group_id` = ?", 'i', [$groupId]);
        $data['instance_ids'] = array_map('intval', $this->db->fetchColumn());

        $data['languages'] = [];
        $this->db->query("SELECT `language_id`, `can_write` FROM `rel_group_language` WHERE `group_id` = ?", 'i', [$groupId]);
        while ($row = $this->db->fetch()) {
            $data['languages'][(int)$row['language_id']] = !empty($row['can_write']) ? self::LANGUAGE_WRITE : self::LANGUAGE_READ;
        }

        $this->db->query("SELECT `status_id` FROM `rel_group_status` WHERE `group_id` = ?", 'i', [$groupId]);
        $data['statuses'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * Vor dem Speichern: Superuser-Gruppe abweisen, Eingaben normalisieren.
     *
     *     modules       aus dem Formular (data[modules]) oder bereits als
     *                   details.modules - nur bekannte Module, nur definierte Rechte
     *     instance_ids  nur vorhandene Instanzen
     *     languages     language_id => LANGUAGE_*, nur vorhandene Sprachen
     *     statuses      nur vorhandene Status
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        // Module und Oberflächen
        // --------------------
        if (in_array('details', $fields, true) || array_key_exists('modules', $data) || array_key_exists('interfaces', $data)) {
            $details = is_array($data['details'] ?? null) ? $data['details'] : [];
            if (array_key_exists('modules', $data)) {
                $details['modules'] = $this->modulesFromForm((array)$data['modules']);
            }
            $details['modules'] = $this->normalizeModules((array)($details['modules'] ?? []));
            if (array_key_exists('interfaces', $data)) {
                $details['interfaces'] = (array)$data['interfaces'];
            }
            // bei einem Speichern ohne Angabe: die bisherigen (sonst DEFAULT_INTERFACES)
            if (!isset($details['interfaces']) && isset($previousData['details']['interfaces'])) {
                $details['interfaces'] = (array)$previousData['details']['interfaces'];
            }
            $details['interfaces'] = Access::normalizeInterfaces(isset($details['interfaces']) ? (array)$details['interfaces'] : null);
            $data['details'] = $details;
            if (!in_array('details', $fields, true)) $fields[] = 'details';
        }

        // Instanzen
        // --------------------
        if (in_array('instance_ids', $fields, true)) {
            $ids = $this->system->sanitizeIds((array)($data['instance_ids'] ?? []), true);
            $data['instance_ids'] = $this->existingIds('instance', $ids);
        }

        // Sprachen
        // --------------------
        // LANGUAGE_NONE bleibt im Array stehen: hookOnSaved() weiß dann, dass die
        // Sprache ausdrücklich abgewählt wurde, und belegt sie bei einer neu
        // zugewiesenen Instanz nicht mit Leserecht vor.
        if (in_array('languages', $fields, true)) {
            $languages = [];
            // Je Sprache eine Stufe oder - aus dem Formular (Hiddenfeld 0 + Checkboxes
            // "lesen" 1 / "schreiben" 2) - eine Liste davon: es gilt die höchste
            foreach ((array)($data['languages'] ?? []) as $languageId => $level) {
                $level = is_array($level) ? max(array_map('intval', $level ?: [0])) : (int)$level;
                $languages[(int)$languageId] = max(self::LANGUAGE_NONE, min($level, self::LANGUAGE_WRITE));
            }
            // language_id 0 ist eine gültige Sprache - deshalb kein sanitizeIds()
            $existing = $this->existingIds('language', array_keys($languages));
            $data['languages'] = array_intersect_key($languages, array_flip($existing));
        }

        // Status
        // --------------------
        // status_id 0 ist ein gültiger Status - deshalb kein sanitizeIds()
        if (in_array('statuses', $fields, true)) {
            $data['statuses'] = $this->existingIds('status', array_map('intval', (array)($data['statuses'] ?? [])));
        }
    }

    /**
     * Nach dem Speichern: Relation-Tabellen schreiben, Bereichs- und Sprachrechte
     * für neu zugewiesene Instanzen vorbelegen, Cache und Sessions erneuern.
     * Läuft in der Transaktion von Dataset::save().
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $groupId = (int)($data['group_id'] ?? 0);

        // Sprachen aus dem Formular (vor der Vorbelegung neuer Instanzen)
        // --------------------
        $submittedLanguageIds = [];
        if (in_array('languages', $fields, true)) {
            $submittedLanguageIds = array_map('intval', array_keys((array)($data['languages'] ?? [])));
            $this->db->query("DELETE FROM `rel_group_language` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ((array)($data['languages'] ?? []) as $languageId => $level) {
                if ((int)$level < self::LANGUAGE_READ) continue;
                $canWrite = ((int)$level >= self::LANGUAGE_WRITE);
                $this->db->query(
                    "INSERT INTO `rel_group_language` (`group_id`, `language_id`, `can_write`, `sig`) VALUES (?, ?, ?, ?)",
                    'iiis', [$groupId, (int)$languageId, (int)$canWrite, $this->integrity()->languageRightSignature($groupId, (int)$languageId, $canWrite)]
                );
            }
        }

        // Status
        // --------------------
        if (in_array('statuses', $fields, true)) {
            $this->db->query("DELETE FROM `rel_group_status` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ((array)($data['statuses'] ?? []) as $statusId) {
                $this->db->query(
                    "INSERT INTO `rel_group_status` (`group_id`, `status_id`, `sig`) VALUES (?, ?, ?)",
                    'iis', [$groupId, (int)$statusId, $this->integrity()->statusRightSignature($groupId, (int)$statusId)]
                );
            }
        }

        // Instanzen
        // --------------------
        if (in_array('instance_ids', $fields, true)) {
            $newIds = array_map('intval', (array)($data['instance_ids'] ?? []));
            $oldIds = array_map('intval', (array)($previousData['instance_ids'] ?? []));

            $this->db->query("DELETE FROM `rel_group_instance` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ($newIds as $instanceId) {
                $this->db->query(
                    "INSERT INTO `rel_group_instance` (`group_id`, `instance_id`, `sig`) VALUES (?, ?, ?)",
                    'iis', [$groupId, $instanceId, $this->integrity()->instanceRightSignature($groupId, $instanceId)]
                );
            }
            foreach (array_diff($newIds, $oldIds) as $instanceId) {
                $this->grantInstance($groupId, $instanceId, $submittedLanguageIds);
            }
            foreach (array_diff($oldIds, $newIds) as $instanceId) {
                $this->revokeInstance($groupId, $instanceId);
            }
        }

        // Signatur der Gruppen-Zeile (details = Module) - Integrity
        $this->integrity()->signGroup($groupId);

        $this->afterChange($groupId);
    }

    /**
     * Vor dem Löschen: Superuser-Gruppe und Gruppen mit (aktiven) Benutzern bleiben.
     * Gelöschte Benutzer verlieren die Zuordnung (group_id NULL) - sie lassen sich
     * nicht wiederherstellen, ihre Gruppe ist nur noch Historie, und admin.group_id
     * verweist mit ON DELETE RESTRICT auf die Gruppe. Neu signiert, mit Eintrag im
     * Audit-Log des Kontos. Die Relation-Tabellen räumen die Fremdschlüssel ab
     * (ON DELETE CASCADE).
     */
    protected function hookOnDelete(array $data): void
    {
        $groupId = (int)($data['group_id'] ?? 0);
        if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        if ($this->adminCount($groupId) > 0) {
            $errorMsg = $this->cms->snippet('admin.error_group_has_admins');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }
        $this->db->pushResult();
        try {
            $this->db->query("SELECT `admin_id`, `name` FROM `admin` WHERE `group_id` = ? AND `deleted` = 1", 'i', [$groupId]);
            $deleted = $this->db->fetchAll();
            $auditLog = $this->container->get(AuditLog::class);
            foreach ($deleted as $row) {
                $adminId = (int)$row['admin_id'];
                $this->db->query("UPDATE `admin` SET `group_id` = NULL WHERE `admin_id` = ?", 'i', [$adminId]);
                $this->integrity()->signAdmin($adminId);
                $auditLog->write('update', 'admin', $adminId, (string)$row['name'], [[
                    'k' => 'group_id', 'l' => '#admin.field_admin_group_id', 't' => 'integer', 'g' => '',
                    'b' => (string)$groupId, 'a' => '',
                ]]);
            }
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Gruppe löschen und ihre (aktiven) Benutzer vorher einer Ersatzgruppe zuweisen;
     * alles in einer Transaktion. Gelöschte Benutzer verlieren die Zuordnung
     * (hookOnDelete()). Jede Zuweisung läuft über
     * Admin::save() (Signatur, Audit-Log). Die Superuser-Gruppe ist als Ersatz
     * ausgeschlossen - das Löschen einer Gruppe soll niemanden zum Superuser machen.
     *
     * @param int $replacementId  Ersatzgruppe; 0 = keine (nur ohne Benutzer möglich)
     */
    public function deleteReplacing(int $groupId, int $replacementId): bool
    {
        return (bool)$this->transactional(function () use ($groupId, $replacementId) {
            $this->db->query("SELECT `admin_id` FROM `admin` WHERE `group_id` = ? AND `deleted` = 0", 'i', [$groupId]);
            $adminIds = array_map('intval', $this->db->fetchColumn());
            if ($adminIds) {
                if ($replacementId <= InstanceCache::SUPERUSER_GROUP_ID || $replacementId === $groupId || !$this->exists($replacementId)) {
                    $errorMsg = $this->cms->snippet('admin.error_group_replacement');
                    $this->cms->error($errorMsg);
                    throw new \Exception('#' . $errorMsg);
                }
                $admin = $this->container->get(Admin::class);
                foreach ($adminIds as $adminId) {
                    $data = ['admin_id' => $adminId, 'group_id' => $replacementId];
                    if ($admin->save(['group_id'], $data) === null) {
                        throw new \Exception('#' . $this->cms->errorMsgLast());
                    }
                }
            }
            if (!$this->delete($groupId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
            return true;
        }, false);
    }

    /** Gibt es die Gruppe? */
    private function exists(int $groupId): bool
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT 1 FROM `group` WHERE `group_id` = ?", 'i', [$groupId]);
            return $this->db->num_rows > 0;
        }
        finally {
            $this->db->popResult();
        }
    }

    /**
     * Nach dem Löschen: Gruppendatei entfernen (rebuild() räumt sie ab).
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
    }

    // ====================
    // Bereichsrechte (rel_group_area) - Vorbelegung und Abräumen

    /**
     * Alle Bereiche einer Instanz (außer Ordnern) mit vollen Rechten (ohne
     * set_owner) und ihre Sprachen mit Leserecht freigeben - soweit noch keine
     * Zeile besteht. Sprachen, die das Formular ausdrücklich abgewählt hat
     * ($skipLanguageIds), bleiben außen vor.
     */
    public function grantInstance(int $groupId, int $instanceId, array $skipLanguageIds = []): void
    {
        if ($groupId <= InstanceCache::SUPERUSER_GROUP_ID || $instanceId < 1) return;
        // Zeile für Zeile: jede braucht ihre Signatur (Integrity). Die Flags sind
        // die Spalten-Defaults (alles außer set_owner).
        $this->db->pushResult();
        $this->db->query("SELECT `area_id` FROM `area` WHERE `instance_id` = ? AND `type` <> 'folder'", 'i', [$instanceId]);
        $areaIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        foreach ($areaIds as $areaId) {
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_area` (`group_id`, `area_id`, `sig`) VALUES (?, ?, ?)",
                'iis', [$groupId, $areaId, $this->integrity()->areaRightSignature($groupId, $areaId, [])]
            );
        }
        foreach ($this->instanceCache()->languageIds($instanceId) as $languageId) {
            if (in_array($languageId, $skipLanguageIds, true)) continue;
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_language` (`group_id`, `language_id`, `can_write`, `sig`) VALUES (?, ?, 0, ?)",
                'iis', [$groupId, $languageId, $this->integrity()->languageRightSignature($groupId, $languageId, false)]
            );
        }
    }

    /**
     * Bereichsrechte einer entzogenen Instanz entfernen.
     */
    public function revokeInstance(int $groupId, int $instanceId): void
    {
        if ($groupId < 1 || $instanceId < 1) return;
        $this->db->query(
            "DELETE `r` FROM `rel_group_area` `r`"
            . " INNER JOIN `area` `a` ON `a`.`area_id` = `r`.`area_id`"
            . " WHERE `r`.`group_id` = ? AND `a`.`instance_id` = ?",
            'ii', [$groupId, $instanceId]
        );
    }

    /**
     * Bereichsrechte einer Gruppe schreiben (GroupBackend::access()).
     *
     * @param int   $groupId
     * @param array $rows  area_id => ['read' => 1, 'group_write' => 1, ...] aus dem
     *                     Formular; ohne 'read' fällt die Zeile weg. Nur Bereiche
     *                     (keine Ordner) zugewiesener Instanzen; Template-Bereiche
     *                     behalten nur group_write.
     * @return bool  false: Meldung in Cms::errors
     */
    public function saveAreaRights(int $groupId, array $rows): bool
    {
        if ($groupId <= InstanceCache::SUPERUSER_GROUP_ID) {
            $errorMsg = $this->cms->snippet('admin.error_group_superuser');
            $this->cms->error($errorMsg);
            return false;
        }
        $instanceIds = array_map('intval', (array)($this->instanceCache()->group($groupId)['instance_ids'] ?? []));
        $areas = $this->instanceCache()->areasOfType([], $instanceIds);

        return (bool)$this->transactional(function () use ($groupId, $rows, $areas) {
            $this->db->query("DELETE FROM `rel_group_area` WHERE `group_id` = ?", 'i', [$groupId]);
            foreach ($rows as $areaId => $flags) {
                $areaId = (int)$areaId;
                $flags = (array)$flags;
                if (!isset($areas[$areaId]) || $areas[$areaId]['type'] === 'folder') continue;
                if (empty($flags['read'])) continue;
                $isTemplate = ($areas[$areaId]['type'] === 'template');
                $row = [
                    'group_write'  => (int)!empty($flags['group_write']),
                    'group_delete' => $isTemplate ? 0 : (int)!empty($flags['group_delete']),
                    'other_write'  => $isTemplate ? 0 : (int)!empty($flags['other_write']),
                    'other_delete' => $isTemplate ? 0 : (int)!empty($flags['other_delete']),
                    'set_owner'    => $isTemplate ? 0 : (int)!empty($flags['set_owner']),
                ];
                $this->db->query(
                    "INSERT INTO `rel_group_area` (`group_id`, `area_id`, `group_write`, `group_delete`, `other_write`, `other_delete`, `set_owner`, `sig`)"
                    . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'iiiiiiis',
                    array_merge([$groupId, $areaId], array_values($row), [$this->integrity()->areaRightSignature($groupId, $areaId, $row)])
                );
            }
            $this->afterChange($groupId);
            return true;
        }, false);
    }

    /**
     * Bereichsrechte einer Gruppe: area_id => Flags (Zeile = Leserecht).
     * Anzeige und Formular in GroupBackend; auch für den Reiter "Zugriff" im Modul Area.
     *
     * @return array  area_id => ['group_write', 'group_delete', 'other_write',
     *                'other_delete', 'set_owner'] (int 0|1)
     */
    public function areaRights(int $groupId): array
    {
        $rights = [];
        if ($groupId < 1) return $rights;
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `group_id` = ?", 'i', [$groupId]);
        while ($row = $this->db->fetch()) {
            $rights[(int)$row['area_id']] = [
                'group_write'  => (int)!empty($row['group_write']),
                'group_delete' => (int)!empty($row['group_delete']),
                'other_write'  => (int)!empty($row['other_write']),
                'other_delete' => (int)!empty($row['other_delete']),
                'set_owner'    => (int)!empty($row['set_owner']),
            ];
        }
        $this->db->popResult();
        return $rights;
    }

    /**
     * Cache und Sessions nach einer Änderung an einer Gruppe oder ihren Rechten
     * erneuern. Auch für das Modul Area (Reiter "Zugriff") gedacht.
     */
    public function afterChange(int $groupId): void
    {
        $this->instanceCache()->rebuild();
        if ($groupId < 1) return;
        $this->db->pushResult();
        $this->db->query("SELECT `admin_id` FROM `admin` WHERE `group_id` = ?", 'i', [$groupId]);
        $adminIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        foreach ($adminIds as $adminId) {
            $this->session->refreshDetails($adminId);
        }
    }

    // ====================
    // Hilfsfunktionen

    /**
     * Anzahl der Benutzer einer Gruppe - ohne die gelöschten, mit $withDeleted alle.
     */
    public function adminCount(int $groupId, bool $withDeleted = false): int
    {
        $this->db->pushResult();
        $this->db->query("SELECT COUNT(*) FROM `admin` WHERE `group_id` = ?" . ($withDeleted ? '' : " AND `deleted` = 0"), 'i', [$groupId]);
        $count = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $count;
    }

    /**
     * Module, die eine Gruppe wählen kann: Name => Zusatzrechte (Name => Snippet).
     * Nur gelistete Module (Start und Messaging stehen jedem offen).
     *
     * @return array  Modulname => ['label' => Snippet-Verweis, 'access' => [recht => Snippet]]
     */
    public function availableModules(): array
    {
        $modules = [];
        foreach ($this->cms->modules as $name => $settings) {
            if (empty($settings['listed'])) continue;
            $access = [];
            foreach ((array)($settings['access'] ?? []) as $right => $snippet) {
                if (!preg_match('/^[a-z0-9_]+$/', (string)$right)) continue;
                $access[(string)$right] = (string)$snippet;
            }
            $modules[(string)$name] = [
                'label'  => (string)($settings['label'] ?? $name),
                'icon'  => (string)($settings['icon'] ?? ''),
                'access' => $access,
            ];
        }
        return $modules;
    }

    /**
     * Formulareingabe data[modules] -> details.modules.
     *
     *     data[modules][{Modul}][access] = 1        Zugriff
     *     data[modules][{Modul}][rights][] = recht  Zusatzrechte
     */
    private function modulesFromForm(array $input): array
    {
        $modules = [];
        foreach ($input as $name => $entry) {
            $entry = (array)$entry;
            if (empty($entry['access'])) continue;
            $modules[(string)$name] = array_map('strval', (array)($entry['rights'] ?? []));
        }
        return $modules;
    }

    /**
     * Nur bekannte Module und dort definierte Zusatzrechte übrig lassen.
     */
    private function normalizeModules(array $modules): array
    {
        $available = $this->availableModules();
        $clean = [];
        foreach ($modules as $name => $rights) {
            $name = (string)$name;
            if (!isset($available[$name])) continue;
            $allowed = array_keys($available[$name]['access']);
            $clean[$name] = array_values(array_intersect($allowed, array_map('strval', (array)$rights)));
        }
        return $clean;
    }

    /**
     * Aus einer Id-Liste die Ids heraussuchen, die es in $tableName gibt
     * (Reihenfolge wie übergeben). $tableName steht fest im Code.
     */
    private function existingIds(string $tableName, array $ids): array
    {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $idField = $tableName . '_id';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->pushResult();
        $this->db->query("SELECT `{$idField}` FROM `{$tableName}` WHERE `{$idField}` IN ($placeholders)", str_repeat('i', count($ids)), $ids);
        $found = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();
        return array_values(array_filter($ids, fn($id) => in_array($id, $found, true)));
    }

    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    private function integrity(): Integrity
    {
        return $this->container->get(Integrity::class);
    }
}
