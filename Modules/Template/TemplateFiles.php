<?php
/**
 * Source code of the frontend templates as files: var/templates/ - the starting point.
 *
 *     var/templates/{instance_id}/{bereichsname}/{templatename}.cms.php
 *
 * Directory per tenant (instance_id), below it per template area - with the NAME
 * of the area, not its id, so that the directory can be read in the editor.
 * The file content is the unparsed source code in CMS syntax.
 *
 * THE FILES LEAD
 * --------------------
 * The file is the only source of the source code. The column `template`.`code` is
 * only a copy for searching in the backend, `styled_code` the colored version for
 * TemplateBackend::info(). Code NEVER gets from the database into the file system -
 * neither into var/templates nor as compiled PHP into var/php (security aspect:
 * whoever can write to the database must not be able to run PHP code with it).
 *
 * Files -> database (sync(), every minute via Core/cron and with the refresh button
 * of the template lists):
 *
 *     new directory {bereich}        -> new area of type 'template' in the tenant
 *     new file {name}.cms.php        -> new entry in `template`
 *     changed file                   -> `code`/`styled_code`/`error_text` anew, PHP
 *                                       newly compiled (Template::save())
 *     file deleted                   -> template deleted (including assignments)
 *     directory deleted              -> area to the trash
 *
 * Backend -> files: the form loads the source code from the file
 * (Template::hookOnLoaded()) and writes it back there on saving
 * (Template::hookOnSaved()); renaming, moving and deleting likewise act on
 * the file resp. the directory (area hooks). The trash of an area lies
 * under var/templates/.trash/{area_id} - when restoring, the
 * directory moves back from there, nothing is rewritten from the database.
 *
 * Safeguard: if the whole tenant directory var/templates/{instance_id} is missing
 * (e.g. volume not mounted), sync() deletes nothing for this tenant.
 *
 * Names: directory and file names must match the flag 'name'
 * (/^[a-z][a-z0-9_]+$/); everything else is skipped. Tenant directories for
 * which there is no instance likewise.
 *
 * @see Camindo\Core\Modules\Template\Template   reads/writes the file per template
 * @see Camindo\Core\Modules\Area\Area           creates/renames the directory per area
 * @see Camindo\Core\cron\TriggerCron            per-minute call of sync()
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
    // Trash for area directories (soft delete); dot directories
    // are skipped by glob() in sync()
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

    /** Directory of an area, relative to baseDir(): "{instance_id}/{name}" */
    public function areaPath(int $instanceId, string $areaName): string
    {
        return $instanceId . '/' . $areaName;
    }

    /** File of a template, relative to baseDir() */
    public function filePath(int $instanceId, string $areaName, string $name): string
    {
        return $this->areaPath($instanceId, $areaName) . '/' . $name . self::EXTENSION;
    }

    /** Trash directory of an area, relative to baseDir() */
    private function trashPath(int $areaId): string
    {
        return self::TRASH_DIR . '/' . $areaId;
    }

    /**
     * instance_id and name of an area - for the hooks that only know the area_id.
     *
     * @return array|null  ['instance_id' => int, 'name' => string]
     */
    public function areaInfo(int $areaId): ?array
    {
        // from the instance file cache (Area::hookOnSaved() writes it before this call)
        $info = $this->instanceCache->areaInfo($areaId);
        if ($info === null) return null;
        return ['instance_id' => $info['instance_id'], 'name' => $info['name']];
    }

    /**
     * Directory of an area relative to baseDir(), as it lies on disk right now:
     * "{instance_id}/{name}", for an area in the trash ".trash/{area_id}".
     * The name of a deleted area may already belong to a new area of the same
     * tenant (e.g. after an import) - its directory must never be touched.
     *
     * @return string|null  null if the area does not exist
     */
    private function areaDir(int $areaId): ?string
    {
        if (!$info = $this->areaInfo($areaId)) return null;
        $this->db->pushResult();
        $this->db->query("SELECT `deleted` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return null;
        if (!empty($row['deleted'])) return $this->trashPath($areaId);
        return $this->areaPath($info['instance_id'], $info['name']);
    }

    // ====================
    // Single file / single directory (called from the hooks)

    /**
     * Read the source code of a template from the file.
     *
     * @return string|null  null if the file (or the area) does not exist
     */
    public function readTemplate(int $areaId, string $name): ?string
    {
        if (($dir = $this->areaDir($areaId)) === null) return null;
        $file = $this->baseDir() . '/' . $dir . '/' . $name . self::EXTENSION;
        if (!is_file($file)) return null;
        $code = file_get_contents($file);
        return ($code === false) ? null : $code;
    }

    /**
     * Write the source code of a template (overwrites an existing file).
     * A file with identical content stays untouched - otherwise the reconciliation
     * would rewrite the timestamp on every run.
     */
    public function writeTemplate(int $areaId, string $name, string $code): void
    {
        if (($dir = $this->areaDir($areaId)) === null) return;
        $path = $dir . '/' . $name . self::EXTENSION;
        $file = $this->baseDir() . '/' . $path;
        if (is_file($file) && file_get_contents($file) === $code) return;
        $this->system->filePutContents($this->baseDir(), $path, $code, lock:true);
    }

    /**
     * Remove the file of a template (renaming, moving, deleting).
     */
    public function removeTemplate(int $areaId, string $name): void
    {
        if (($dir = $this->areaDir($areaId)) === null) return;
        $file = $this->baseDir() . '/' . $dir . '/' . $name . self::EXTENSION;
        if (is_file($file)) unlink($file);
    }

    /**
     * Create the directory of an area.
     */
    public function createAreaDir(int $instanceId, string $areaName): void
    {
        $this->system->createDir($this->baseDir(), $this->areaPath($instanceId, $areaName));
    }

    /**
     * Rename the directory of an area resp. move it to another tenant.
     * If the old directory is missing, the new one is created.
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
     * Remove the directory of an area including its files (area finally
     * deleted). An area from the trash only has its copy there
     * (.trash/{area_id}) - the directory {instance_id}/{name} may already belong
     * to a new area of the same name and stays untouched.
     */
    public function removeAreaDir(int $areaId, int $instanceId, string $areaName, bool $deleted): void
    {
        if (!$deleted) {
            $this->removeDir($this->baseDir() . '/' . $this->areaPath($instanceId, $areaName));
        }
        $this->removeDir($this->baseDir() . '/' . $this->trashPath($areaId));
    }

    /**
     * Move the directory of an area to the trash (soft delete).
     * It thus disappears from var/templates; sync() therefore neither creates
     * anything new for the area nor deletes anything.
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
     * Fetch the directory of an area back from the trash (restore).
     * Without a trash entry an empty directory is created - the templates of the
     * area then have no file any more and sync() removes them.
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

    /** Remove a directory with its files (one level) */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach ((array)glob($dir . '/*') as $file) {
            if (is_file($file)) unlink($file);
        }
        @rmdir($dir);
    }

    // ====================
    // Reconciliation

    /**
     * Reconcile file system -> database (see class comment).
     *
     *  1. Unknown directories become areas of type 'template', unknown
     *     files become templates (label = name), files with content other than
     *     `code` are saved anew (compiled).
     *  2. Templates without a file are deleted, areas without a directory go to
     *     the trash - both only if the tenant directory exists.
     *
     * Does not run if another reconciliation is still running (lock file in var/tmp).
     *
     * @return array  areas_created, templates_created, templates_updated,
     *                templates_deleted, areas_deleted, errors (messages),
     *                locked (true = skipped)
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

            // Areas and templates from the database
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

            // 1. Files -> database
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
                            // new file -> new template
                            $templateId = $this->createTemplate($areaId, $name, $code, $result['errors']);
                            if ($templateId > 0) {
                                $templates[$areaId][$name] = ['template_id' => $templateId, 'code' => $code];
                                $result['templates_created']++;
                            }
                        }
                        elseif ($templates[$areaId][$name]['code'] !== $code) {
                            // changed file -> save template anew (compile)
                            $templateId = $templates[$areaId][$name]['template_id'];
                            if ($this->updateTemplate($templateId, $areaId, $name, $code, $result['errors'])) {
                                $templates[$areaId][$name]['code'] = $code;
                                $result['templates_updated']++;
                            }
                        }
                    }
                }
            }

            // 2. Clean up the database: whatever no longer has a file disappears
            // --------------------
            foreach ($areas as $path => $areaId) {
                $instanceId = (int)strtok($path, '/');
                // Safeguard: without tenant directory delete nothing
                if (!is_dir($base . '/' . $instanceId)) continue;

                if (!is_dir($base . '/' . $path)) {
                    // Directory deleted -> area to the trash
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
                    // File deleted -> delete template
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
     * Area of type 'template' for a manually created directory.
     *
     * @return int  area_id, 0 on error (message in $errors)
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
     * Template for a manually created file.
     *
     * @return int  template_id, 0 on error (message in $errors)
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
     * Save the template for a changed file anew: Template::hookOnSave()
     * compiles and fills `code`/`styled_code`/`error_text`, hookOnSaved()
     * writes the PHP to var/php.
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
