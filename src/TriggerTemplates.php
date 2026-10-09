<?php
/**
 * Trigger templates: event methods of a content area from frontend templates
 * (`rel_area_trigger`: any number per area, with `position`).
 *
 * Record calls run() from its hooks; for every trigger template of the area
 * that has the matching method, it runs with the same arguments as the hook -
 * references stay references, so the template can change $data, $affectedFields etc.
 *
 * ORDER
 * --------------------
 * The templates run one after another in the order of assignment (`position`).
 * Each one sees what the previous ones changed in $data, $affectedFields ... If
 * one throws an exception, the following ones no longer run - the process aborts.
 *
 * EVENTS
 * --------------------
 *     onCreate(array &$data): void           init() - default values of a new record
 *     onLoaded(array &$data): void           after every Record::load() (input mask,
 *                                            previousData on save ...). NOT for
 *                                            CMS:SELECT / Cms::fetch() in the frontend
 *     onSave(array &$affectedFields, array &$data, array &$previousData): void
 *                                            before validation and writing; a field that
 *                                            the template sets anew must be added to
 *                                            $affectedFields
 *     onSaved(array &$affectedFields, array &$data, array &$previousData): void
 *                                            after writing, before the commit
 *     onSoftDelete(array $data): void        into the trash
 *     onRestore(array $data): void           back from the trash
 *     onDelete(array $data): void            permanent deletion, before the DELETE
 *     onDeleted(array $data): void           permanent deletion, after the DELETE
 *     onPrivacyAction(string $action, array &$data, array $context): void
 *                                            action of a deletion policy (Privacy module,
 *                                            PrivacyJobs): $action is the identifier of the
 *                                            block; $context['target'] 'record' -
 *                                            $data is the record (multilingual
 *                                            fields as language_id => value), changes
 *                                            are saved - or 'audit' - $data
 *                                            is an entry in the audit log, changes
 *                                            are written back
 *
 * All calls except onLoaded/onCreate run inside the transaction of the operation: an
 * exception in the template (throw new \Exception('Message')) aborts it and rolls it
 * back; the message appears in the mask. Temporarily saving a working copy
 * (tab switch) also calls onSave/onSaved - $this->temporary is then true.
 *
 * What counts is the area in which the record is after the operation: when moving
 * (MoveTrait) therefore the trigger templates of the TARGET AREA. There $data already
 * contains the fields of the target area, $previousData the state in the
 * source area (with its field names).
 *
 * THE CODE
 * --------------------
 * The template is compiled like any other (var/php/{area_id}/{name}.php). For
 * the call, the compiled code is placed into the body of an anonymous class
 * that inherits from TriggerTemplate, and stored as var/cache/trigger/{template_id}.php
 * (regenerated as soon as the compiled file is newer). It follows that:
 *   - the template contains only methods (and possibly properties/constants), no
 *     statements outside them and no output
 *   - $this is the trigger object, see TriggerTemplate (cms, db, record ...)
 *   - methods of the same name in templates of different areas do not interfere
 *   - address classes by their fully qualified name (no `use`)
 * A template is loaded at most once per request.
 *
 * A template that cannot be loaded (syntax error, output outside the
 * methods) throws an exception - silently skipping it would mean saving records without the
 * intended processing. A template that has meanwhile been deleted
 * (assignment gone via FK ON DELETE CASCADE, cache not yet renewed) is skipped.
 *
 * CMS COMMANDS
 * --------------------
 * During the call, $GLOBALS['cms'] holds only ['record_id' => …] (restored afterwards) -
 * CMS:SELECT relation=… / CMS:RELATION without a source mean the triggering
 * record by it. In onSave and in onSaved when temporarily saving, this is
 * the working copy (Dataset::savingWorkingCopyId()): the links hang on it until the
 * checkIn, so the queries see the new state. $data['record_id'] stays
 * the id of the original. Contents of the operation: $data / $previousData.
 *
 * RE-ENTRY
 * --------------------
 * If a template saves the same record again in onSaved(), it would run endlessly.
 * An event is therefore not run nested for the same record per template; other
 * records (also of the same area) fire their triggers normally. When saving nested,
 * the other templates of the area therefore run - only the one currently running is skipped.
 *
 * @see Camindo\Core\TriggerTemplate           base class, environment in the template
 * @see Camindo\Core\Modules\Record\Record     called from the hooks
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\Record;

class TriggerTemplates
{
    /** Events (method names) */
    public const EVENTS = [
        'onCreate', 'onLoaded', 'onSave', 'onSaved',
        'onSoftDelete', 'onDelete', 'onDeleted', 'onRestore',
        'onPrivacyAction',
    ];

    /** Loaded trigger objects: template_id => TriggerTemplate|false (no template) */
    private array $handlers = [];

    /** Running calls: "template_id:event:record_id" => true */
    private array $running = [];

    public function __construct(
        private Cms $cms,
        private Database $db,
        private Config $config,
        private System $system,
        private InstanceCache $instanceCache,
        private ContainerInterface $container
    ) {}

    /**
     * Run the event method of the trigger templates of an area - one after another
     * in the order of assignment (see class comment).
     *
     * @param Record $record     triggering dataset
     * @param int    $areaId     area of the record
     * @param string $event      one of the EVENTS
     * @param bool   $temporary  temporarily saving a working copy
     * @param mixed  ...$args    arguments of the hook (passed through by reference)
     * @throws \RuntimeException template cannot be loaded, or an exception in it
     */
    public function run(Record $record, int $areaId, string $event, bool $temporary, mixed &...$args): void
    {
        $templateIds = $this->templateIds($areaId);
        if (!$templateIds) return;

        // Running record for the CMS commands (CMS:SELECT relation=… without src_id,
        // CMS:RELATION ...): only the id, the contents of the operation are in $data /
        // $previousData. While the links hang on the working copy (onSave,
        // onSaved when temporarily saving), its id - otherwise that of the record
        $data = $args[1] ?? $args[0] ?? [];
        $recordId = $event === 'onPrivacyAction'
            ? (int)($args[2]['record_id'] ?? 0)
            : (int)($data['record_id'] ?? 0);
        if (in_array($event, ['onSave', 'onSaved'], true) && $record->savingWorkingCopyId() > 0) {
            $recordId = $record->savingWorkingCopyId();
        }
        $hadGlobal = array_key_exists('cms', $GLOBALS);
        $previousGlobal = $GLOBALS['cms'] ?? null;
        $GLOBALS['cms'] = ['record_id' => $recordId];
        try {
            foreach ($templateIds as $templateId) {
                $this->runOne($record, $areaId, $templateId, $event, $temporary, $args);
            }
        }
        finally {
            if ($hadGlobal) $GLOBALS['cms'] = $previousGlobal;
            else unset($GLOBALS['cms']);
        }
    }

    /**
     * One template of the area (see run()).
     */
    private function runOne(Record $record, int $areaId, int $templateId, string $event, bool $temporary, array &$args): void
    {
        $handler = $this->handler($record, $areaId, $templateId);
        if ($handler === null || !method_exists($handler, $event)) return;

        // Re-entry (see class comment) - only here, since the previous
        // templates may have changed $data
        $data = $args[1] ?? $args[0] ?? [];
        $key = $templateId . ':' . $event . ':' . (int)($data['record_id'] ?? 0);
        if (isset($this->running[$key])) return;

        $this->running[$key] = true;
        $previousTemporary = $handler->temporary;
        $handler->temporary = $temporary;
        try {
            $handler->$event(...$args);
        }
        finally {
            $handler->temporary = $previousTemporary;
            unset($this->running[$key]);
        }
    }

    /**
     * Does the area have at least one trigger template with this event method?
     * (Privacy module: a trigger action without a method is an error, not a silent
     * nothing.)
     *
     * @throws \RuntimeException  a template cannot be loaded
     */
    public function has(Record $record, int $areaId, string $event): bool
    {
        foreach ($this->templateIds($areaId) as $templateId) {
            $handler = $this->handler($record, $areaId, $templateId);
            if ($handler !== null && method_exists($handler, $event)) return true;
        }
        return false;
    }

    /**
     * Trigger templates of an area in their order (InstanceCache,
     * from `rel_area_trigger`).
     *
     * @return int[]
     */
    private function templateIds(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->instanceCache->areaInfo($areaId)['trigger_template_ids'] ?? [];
    }

    /**
     * Trigger object of a template - loaded once per request.
     *
     * @return TriggerTemplate|null  null: template does not exist (any more)
     * @throws \RuntimeException     code cannot be loaded
     */
    private function handler(Record $record, int $areaId, int $templateId): ?TriggerTemplate
    {
        if (array_key_exists($templateId, $this->handlers)) {
            return $this->handlers[$templateId] ?: null;
        }
        $this->handlers[$templateId] = false;

        // Compiled file
        $this->db->pushResult();
        $this->db->query("SELECT `area_id`, `name` FROM `template` WHERE `template_id` = ?", 'i', [$templateId]);
        $row = $this->db->fetchNum();
        $this->db->popResult();
        if (!$row) return null;
        $compiled = $this->cms->templatePath . '/' . (int)$row[0] . '/' . $row[1] . '.php';
        if (!is_file($compiled)) return null;

        $label = "trigger template \"{$row[1]}\" (id {$templateId})";
        $file = $this->wrapperFile($templateId, $compiled, $label);

        try {
            $handler = (static function (string $_file, Cms $cms, Database $db, Record $record, ContainerInterface $container, int $areaId) {
                return include $_file;
            })($file, $this->cms, $this->db, $record, $this->container, $areaId);
        }
        catch (\Throwable $e) {
            // Line relative to the generated file (the code starts at line 3 with the first template line)
            $where = $e->getFile() === $file ? ' (line ' . max(1, $e->getLine() - 2) . ' of the compiled template)' : '';
            throw new \RuntimeException("Error in {$label}: " . $e->getMessage() . $where, 0, $e);
        }
        if (!$handler instanceof TriggerTemplate) {
            throw new \RuntimeException("Error in {$label}: could not be loaded.");
        }
        return $this->handlers[$templateId] = $handler;
    }

    /**
     * Store the compiled code as the body of an anonymous class (see class
     * comment) - regenerated only if the compiled file is newer.
     *
     * @return string  path of the generated file
     */
    private function wrapperFile(int $templateId, string $compiled, string $label): string
    {
        $varDir = $this->config->get('paths.var_dir');
        $file = "{$varDir}/cache/trigger/{$templateId}.php";
        if (is_file($file) && filemtime($file) >= filemtime($compiled)) return $file;

        // Remove the frame, merge adjoining PHP blocks (CMS commands)
        $code = trim((string)file_get_contents($compiled));
        if (str_starts_with($code, '<?php')) $code = substr($code, 5);
        if (str_ends_with($code, '?>')) $code = substr($code, 0, -2);
        $code = preg_replace('/\?>\s*<\?php\b/', "\n", $code);

        $wrapper = "<?php\n"
            . "// Generated by Camindo\\Core\\TriggerTemplates from {$label} - do not edit.\n"
            . "return new class(\$cms, \$db, \$record, \$container, \$areaId) extends \\Camindo\\Core\\TriggerTemplate {\n"
            . $code
            . "\n};\n";

        if (!$this->system->createDir("{$varDir}/cache", 'trigger')) {
            throw new \RuntimeException("Error in {$label}: directory var/cache/trigger could not be created.");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $wrapper) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Error in {$label}: {$file} could not be written.");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        return $file;
    }
}
