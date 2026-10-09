<?php
/**
 * Trigger-Templates: Ereignis-Methoden eines Inhaltsbereichs aus Frontend-Templates
 * (`rel_area_trigger`: beliebig viele je Bereich, mit `position`).
 *
 * Record ruft aus seinen Hooks run() auf; für jedes Trigger-Template des Bereichs,
 * das die passende Methode hat, läuft sie mit denselben Argumenten wie der Hook -
 * Referenzen bleiben Referenzen, das Template kann also $data, $affectedFields usw.
 * verändern.
 *
 * REIHENFOLGE
 * --------------------
 * Die Templates laufen nacheinander in der Reihenfolge der Zuweisung (`position`).
 * Jedes sieht, was die vorigen an $data, $affectedFields ... geändert haben. Wirft
 * eines eine Ausnahme, laufen die folgenden nicht mehr - der Vorgang bricht ab.
 *
 * EREIGNISSE
 * --------------------
 *     onCreate(array &$data): void           init() - Vorbelegung eines neuen Datensatzes
 *     onLoaded(array &$data): void           nach jedem Record::load() (Eingabemaske,
 *                                            previousData beim Speichern ...). NICHT bei
 *                                            CMS:SELECT / Cms::fetch() im Frontend
 *     onSave(array &$affectedFields, array &$data, array &$previousData): void
 *                                            vor Prüfung und Schreiben; ein Feld, das
 *                                            das Template neu setzt, muss es in
 *                                            $affectedFields eintragen
 *     onSaved(array &$affectedFields, array &$data, array &$previousData): void
 *                                            nach dem Schreiben, vor dem Commit
 *     onSoftDelete(array $data): void        in den Papierkorb
 *     onRestore(array $data): void           aus dem Papierkorb zurück
 *     onDelete(array $data): void            endgültiges Löschen, vor dem DELETE
 *     onDeleted(array $data): void           endgültiges Löschen, nach dem DELETE
 *     onPrivacyAction(string $action, array &$data, array $context): void
 *                                            Aktion einer Lösch-Policy (Modul Privacy,
 *                                            PrivacyJobs): $action ist die Kennung des
 *                                            Blocks; $context['target'] 'record' -
 *                                            $data ist der Datensatz (mehrsprachige
 *                                            Felder als language_id => Wert), Änderungen
 *                                            werden gespeichert - oder 'audit' - $data
 *                                            ist ein Eintrag im Audit-Log, Änderungen
 *                                            werden zurückgeschrieben
 *
 * Alle Aufrufe außer onLoaded/onCreate laufen in der Transaktion des Vorgangs: eine
 * Ausnahme im Template (throw new \Exception('Meldung')) bricht ihn ab und macht ihn
 * rückgängig; die Meldung erscheint in der Maske. Auch das Zwischenspeichern einer
 * Arbeitskopie (Reiterwechsel) ruft onSave/onSaved - $this->temporary ist dann true.
 *
 * Maßgeblich ist der Bereich, in dem der Datensatz nach dem Vorgang steht: beim
 * Verschieben (MoveTrait) also die Trigger-Templates des ZIELBEREICHS. $data enthält
 * dort bereits die Felder des Zielbereichs, $previousData den Stand im
 * Herkunftsbereich (mit dessen Feldnamen).
 *
 * DER CODE
 * --------------------
 * Das Template wird wie jedes andere kompiliert (var/php/{area_id}/{name}.php). Für
 * den Aufruf wird der kompilierte Code in den Rumpf einer anonymen Klasse gesetzt,
 * die von TriggerTemplate erbt, und als var/cache/trigger/{template_id}.php
 * abgelegt (neu erzeugt, sobald die kompilierte Datei jünger ist). Daraus folgt:
 *   - das Template enthält nur Methoden (und ggf. Eigenschaften/Konstanten), keine
 *     Anweisungen außerhalb davon und keine Ausgabe
 *   - $this ist das Trigger-Objekt, siehe TriggerTemplate (cms, db, record ...)
 *   - gleichnamige Methoden in Templates verschiedener Bereiche stören sich nicht
 *   - Klassen per voll qualifiziertem Namen ansprechen (kein `use`)
 * Je Request wird ein Template höchstens einmal geladen.
 *
 * Ein Template, das sich nicht laden lässt (Syntaxfehler, Ausgabe außerhalb der
 * Methoden), löst eine Ausnahme aus - still übergehen hieße, Datensätze ohne die
 * vorgesehene Aufbereitung zu speichern. Ein inzwischen gelöschtes Template
 * (Zuweisung per FK ON DELETE CASCADE weg, Cache noch nicht erneuert) wird übergangen.
 *
 * CMS-BEFEHLE
 * --------------------
 * Während des Aufrufs steht in $GLOBALS['cms'] nur ['record_id' => …] (danach
 * wiederhergestellt) - CMS:SELECT relation=… / CMS:RELATION ohne Quelle meinen damit
 * den auslösenden Datensatz. In onSave und in onSaved beim Zwischenspeichern ist das
 * die Arbeitskopie (Dataset::savingWorkingCopyId()): an ihr hängen bis zum checkIn
 * die Verknüpfungen, die Abfragen sehen so den neuen Stand. $data['record_id'] bleibt
 * die Id des Originals. Inhalte des Vorgangs: $data / $previousData.
 *
 * WIEDEREINTRITT
 * --------------------
 * Speichert ein Template in onSaved() denselben Datensatz erneut, liefe es endlos.
 * Ein Ereignis wird deshalb je Template für denselben Datensatz nicht verschachtelt
 * ausgeführt; andere Datensätze (auch desselben Bereichs) lösen ihre Trigger normal
 * aus. Beim verschachtelten Speichern laufen also die übrigen Templates des Bereichs
 * - nur das gerade laufende wird übergangen.
 *
 * @see Camindo\Core\TriggerTemplate           Basisklasse, Umgebung im Template
 * @see Camindo\Core\Modules\Record\Record     Aufruf aus den Hooks
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\Record;

class TriggerTemplates
{
    /** Ereignisse (Methodennamen) */
    public const EVENTS = [
        'onCreate', 'onLoaded', 'onSave', 'onSaved',
        'onSoftDelete', 'onDelete', 'onDeleted', 'onRestore',
        'onPrivacyAction',
    ];

    /** Geladene Trigger-Objekte: template_id => TriggerTemplate|false (kein Template) */
    private array $handlers = [];

    /** Laufende Aufrufe: "template_id:event:record_id" => true */
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
     * Ereignis-Methode der Trigger-Templates eines Bereichs ausführen - nacheinander
     * in der Reihenfolge der Zuweisung (siehe Klassenkommentar).
     *
     * @param Record $record     auslösender Dataset
     * @param int    $areaId     Bereich des Datensatzes
     * @param string $event      einer der EVENTS
     * @param bool   $temporary  Zwischenspeichern einer Arbeitskopie
     * @param mixed  ...$args    Argumente des Hooks (per Referenz durchgereicht)
     * @throws \RuntimeException Template lässt sich nicht laden, oder Ausnahme darin
     */
    public function run(Record $record, int $areaId, string $event, bool $temporary, mixed &...$args): void
    {
        $templateIds = $this->templateIds($areaId);
        if (!$templateIds) return;

        // Laufender Datensatz für die CMS-Befehle (CMS:SELECT relation=… ohne src_id,
        // CMS:RELATION ...): nur die Id, die Inhalte des Vorgangs stehen in $data /
        // $previousData. Solange die Verknüpfungen an der Arbeitskopie hängen (onSave,
        // onSaved beim Zwischenspeichern), deren Id - sonst die des Datensatzes
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
     * Ein Template des Bereichs (siehe run()).
     */
    private function runOne(Record $record, int $areaId, int $templateId, string $event, bool $temporary, array &$args): void
    {
        $handler = $this->handler($record, $areaId, $templateId);
        if ($handler === null || !method_exists($handler, $event)) return;

        // Wiedereintritt (siehe Klassenkommentar) - erst hier, die vorigen
        // Templates können $data verändert haben
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
     * Hat der Bereich mindestens ein Trigger-Template mit dieser Ereignis-Methode?
     * (Modul Privacy: eine Trigger-Aktion ohne Methode ist ein Fehler, kein stilles
     * Nichts.)
     *
     * @throws \RuntimeException  ein Template lässt sich nicht laden
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
     * Trigger-Templates eines Bereichs in ihrer Reihenfolge (InstanceCache,
     * aus `rel_area_trigger`).
     *
     * @return int[]
     */
    private function templateIds(int $areaId): array
    {
        if ($areaId < 1) return [];
        return $this->instanceCache->areaInfo($areaId)['trigger_template_ids'] ?? [];
    }

    /**
     * Trigger-Objekt eines Templates - geladen einmal je Request.
     *
     * @return TriggerTemplate|null  null: Template gibt es nicht (mehr)
     * @throws \RuntimeException     Code lässt sich nicht laden
     */
    private function handler(Record $record, int $areaId, int $templateId): ?TriggerTemplate
    {
        if (array_key_exists($templateId, $this->handlers)) {
            return $this->handlers[$templateId] ?: null;
        }
        $this->handlers[$templateId] = false;

        // Kompilierte Datei
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
            // Zeile bezogen auf die erzeugte Datei (der Code beginnt in Zeile 3 mit der ersten Template-Zeile)
            $where = $e->getFile() === $file ? ' (line ' . max(1, $e->getLine() - 2) . ' of the compiled template)' : '';
            throw new \RuntimeException("Error in {$label}: " . $e->getMessage() . $where, 0, $e);
        }
        if (!$handler instanceof TriggerTemplate) {
            throw new \RuntimeException("Error in {$label}: could not be loaded.");
        }
        return $this->handlers[$templateId] = $handler;
    }

    /**
     * Den kompilierten Code als Rumpf einer anonymen Klasse ablegen (siehe
     * Klassenkommentar) - neu nur, wenn die kompilierte Datei jünger ist.
     *
     * @return string  Pfad der erzeugten Datei
     */
    private function wrapperFile(int $templateId, string $compiled, string $label): string
    {
        $varDir = $this->config->get('paths.var_dir');
        $file = "{$varDir}/cache/trigger/{$templateId}.php";
        if (is_file($file) && filemtime($file) >= filemtime($compiled)) return $file;

        // Rahmen weg, aneinanderstoßende PHP-Blöcke (CMS-Befehle) zusammenziehen
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
