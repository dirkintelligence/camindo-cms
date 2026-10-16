<?php
/**
 * Dataset child class for the table `template` - the frontend templates.
 *
 * A template is written in the CMS command language and compiled to PHP on saving.
 * At runtime no parser is used any more, the frontend includes finished
 * PHP.
 *
 * THE FILE LEADS
 * --------------------
 * The source code lies as a file under var/templates/{instance_id}/{bereich}/{name}.cms.php
 * (TemplateFiles). It is the only source:
 *
 *     file          source code in CMS syntax - what the editor works on.
 *                   load() reads it from there (hookOnLoaded()), save() writes it
 *                   back there (hookOnSaved()).
 *     code          copy of the source code in the database - ONLY for searching in the
 *                   backend for code locations
 *     styled_code   HTML with syntax highlighting - ONLY for TemplateBackend::info()
 *     error_text    messages of the parser, with line numbers
 *     php_code      compiled PHP from it - created in hookOnSave(), only written as a
 *                   file to var/php/{area_id}/{name}.php
 *                   (hookOnSaved()), never into the database
 *
 * Code never gets from the database into the file system: compilation always happens from the
 * source code that is being saved (form or file), and rebuildFiles()
 * reads the files. Security aspect - whoever can write to the database must not be able to run
 * PHP code with it. Manually changed, created or deleted
 * files are taken over by TemplateFiles::sync().
 *
 * Page::resolveRequest() includes the compiled file from var/php.
 *
 * NAMES ARE UNIQUE ONLY PER AREA
 * --------------------
 * `name` carries the flag 'unique_in_area': the same name may occur in several
 * template areas. A template is therefore addressed either as "name" or as
 * "areaname.name" (Cms::templateFile()). A bare name
 * that occurs in several areas is an error in the frontend (exception) -
 * the directory part {area_id} of the file path keeps the files apart.
 *
 * Syntax errors do NOT prevent saving. They end up in error_text and are shown in the
 * backend; the faulty command remains in the generated PHP as raw text.
 * So a half-finished template is not lost when saving intermediately.
 *
 * ASSIGNMENT TO CONTENT AREAS
 * --------------------
 * Which template applies to which area is not stated here, but in
 * `rel_area_template` and `area`.`default_template_id` - maintained via saveAccess().
 * These links (as well as `area`.`list_template_id`) exist only in the backend;
 * the file system does not know them.
 *
 * @see Camindo\Core\Modules\Template\TemplateFiles         source files, reconciliation
 * @see Camindo\Core\Modules\Template\TemplateParserTrait   parser and code generation
 * @see Camindo\Core\Modules\Template\TemplateBackend       backend interface
 * @see Camindo\Core\Page                                    delivery in the frontend
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Dataset;
use Camindo\Core\Modules\Template\TemplateParserTrait;

class Template extends Dataset {

    /** Verwendungen (`type`): '' = Seite/Baustein, 'scheduler' = zeitgesteuert */
    public const TYPES = ['', 'scheduler'];

    // Audit log: styled_code is created from `code` when saving (parse()) - the
    // comparison of `code` says everything; error_text stays (shows what a save
    // produced in messages)
    protected array $auditIgnore = ['styled_code'];

    // Brings parse(): CMS syntax -> PHP + styledCode + error list.
    // As a trait and not as its own class, because the parser needs direct access to the
    // dataset (among others $this->config for the snippet directories).
    use TemplateParserTrait;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'template';
        $this->moduleName = 'Template';
        // Templates contain script and event handlers as content - no sanitizing
        $this->sanitizeHtml = false;
        $this->defaultSortOrder = 'name ASC';
        $this->schema = [
            'template_id' => [
                'type'  => 'integer',
                'label' => 'template_id'
            ],
            'area_id' => [
                'type'  => 'integer', // no foreign key
                'label' => 'area_id',
                'min'   => 1
            ],
            // Website to which the template belongs. Not an input field: the value comes
            // from the container (`area`.`instance_id`) and is fetched from there by hookOnSave() on
            // every save. The copy in `template` carries the foreign key to `instance` and saves
            // Cms::select() the JOIN via the area (see Cms::$sqlAccess).
            'instance_id' => [
                'type'  => 'integer', // FK to `instance`, NOT NULL -> not 'id'
                'label' => '#template.field_instance_id',
                'min'   => 0
            ],
            // Usage: '' = page/component, 'scheduler' = time-controlled (module
            // Scheduler, task type template) - never callable via URL (Page)
            'type' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#template.field_type',
                'tooltip' => '#template.tooltip_field_type',
                'max'   => 12
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#template.field_name',
                'tooltip' => '#template.tooltip_field_name',
                'max'   => 32,
                'flags' => ['not_empty', 'trim', 'name', 'unique_in_area'],
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#template.field_label',
                'note' => '#template.note_field_label',
                'tooltip' => '#template.tooltip_field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core extension: select snippet (#prefix.key)
            ],
            'code' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#template.field_code',
            ],
            'styled_code' => [
                'type'  => 'text',
                'label' => '#template.field_styled_code',
            ],
            'error_text' => [
                'type'  => 'text',
                'label' => '#template.field_error_text',
            ],
            'created_by' => [
                'type'  => 'id',
                'label' => '#record.created_by',
                'table' => 'admin'
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#record.changed_by',
                'table' => 'admin'
            ],
            'created_at' => [
                'type'  => 'timestampl',
                'label' => '#record.field_created_at',
                'default' => 'now'
            ],
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#record.field_changed_at',
                'default' => 'now'
            ],
            'standalone' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#template.field_standalone',
                'tooltip' => '#template.tooltip_field_standalone'
            ]
        ];
    }

    protected function hookOnCreate(array &$data): void
    {
    }

    /**
     * Before saving: compile the CMS source code.
     *
     * Fills the three derived columns from `code`. They are therefore always consistent
     * with the source code - php_code, styled_code and error_text should never be
     * written by hand.
     *
     * Note: $data['code'] and $data['area_id'] are required. If save() is called with
     * a $fields list that does not contain 'code', the caller must still pass the
     * values in $data - from the form or the file,
     * never from the column `code` (load() supplies the file content anyway).
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // instance_id
        // --------------------
        // Not an input field: the template belongs to the website to which its container
        // belongs. Fetched from there on EVERY save - so a template that
        // changes container moves along with it. (Area::hookOnSaved() covers
        // the reverse case: the container changes the instance.)
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId > 0) {
            $data['instance_id'] = $this->areaInstanceId($areaId);
            if (!in_array('instance_id', $fields, true)) $fields[] = 'instance_id';
        }

        // Template-Code parsen
        $result               = $this->parse($data['code'] ?? '', (int)$data['area_id']);
        $data['php_code']     = $result['phpCode'];
        $data['styled_code']  = $result['styledCode'];
        $data['error_text']   = implode('<br>', (array)$result['errors']);
    }

    /**
     * After saving: store the compiled PHP as an executable file.
     *
     * The target is var/php/{area_id}/{name}.php - exactly the path that
     * Cms::templateFile() returns and from which Page resp. the include snippets of the
     * templates load. It is written with a file lock, so that a concurrent
     * frontend request does not include a half-written file.
     *
     * If the name or area changes (renaming, moving), the old file is
     * removed.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $templateDir = $this->templateDir();

        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        $name = (string)($data['name'] ?? $previousData['name'] ?? '');
        $this->system->filePutContents($templateDir, self::fileName($areaId, $name), $data['php_code'], lock:true);

        $oldAreaId = (int)($previousData['area_id'] ?? 0);
        $oldName = (string)($previousData['name'] ?? '');
        $renamed = ($oldName !== '' && ($oldName !== $name || $oldAreaId !== $areaId));
        if ($renamed) {
            $oldFile = $templateDir . '/' . self::fileName($oldAreaId, $oldName);
            if (is_file($oldFile)) unlink($oldFile);
        }

        // Source code as a file (var/templates/{instance_id}/{bereich}/{name}.cms.php,
        // see TemplateFiles) - the backend overwrites the file regardless of
        // its timestamp; when reconciling from the file the content is the same anyway
        // and the file stays untouched
        $files = $this->templateFiles();
        if ($renamed) $files->removeTemplate($oldAreaId, $oldName);
        $files->writeTemplate($areaId, $name, (string)($data['code'] ?? $previousData['code'] ?? ''));
    }

    /**
     * After loading: source code from the file.
     *
     * The column `code` is only the search copy (see class comment). If the
     * file is missing, the source code is empty - deliberately no fallback to the column, otherwise
     * "Edit -> Save" would bring code from the database into the
     * file system. TemplateFiles::sync() deletes a template without a file
     * anyway at the next run.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $data['code'] = $this->templateFiles()->readTemplate(
            (int)($data['area_id'] ?? 0), (string)($data['name'] ?? '')
        ) ?? '';
    }

    /**
     * Before the (final) deletion of the database entry
     */
    protected function hookOnDelete(array $data): void
    {
        // Delete php file
        // If the file cannot be deleted -> exception -> rollback
        // Manual intervention required (intended as a security aspect).
        $file = $this->templateDir() . '/' . self::fileName((int)$data['area_id'], (string)$data['name']);
        if (is_file($file)) unlink($file);

        // Source code file (var/templates/...)
        $this->templateFiles()->removeTemplate((int)$data['area_id'], (string)$data['name']);
    }

    private function templateFiles(): TemplateFiles
    {
        return $this->container->get(TemplateFiles::class);
    }

    /**
     * Relative path of the compiled file below var/php:
     * "{area_id}/{name}.php". The same rule applies in Cms::templateFile().
     */
    public static function fileName(int $areaId, string $name): string
    {
        return $areaId . '/' . $name . '.php';
    }

    private function templateDir(): string
    {
        return $this->config->get('paths.var_dir') . '/php';
    }

    /**
     * All command definitions (core and active modules, loadCommands()), sorted
     * alphabetically by name - for the command picker of the input mask
     * (TemplateBackend::command()).
     *
     * @return array  name (lowercase, without "CMS:") => definition from commands.php
     */
    public function commandDefinitions(): array
    {
        $commands = $this->loadCommands();
        ksort($commands);
        return $commands;
    }

    /**
     * Text of a command definition (note, description, value, default) for display:
     * '#prefix.key' is a snippet (Cms::textOrSnippet() rule), anything else - also a value
     * hint like '#rrggbb' of a module command - stays as it is. Plain text, not escaped.
     *
     * @param string $lang  language of the snippet (default: that of the call)
     */
    public function commandText(mixed $value, string $lang = ''): string
    {
        $value = (string)$value;
        if (preg_match('/^#([A-Za-z0-9_.]+\.[A-Za-z0-9_]+)$/', $value, $m)) {
            return $this->cms->snippet($m[1], [], $lang);
        }
        return $value;
    }

    /**
     * Rewrite all compiled files (Tools > Developer).
     *
     * Recompiles each template from its source file (var/templates, never from
     * the column `code`) to {area_id}/{name}.php, writes styled_code
     * and error_text to it (parser messages, also new hints after a change to the
     * compiler - widget TemplateBackend::widget()) and then removes
     * files from the root directory of var/php - the files were there
     * before the switch to area directories. Templates without a file are
     * skipped (TemplateFiles::sync() deletes them).
     *
     * @return int  number of files written
     */
    public function rebuildFiles(): int
    {
        $templateDir = $this->templateDir();
        $files = $this->templateFiles();
        $this->db->pushResult();
        $this->db->query("SELECT `template_id`, `area_id`, `name` FROM `template`");
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $count = 0;
        foreach ($rows as $row) {
            $code = $files->readTemplate((int)$row['area_id'], (string)$row['name']);
            if ($code === null) continue;
            $result = $this->parse($code, (int)$row['area_id']);
            $this->system->filePutContents(
                $templateDir,
                self::fileName((int)$row['area_id'], (string)$row['name']),
                $result['phpCode'],
                lock:true
            );
            $this->db->pushResult();
            $this->db->query(
                "UPDATE `template` SET `styled_code` = ?, `error_text` = ? WHERE `template_id` = ?",
                'ssi',
                [$result['styledCode'], implode('<br>', (array)$result['errors']), (int)$row['template_id']]
            );
            $this->db->popResult();
            $count++;
        }

        foreach ((array)glob($templateDir . '/*.php') as $file) {
            if (is_file($file)) unlink($file);
        }
        return $count;
    }

    /**
     * Move a template to another template area.
     *
     * Runs via save(): load() brings the source code from the file, hookOnSave()
     * fetches the instance_id of the new area and recompiles, hookOnSaved() stores
     * the files in the new area directory and removes the old ones. The uniqueness check of the name (unique_in_area)
     * applies to the target area; an error is in Cms::errors.
     *
     * The assignments to content areas (`rel_area_template`,
     * `area`.`default_template_id`) remain untouched - they hang on the
     * template_id, not on the area in which the template lies.
     *
     * @param int $id            template_id
     * @param int $targetAreaId  target area (type 'template')
     * @return bool
     */
    public function moveToArea(int $id, int $targetAreaId): bool
    {
        if (!$data = $this->load($id)) return false;
        if ((int)$data['area_id'] === $targetAreaId) return true;
        // Rights of the user group: write to source and target
        if (!$this->accessCheck('write', (int)$data['area_id']) || !$this->accessCheck('write', $targetAreaId)) return false;

        $this->db->query(
            "SELECT 1 FROM `area` WHERE `area_id` = ? AND `type` = 'template' AND `deleted` = 0",
            'i', [$targetAreaId]
        );
        if ($this->db->num_rows < 1) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }

        $data['area_id'] = $targetAreaId;
        $fields = ['area_id', 'name', 'styled_code', 'error_text'];
        return $this->save($fields, $data) !== null;
    }

    /**
     * Update template assignments (access)
     *
     * Writes both places where the assignment hangs in one pass:
     *   - `area`.`default_template_id`  (at most one default template per area)
     *   - `rel_area_template`           (selectable / override per area)
     * Areas for which neither selectable nor override is set get no row
     * in rel_area_template - the table therefore contains only real assignments.
     *
     * The default template is always additionally marked as "selectable"; this
     * simplifies the access check in Page::resolveRequest().
     * 
     * NEEDS IMPROVEMENT:
     * If the assignment between content area and template is removed,
     * the template still remains in record.template_id.
     * Calling the record does NOT fail, although this would be expected.
     * The record data survive removing and restoring the assignment.
     * 
     * TODO:
     * Output a note on how many records explicitly select the template
     * that is to be removed from the assignment -> choose an alternative template (or "Default")
     *
     * @param int $id   template ID
     * @param array $data permissions - key:area_id, val:
     *                  default = true: template is the default template in the area
     *                  selectable = true: template is selectable in the area
     *                  override = true: template specification in the URL may override the assigned template in the area
     * @param bool|int $standalone
     *                  true: template may be used without record contents
     * @param ?string $type  usage (self::TYPES), null = unchanged; a
     *                  scheduled template is never standalone
     * @return bool     false on errors
     */
    public function saveAccess(int $id, array $data, bool|int $standalone, ?string $type = null): bool
    {
        // Rights of the user group: write in the template area, read of the
        // content areas to which the template is assigned (developer docs, chapter 11 (Permissions))
        $access = $this->container->get(\Camindo\Core\Access::class);
        if ($access->isLoaded() && !$access->isSuperuser()) {
            $template = $this->load($id);
            $ok = $template !== null && $access->templateWrite((int)$template['area_id']);
            foreach (array_keys($data) as $areaId) {
                if (!$access->areaRead((int)$areaId)) $ok = false;
            }
            if (!$ok) {
                $this->cms->error($this->cms->snippet('error_access_denied'));
                return false;
            }
        }

        // Load current default templates from the content areas given (in $data).
        // Format: area_id => default_template_id
        $areaIds = $this->system->sanitizeIds(array_keys($data), true);
        $areaIdString = implode(',', $areaIds) ?: '0';
        $this->db->query("SELECT `area_id`,`default_template_id` FROM `area` WHERE `area_id` IN ($areaIdString) AND `deleted`=0");
        $defaultTemplate = $this->db->fetchPairs();

        // Collect data for `rel_area_template`
        $i = 0;
        $sqlValues = [];
        // Work through $data...
        foreach ($data as $areaId => $row) {
            $isCurrentlyDefault = ((int)($defaultTemplate[$areaId] ?? 0) === $id);
            $becomesDefault = (bool)($row['default'] ?? false);

            if ($isCurrentlyDefault != $becomesDefault) {
                // Update the default template of the area
                if (!$becomesDefault) {
                    $sql = "UPDATE `area` SET `default_template_id`= NULL WHERE `area_id` = ?";
                    $this->db->query($sql, 'i', [$areaId]);
                }
                else {
                    $sql = "UPDATE `area` SET `default_template_id`= ? WHERE `area_id` = ?";
                    $this->db->query($sql, 'ii', [$id, $areaId]);
                }
            }

            // Always also mark the default template as "selectable"
            // (eases the access check in class Page)
            if ($becomesDefault) {
                $row['selectable'] = 1;
            }

            // Data for rel_area_template
            $selectable = (int)($row['selectable'] ?? 0);
            $override = (int)($row['override'] ?? 0);
            if ($selectable || $override) {
                array_push($sqlValues, 
                    (int)$areaId, 
                    (int)$id, 
                    $selectable, 
                    $override
                );
                $i++;
            }
        }

        // Delete old data from `rel_area_template`
        $this->db->query("DELETE FROM `rel_area_template` WHERE `template_id` = ?", 'i', [$id]);

        // Insert new data
        if ($i > 0) {
            $sqlCols = implode(', ', array_fill(0, $i, '(?, ?, ?, ?)'));
            $sql = "INSERT INTO `rel_area_template` (`area_id`, `template_id`, `selectable`, `override`)"
            ." VALUES " . $sqlCols;
            $sqlTypes = str_repeat('iiii', $i);
            $this->db->query($sql, $sqlTypes, $sqlValues);
        }

        // Update standalone flag and usage
        if ($type !== null) {
            $type = in_array($type, self::TYPES, true) ? $type : '';
            if ($type === 'scheduler') $standalone = 0;
            $this->db->query("UPDATE `template` SET `type` = ? WHERE `template_id` = ?", 'si', [$type, $id]);
        }
        $sql = "UPDATE `template` SET `standalone` = ? WHERE `template_id` = ?";
        $this->db->query($sql, 'ii', [$standalone, $id]);
        return true;
    }
}