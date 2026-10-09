<?php
/**
 * Quelltexte der Frontend-Templates als Dateien: var/templates/ - die Ausgangsbasis.
 *
 *     var/templates/{instance_id}/{bereichsname}/{templatename}.cms.php
 *
 * Verzeichnis je Mandant (instance_id), darunter je Template-Bereich - mit dem NAMEN
 * des Bereichs, nicht seiner Id, damit sich das Verzeichnis im Editor lesen lässt.
 * Der Dateiinhalt ist der ungeparste Quelltext in CMS-Syntax.
 *
 * DIE DATEIEN FÜHREN
 * --------------------
 * Die Datei ist die einzige Quelle des Quelltexts. Die Spalte `template`.`code` ist
 * nur eine Kopie für die Suche im Backend, `styled_code` die eingefärbte Fassung für
 * TemplateBackend::info(). Aus der Datenbank gelangt NIE Code ins Dateisystem -
 * weder in var/templates noch als kompiliertes PHP nach var/php (Sicherheitsaspekt:
 * wer in die Datenbank schreiben kann, darf damit keinen PHP-Code ausführen können).
 *
 * Dateien -> Datenbank (sync(), minütlich über Core/cron und beim Refresh-Knopf
 * der Template-Listen):
 *
 *     neues Verzeichnis {bereich}    -> neuer Bereich vom Typ 'template' im Mandanten
 *     neue Datei {name}.cms.php      -> neuer Eintrag in `template`
 *     geänderte Datei                -> `code`/`styled_code`/`error_text` neu, PHP
 *                                       neu kompiliert (Template::save())
 *     Datei gelöscht                 -> Template gelöscht (samt Zuweisungen)
 *     Verzeichnis gelöscht           -> Bereich in den Papierkorb
 *
 * Backend -> Dateien: das Formular lädt den Quelltext aus der Datei
 * (Template::hookOnLoaded()) und schreibt ihn beim Speichern dorthin zurück
 * (Template::hookOnSaved()); Umbenennen, Verschieben und Löschen wirken ebenso auf
 * die Datei bzw. das Verzeichnis (Area-Hooks). Der Papierkorb eines Bereichs liegt
 * unter var/templates/.trash/{area_id} - beim Wiederherstellen wandert das
 * Verzeichnis von dort zurück, nichts wird aus der Datenbank neu geschrieben.
 *
 * Sicherung: fehlt das ganze Mandanten-Verzeichnis var/templates/{instance_id}
 * (z. B. Volume nicht eingebunden), löscht sync() für diesen Mandanten nichts.
 *
 * Namen: Verzeichnis- und Dateinamen müssen dem Flag 'name' genügen
 * (/^[a-z][a-z0-9_]+$/); alles andere wird übergangen. Mandanten-Verzeichnisse, zu
 * denen es keine Instanz gibt, ebenfalls.
 *
 * @see Camindo\Core\Modules\Template\Template   liest/schreibt die Datei je Template
 * @see Camindo\Core\Modules\Area\Area           legt das Verzeichnis je Bereich an/um
 * @see Camindo\Core\cron\TriggerCron            minütlicher Aufruf von sync()
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Config;
use Camindo\Core\System;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Area\Area;

class TemplateFiles {

    public const EXTENSION = '.cms.php';
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]+$/';
    private const LOCK_FILE = 'template_sync.lock';
    private const LOG = 'template-sync';
    // Papierkorb für Bereichsverzeichnisse (Soft-Delete); Punkt-Verzeichnisse
    // übergeht glob() in sync()
    private const TRASH_DIR = '.trash';

    public function __construct(
        private Config $config,
        private System $system,
        private Database $db,
        private Cms $cms,
        private InstanceCache $instanceCache,
        private Area $area,
        private Template $template,
    )
    {}

    // ====================
    // Pfade

    /** var/templates */
    public function baseDir(): string
    {
        return $this->config->get('paths.var_dir') . '/templates';
    }

    /** Verzeichnis eines Bereichs, relativ zu baseDir(): "{instance_id}/{name}" */
    public function areaPath(int $instanceId, string $areaName): string
    {
        return $instanceId . '/' . $areaName;
    }

    /** Datei eines Templates, relativ zu baseDir() */
    public function filePath(int $instanceId, string $areaName, string $name): string
    {
        return $this->areaPath($instanceId, $areaName) . '/' . $name . self::EXTENSION;
    }

    /** Papierkorb-Verzeichnis eines Bereichs, relativ zu baseDir() */
    private function trashPath(int $areaId): string
    {
        return self::TRASH_DIR . '/' . $areaId;
    }

    /**
     * instance_id und name eines Bereichs - für die Hooks, die nur die area_id kennen.
     *
     * @return array|null  ['instance_id' => int, 'name' => string]
     */
    public function areaInfo(int $areaId): ?array
    {
        // aus dem Instanz-Dateicache (Area::hookOnSaved() schreibt ihn vor diesem Aufruf)
        $info = $this->instanceCache->areaInfo($areaId);
        if ($info === null) return null;
        return ['instance_id' => $info['instance_id'], 'name' => $info['name']];
    }

    // ====================
    // Einzelne Datei / einzelnes Verzeichnis (Aufruf aus den Hooks)

    /**
     * Quelltext eines Templates aus der Datei lesen.
     *
     * @return string|null  null, wenn es die Datei (oder den Bereich) nicht gibt
     */
    public function readTemplate(int $areaId, string $name): ?string
    {
        if (!$info = $this->areaInfo($areaId)) return null;
        $file = $this->baseDir() . '/' . $this->filePath($info['instance_id'], $info['name'], $name);
        if (!is_file($file)) return null;
        $code = file_get_contents($file);
        return ($code === false) ? null : $code;
    }

    /**
     * Quelltext eines Templates schreiben (überschreibt eine vorhandene Datei).
     * Eine Datei mit identischem Inhalt bleibt unangetastet - der Abgleich schriebe
     * sonst bei jedem Lauf den Zeitstempel neu.
     */
    public function writeTemplate(int $areaId, string $name, string $code): void
    {
        if (!$info = $this->areaInfo($areaId)) return;
        $path = $this->filePath($info['instance_id'], $info['name'], $name);
        $file = $this->baseDir() . '/' . $path;
        if (is_file($file) && file_get_contents($file) === $code) return;
        $this->system->filePutContents($this->baseDir(), $path, $code, lock:true);
    }

    /**
     * Datei eines Templates entfernen (Umbenennen, Verschieben, Löschen).
     */
    public function removeTemplate(int $areaId, string $name): void
    {
        if (!$info = $this->areaInfo($areaId)) return;
        $file = $this->baseDir() . '/' . $this->filePath($info['instance_id'], $info['name'], $name);
        if (is_file($file)) unlink($file);
    }

    /**
     * Verzeichnis eines Bereichs anlegen.
     */
    public function createAreaDir(int $instanceId, string $areaName): void
    {
        $this->system->createDir($this->baseDir(), $this->areaPath($instanceId, $areaName));
    }

    /**
     * Verzeichnis eines Bereichs umbenennen bzw. in einen anderen Mandanten
     * verschieben. Fehlt das alte Verzeichnis, wird das neue angelegt.
     */
    public function moveAreaDir(int $oldInstanceId, string $oldName, int $instanceId, string $areaName): void
    {
        $base = $this->baseDir();
        $old = $base . '/' . $this->areaPath($oldInstanceId, $oldName);
        $new = $base . '/' . $this->areaPath($instanceId, $areaName);
        if ($old === $new) return;
        if (is_dir($old) && !is_dir($new)) {
            $this->system->createDir($base, (string)$instanceId);
            rename($old, $new);
            return;
        }
        $this->createAreaDir($instanceId, $areaName);
    }

    /**
     * Verzeichnis eines Bereichs samt Dateien entfernen (Bereich endgültig
     * gelöscht) - auch aus dem Papierkorb.
     */
    public function removeAreaDir(int $instanceId, string $areaName, int $areaId = 0): void
    {
        $this->removeDir($this->baseDir() . '/' . $this->areaPath($instanceId, $areaName));
        if ($areaId > 0) {
            $this->removeDir($this->baseDir() . '/' . $this->trashPath($areaId));
        }
    }

    /**
     * Verzeichnis eines Bereichs in den Papierkorb verschieben (Soft-Delete).
     * Aus var/templates verschwindet es damit; sync() legt zu dem Bereich also
     * weder etwas neu an noch löscht es etwas.
     */
    public function trashAreaDir(int $areaId, int $instanceId, string $areaName): void
    {
        $base = $this->baseDir();
        $dir = $base . '/' . $this->areaPath($instanceId, $areaName);
        if (!is_dir($dir)) return;
        $trash = $base . '/' . $this->trashPath($areaId);
        $this->removeDir($trash);
        $this->system->createDir($base, self::TRASH_DIR);
        rename($dir, $trash);
    }

    /**
     * Verzeichnis eines Bereichs aus dem Papierkorb zurückholen (Restore).
     * Ohne Papierkorb-Eintrag entsteht ein leeres Verzeichnis - die Templates des
     * Bereichs haben dann keine Datei mehr und sync() räumt sie ab.
     */
    public function restoreAreaDir(int $areaId, int $instanceId, string $areaName): void
    {
        $base = $this->baseDir();
        $dir = $base . '/' . $this->areaPath($instanceId, $areaName);
        $trash = $base . '/' . $this->trashPath($areaId);
        if (is_dir($trash) && !is_dir($dir)) {
            $this->system->createDir($base, (string)$instanceId);
            rename($trash, $dir);
            return;
        }
        $this->createAreaDir($instanceId, $areaName);
    }

    /** Verzeichnis mit seinen Dateien (eine Ebene) entfernen */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach ((array)glob($dir . '/*') as $file) {
            if (is_file($file)) unlink($file);
        }
        @rmdir($dir);
    }

    // ====================
    // Abgleich

    /**
     * Dateisystem -> Datenbank abgleichen (siehe Klassenkommentar).
     *
     *  1. Unbekannte Verzeichnisse werden Bereiche vom Typ 'template', unbekannte
     *     Dateien werden Templates (Label = Name), Dateien mit anderem Inhalt als
     *     `code` werden neu gespeichert (kompiliert).
     *  2. Templates ohne Datei werden gelöscht, Bereiche ohne Verzeichnis kommen in
     *     den Papierkorb - beides nur, wenn das Mandanten-Verzeichnis vorhanden ist.
     *
     * Läuft nicht, wenn ein anderer Abgleich noch läuft (Sperrdatei in var/tmp).
     *
     * @return array  areas_created, templates_created, templates_updated,
     *                templates_deleted, areas_deleted, errors (Meldungen),
     *                locked (true = übersprungen)
     */
    public function sync(): array
    {
        $result = [
            'areas_created' => 0, 'templates_created' => 0, 'templates_updated' => 0,
            'templates_deleted' => 0, 'areas_deleted' => 0, 'errors' => [], 'locked' => false,
        ];

        $lockFile = $this->config->get('paths.tmp_dir') . '/' . self::LOCK_FILE;
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $result['locked'] = true;
            if ($lock !== false) fclose($lock);
            return $result;
        }

        try {
            $base = $this->baseDir();
            $this->system->createDir(dirname($base), basename($base));
            $instanceIds = array_map('intval', (array)($this->instanceCache->all()['instance_ids'] ?? []));

            // Bereiche und Templates aus der Datenbank
            // --------------------
            $this->db->pushResult();
            $this->db->query("SELECT `area_id`, `instance_id`, `name` FROM `area` WHERE `type` = 'template' AND `deleted` = 0");
            $areas = [];   // "{instance_id}/{name}" => area_id
            foreach ($this->db->fetchAll() as $row) {
                $areas[$this->areaPath((int)$row['instance_id'], (string)$row['name'])] = (int)$row['area_id'];
            }
            $this->db->query(
                "SELECT `t`.`template_id`, `t`.`area_id`, `t`.`name`, `t`.`code` FROM `template` `t`"
                . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id`"
                . " WHERE `a`.`type` = 'template' AND `a`.`deleted` = 0"
            );
            $templates = [];   // area_id => [name => [template_id, code]]
            foreach ($this->db->fetchAll() as $row) {
                $templates[(int)$row['area_id']][(string)$row['name']] = [
                    'template_id' => (int)$row['template_id'],
                    'code' => (string)$row['code'],
                ];
            }
            $this->db->popResult();

            // 1. Dateien -> Datenbank
            // --------------------
            foreach ((array)glob($base . '/*', GLOB_ONLYDIR) as $instanceDir) {
                $instanceId = (int)basename($instanceDir);
                if (!ctype_digit(basename($instanceDir)) || !in_array($instanceId, $instanceIds, true)) continue;

                foreach ((array)glob($instanceDir . '/*', GLOB_ONLYDIR) as $areaDir) {
                    $areaName = basename($areaDir);
                    if (!preg_match(self::NAME_PATTERN, $areaName)) continue;
                    $path = $this->areaPath($instanceId, $areaName);

                    $areaId = $areas[$path] ?? 0;
                    if ($areaId < 1) {
                        $areaId = $this->createArea($instanceId, $areaName, $result['errors']);
                        if ($areaId < 1) continue;
                        $areas[$path] = $areaId;
                        $templates[$areaId] = [];
                        $result['areas_created']++;
                    }

                    foreach ((array)glob($areaDir . '/*' . self::EXTENSION) as $file) {
                        $name = substr(basename($file), 0, -strlen(self::EXTENSION));
                        if (!preg_match(self::NAME_PATTERN, $name)) continue;
                        $code = file_get_contents($file);
                        if ($code === false) continue;

                        if (!isset($templates[$areaId][$name])) {
                            // neue Datei -> neues Template
                            $templateId = $this->createTemplate($areaId, $name, $code, $result['errors']);
                            if ($templateId > 0) {
                                $templates[$areaId][$name] = ['template_id' => $templateId, 'code' => $code];
                                $result['templates_created']++;
                            }
                        }
                        elseif ($templates[$areaId][$name]['code'] !== $code) {
                            // geänderte Datei -> Template neu speichern (kompilieren)
                            $templateId = $templates[$areaId][$name]['template_id'];
                            if ($this->updateTemplate($templateId, $areaId, $name, $code, $result['errors'])) {
                                $templates[$areaId][$name]['code'] = $code;
                                $result['templates_updated']++;
                            }
                        }
                    }
                }
            }

            // 2. Datenbank aufräumen: was keine Datei mehr hat, verschwindet
            // --------------------
            foreach ($areas as $path => $areaId) {
                $instanceId = (int)strtok($path, '/');
                // Sicherung: ohne Mandanten-Verzeichnis nichts löschen
                if (!is_dir($base . '/' . $instanceId)) continue;

                if (!is_dir($base . '/' . $path)) {
                    // Verzeichnis gelöscht -> Bereich in den Papierkorb
                    if ($this->area->delete($areaId)) {
                        $result['areas_deleted']++;
                    }
                    else {
                        $result['errors'][] = "area {$path}: " . $this->cms->errorMsgLast();
                        $this->cms->errorsReset();
                    }
                    continue;
                }
                foreach ($templates[$areaId] ?? [] as $name => $row) {
                    if (is_file($base . '/' . $path . '/' . $name . self::EXTENSION)) continue;
                    // Datei gelöscht -> Template löschen
                    if ($this->template->delete($row['template_id'])) {
                        $result['templates_deleted']++;
                    }
                    else {
                        $result['errors'][] = "template {$path}/{$name}: " . $this->cms->errorMsgLast();
                        $this->cms->errorsReset();
                    }
                }
            }
        }
        finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        foreach ($result['errors'] as $error) {
            $this->system->log(self::LOG, $error);
        }
        return $result;
    }

    /**
     * Bereich vom Typ 'template' zu einem manuell angelegten Verzeichnis.
     *
     * @return int  area_id, 0 bei Fehler (Meldung in $errors)
     */
    private function createArea(int $instanceId, string $name, array &$errors): int
    {
        $data = $this->area->init();
        $data['area_id'] = 0;
        $data['instance_id'] = $instanceId;
        $data['type'] = 'template';
        $data['name'] = $name;
        $data['label'] = $name;
        $data['icon'] = Area::DEFAULT_ICONS['template'];
        $data['parent_id'] = 0;
        $data['default_field_id'] = 0;
        $data['sort_field_id'] = 0;
        $data['default_template_id'] = 0;
        $areaId = (int)$this->area->save([], $data);
        if ($areaId < 1) {
            $errors[] = "area {$instanceId}/{$name}: " . $this->cms->errorMsgLast();
            $this->cms->errorsReset();
        }
        return $areaId;
    }

    /**
     * Template zu einer manuell angelegten Datei.
     *
     * @return int  template_id, 0 bei Fehler (Meldung in $errors)
     */
    private function createTemplate(int $areaId, string $name, string $code, array &$errors): int
    {
        $data = [
            'template_id' => 0,
            'area_id' => $areaId,
            'name' => $name,
            'label' => '',
            'code' => $code,
            'type' => '',
            'standalone' => 0,
        ];
        $templateId = (int)$this->template->save([], $data);
        if ($templateId > 0) return $templateId;
        $errors[] = "template {$areaId}/{$name}: " . $this->cms->errorMsgLast();
        $this->cms->errorsReset();
        return 0;
    }

    /**
     * Template zu einer geänderten Datei neu speichern: Template::hookOnSave()
     * kompiliert und befüllt `code`/`styled_code`/`error_text`, hookOnSaved()
     * schreibt das PHP nach var/php.
     */
    private function updateTemplate(int $templateId, int $areaId, string $name, string $code, array &$errors): bool
    {
        $data = [
            'template_id' => $templateId,
            'area_id' => $areaId,
            'name' => $name,
            'code' => $code,
        ];
        if ($this->template->save(['code', 'styled_code', 'error_text'], $data) !== null) return true;
        $errors[] = "template {$areaId}/{$name}: " . $this->cms->errorMsgLast();
        $this->cms->errorsReset();
        return false;
    }
}
