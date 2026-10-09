<?php
/**
 * Dateicache der Instanzen und ihrer Sprachen: var/cache/instances.php und
 * var/cache/instance-{id}.php.
 *
 * WOZU EIN DATEICACHE
 * --------------------
 * Jeder Frontend-Aufruf muss zuerst wissen, zu welcher Website (Instance) er gehört
 * und welche Sprachen dort gelten - noch bevor der erste Inhalt geladen wird. Bisher
 * kostete das je Request vier Abfragen (Page::resolveInstance()). Die Angaben ändern
 * sich aber praktisch nie, deshalb liegen sie jetzt als PHP-Arrays im Dateisystem und
 * werden per include geladen; der Opcache hält sie danach im Speicher.
 *
 * DIE DATEIEN
 * --------------------
 *     instances.php        Domain-Zuordnungen ALLER Instanzen (Haupt-Domains als
 *                          Nachschlagetabelle, Zusatz-Domains als geordnete Liste,
 *                          weil dort auch reguläre Ausdrücke stehen können) sowie
 *                          die Hauptsprache jeder Instanz, ALLE Inhaltsbereiche
 *                          (area_id => name/type/instance_id, siehe unten), die
 *                          Ids der Benutzergruppen, Kürzel und Kollation je Sprache
 *                          (languageCode(), collation(), siehe Camindo\Core\Collation)
 *     instance-{id}.php    alle Angaben EINER Instanz: die Spalten der Tabelle
 *                          `instance`, die Sprachen in der Reihenfolge Hauptsprache
 *                          zuerst (mit code/label/endonym aus `language`), die
 *                          lesbaren Instanzen, die Zusatz-Domains und ihre
 *                          Inhaltsbereiche (area_id => name/type/instance_id)
 *     group-{id}.php       die Sicht EINER Benutzergruppe auf das Backend: die ihr
 *                          zugewiesenen Instanzen, ihre lesbaren Bereiche und alle
 *                          weiteren Rechte (Module, Bereichs-Flags, Sprachen) -
 *                          siehe "Gruppendateien" unten und Camindo\Core\Access
 *
 * Die Dateien werden komplett neu geschrieben, sobald sich in den Modulen Instance,
 * Language, Area oder an den Benutzergruppen etwas ändert (rebuild(), aufgerufen
 * aus deren Hooks). Fehlt eine
 * Datei - etwa weil der Cache geleert wurde -, erzeugt der erste Zugriff sie sofort
 * neu. Damit nicht mehrere gleichzeitige Requests dasselbe tun, läuft der Neuaufbau
 * unter einer Sperrdatei (flock); wer die Sperre bekommt, prüft danach noch einmal,
 * ob ein anderer die Datei inzwischen geschrieben hat.
 *
 * Geschrieben wird immer erst in eine temporäre Datei, die dann per rename() an ihren
 * Platz kommt: ein include sieht so nie eine halb geschriebene Datei.
 *
 * SPRACHEN EINER INSTANZ
 * --------------------
 * languages() liefert die Sprachen einer Instanz als Array language_id => Zeile, die
 * Hauptsprache an erster Stelle. Diese Reihenfolge ist überall die maßgebliche:
 * für die data_*-Zeilen je Sprache, für `record_cache`, für die Reiter der
 * Eingabemaske und für den Sprachumschalter im Frontend.
 *
 * INHALTSBEREICHE
 * --------------------
 * Templates sprechen einen Bereich über seinen Namen an (CMS:SELECT mit
 * filter[area]=artikel bzw. sort=area), die Datenbank kennt nur `record`.`area_id`.
 * Statt je Abfrage eine Unterabfrage auf `area` zu stellen, liegen die Bereiche im
 * Cache: instance-{id}.php führt die der Instanz in Baumreihenfolge (lft),
 * instances.php die aller Instanzen - für Abfragen ohne Instanzbeschränkung
 * (Backend). Je Bereich stehen `name`, `type` und `instance_id` darin:
 *
 *     'areas' => [ 131 => ['name' => 'article', 'type' => 'record', 'instance_id' => 1], ... ]
 *
 * Hat ein Bereich Trigger-Templates (`rel_area_trigger`), stehen deren Ids in ihrer
 * Reihenfolge zusätzlich darin ('trigger_template_ids') - Record fragt sie bei jedem
 * Laden und Speichern ab (TriggerTemplates), der Cache erspart dabei die Abfrage.
 * Ebenso der Slug-Präfix eines Inhaltsbereichs (`details`.slug_prefix), aufgelöst
 * je Sprache der Instanz: 'slug_prefix' => [language_id => 'farben', ...] - CMS:URL
 * und Page brauchen ihn bei jedem Link bzw. Aufruf (slugPrefix()).
 *
 * Cms::select() übersetzt damit den Namen in die Id bzw. baut aus den nach Namen
 * sortierten Ids ein ORDER BY FIELD(); die Dataset-Klassen holen sich Instanz und
 * Typ eines Bereichs von hier statt aus `area` (areaInstanceId(), areaType()).
 * areas()/allAreas()/areasOf() liefern weiterhin nur area_id => name.
 *
 * Auch als "gelöscht" markierte Bereiche stehen darin: ihre Datensätze tragen
 * dieselbe Markierung und tauchen in select() ohnehin nicht auf, und die Hooks vor
 * dem Soft-Delete bzw. Restore sähen noch den alten Stand. Erst das endgültige
 * Löschen (Area::hookOnDeleted()) nimmt einen Bereich aus dem Cache. Wer `deleted`
 * oder andere Spalten braucht (label, lft, details ...), fragt weiterhin `area`.
 *
 * GRUPPENDATEIEN (group-{group_id}.php)
 * --------------------
 * Das Backend zeigt einem Admin nur die Instanzen und Bereiche, auf die seine
 * Benutzergruppe Zugriff hat (docs/developers/12-group-rights.md). Damit das ohne
 * Abfragen je Request geht, liegt je Gruppe eine Datei mit ihrer Sicht:
 *
 *     'group_id'           group_id
 *     'superuser'          true für Gruppe 1 - sie darf alles; die Datei enthält
 *                          dann alle Instanzen, Bereiche, Module und Sprachen,
 *                          damit der Ladepfad überall derselbe ist
 *     'label'              `group`.`label`
 *     'instance_ids'       zugewiesene Instanzen (rel_group_instance), Reihenfolge
 *                          wie instances.php
 *     'primary_languages'  instance_id => primary_language_id dieser Instanzen
 *     'areas'              lesbare Bereiche (rel_group_area) in diesen Instanzen,
 *                          Format wie oben; Ordner haben keine Rechte und fehlen
 *     'modules'            Modulname => gewährte Zusatzrechte (`group`.`details`)
 *     'interfaces'         Oberflächen, über die sich Admins der Gruppe anmelden
 *                          dürfen (`group`.`details`.`interfaces`, Access::INTERFACES)
 *     'rights' => [
 *         'areas'     => [area_id => [group_write, group_delete, other_write,
 *                                     other_delete, set_owner] (bool)]
 *         'languages' => [language_id => can_write (bool)]   Zeile = Leserecht
 *         'statuses'  => [status_id, ...]   Status, die die Gruppe setzen darf
 *                        (rel_group_status); lesen darf jede Gruppe alle
 *     ]
 *
 * Session lädt die Datei der angemeldeten Gruppe in Camindo\Core\Access; alle
 * Rechteprüfungen sind danach reine Array-Zugriffe. Neu geschrieben werden die
 * Gruppendateien mit allen anderen (writeAll()) - auch aus den Hooks von Group.
 *
 * @see Camindo\Core\Page                          Domain -> Instanz -> Sprache
 * @see Camindo\Core\Modules\Instance\Instance     ruft rebuild() aus den Hooks
 * @see Camindo\Core\Modules\Language\Language     dito
 * @see Camindo\Core\Modules\Area\Area             dito (Bereiche)
 * @see Camindo\Core\Modules\Admin\Group           dito (Gruppendateien)
 * @see Camindo\Core\Access                          liest die Gruppendatei
 * @see Camindo\Core\Cms::areaMap()                 Bereichsnamen im Zugriffsbereich
 */
namespace Camindo\Core;

class InstanceCache {

    /** Name der Sammeldatei */
    private const FILE_ALL = 'instances.php';
    /** Sperrdatei für den Neuaufbau */
    private const FILE_LOCK = 'instances.lock';

    /** Superuser-Gruppe: darf alles, ihre Datei enthält alles */
    public const SUPERUSER_GROUP_ID = 1;

    /** Bereits geladene Dateien je Request: instance_id => Daten */
    private array $instances = [];
    /** Bereits geladene Gruppendateien je Request: group_id => Daten */
    private array $groups = [];
    /** Inhalt von instances.php, wenn bereits geladen */
    private ?array $all = null;

    public function __construct(
        private Config $config,
        private Database $db,
        private System $system,
        private Integrity $integrity,
        private HtmlSanitizer $htmlSanitizer
    ) {}

    // ====================
    // Lesen
    // ====================

    /**
     * Inhalt von instances.php: Domain-Zuordnungen und Hauptsprachen aller Instanzen.
     *
     * Fehlt die Datei, wird sie neu erzeugt.
     *
     * @return array  siehe buildAll()
     */
    public function all(): array
    {
        if ($this->all !== null) return $this->all;

        $file = $this->cacheDir() . '/' . self::FILE_ALL;
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file) ?? [];
        }
        $this->all = $data;
        return $data;
    }

    /**
     * Angaben einer Instanz aus instance-{id}.php.
     *
     * Fehlt die Datei, wird der Cache neu erzeugt. Gibt es die Instanz auch danach
     * nicht, liefert die Methode null.
     *
     * @param int $instanceId
     * @return array|null  siehe buildInstance()
     */
    public function instance(int $instanceId): ?array
    {
        if ($instanceId < 1) return null;
        if (array_key_exists($instanceId, $this->instances)) {
            return $this->instances[$instanceId];
        }

        $file = $this->instanceFile($instanceId);
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file);
        }
        $this->instances[$instanceId] = $data;
        return $data;
    }

    /**
     * Sicht einer Benutzergruppe aus group-{id}.php (siehe Klassenkommentar).
     *
     * Fehlt die Datei, wird der Cache neu erzeugt. Gibt es die Gruppe auch danach
     * nicht, liefert die Methode null.
     *
     * @param int $groupId
     * @return array|null  siehe buildGroups()
     */
    public function group(int $groupId): ?array
    {
        if ($groupId < 1) return null;
        if (array_key_exists($groupId, $this->groups)) {
            return $this->groups[$groupId];
        }

        $file = $this->groupFile($groupId);
        $data = $this->includeFile($file);
        if ($data === null) {
            $this->rebuildMissing();
            $data = $this->includeFile($file);
        }
        $this->groups[$groupId] = $data;
        return $data;
    }

    /**
     * Sprachen einer Instanz, Hauptsprache zuerst.
     *
     * @param int $instanceId
     * @return array  language_id => ['language_id', 'code', 'backend_code', 'label',
     *                'endonym', 'timezone']. Leer, wenn die Instanz unbekannt ist.
     */
    public function languages(int $instanceId): array
    {
        return (array)($this->instance($instanceId)['languages'] ?? []);
    }

    /**
     * Sprachen mehrerer zusammengeschalteter Instanzen (eigene Instanz + lesbare):
     * die der ersten Instanz in ihrer Reihenfolge, danach die der übrigen, soweit
     * noch nicht enthalten. Das ist die Sprachliste eines Frontend-Requests -
     * jede dieser Sprachen ist über /{lang}/ erreichbar und im Sprachumschalter.
     *
     * @param int[] $instanceIds  eigene Instanz zuerst
     * @return array  language_id => ['language_id', 'code', 'label', 'endonym']
     */
    public function languagesOf(array $instanceIds): array
    {
        $result = [];
        foreach ($instanceIds as $instanceId) {
            foreach ($this->languages((int)$instanceId) as $languageId => $language) {
                $result[(int)$languageId] ??= $language;
            }
        }
        return $result;
    }

    /**
     * Ids der Sprachen einer Instanz, Hauptsprache zuerst.
     *
     * @param int $instanceId
     * @return int[]
     */
    public function languageIds(int $instanceId): array
    {
        return array_map('intval', array_keys($this->languages($instanceId)));
    }

    /**
     * Hauptsprache einer Instanz.
     *
     * @param int $instanceId
     * @return int  language_id; 0, wenn die Instanz unbekannt ist
     */
    public function primaryLanguageId(int $instanceId): int
    {
        return (int)($this->instance($instanceId)['primary_language_id'] ?? 0);
    }

    /**
     * Hauptsprachen ALLER Instanzen - für Abfragen ohne Sprachkontext (Backend).
     *
     * @return array  instance_id => primary_language_id
     */
    public function primaryLanguages(): array
    {
        return array_map('intval', (array)($this->all()['primary_languages'] ?? []));
    }

    /**
     * Kollation für Textvergleiche und Sortierung in einer Sprache (Collation).
     *
     * @param int $languageId
     * @return string  Name der Kollation; '' = die der Textspalten (neutral), dann
     *                 braucht eine Abfrage kein COLLATE
     */
    public function collation(int $languageId): string
    {
        return (string)($this->all()['collations'][$languageId] ?? '');
    }

    /**
     * Kürzel einer Sprache (`language`.`code`) - auch für Sprachen, die keiner
     * Instanz zugeordnet sind.
     */
    public function languageCode(int $languageId): string
    {
        return (string)($this->all()['language_codes'][$languageId] ?? '');
    }

    /**
     * Alle Instanzen als instance_id => Label, in der Reihenfolge des Backends
     * (`position`, die Standard-Instance am Ende - Instance::orderSql()). Für die Mandanten-Ebene der
     * Bereichsbäume (RecordBackendFolderList, AreaBackend::list()).
     *
     * @return array  instance_id => label (Snippet-Verweise unaufgelöst)
     */
    public function instanceLabels(): array
    {
        $labels = [];
        foreach ((array)($this->all()['instance_ids'] ?? []) as $instanceId) {
            $labels[(int)$instanceId] = (string)($this->instance((int)$instanceId)['label'] ?? '');
        }
        return $labels;
    }

    /**
     * Sind Mandanten eingerichtet - gibt es außer der Standard-Instance noch eine?
     * Erst dann bekommen die Bereichsbäume je Instanz einen Hauptordner.
     */
    public function hasMultipleInstances(): bool
    {
        return count((array)($this->all()['instance_ids'] ?? [])) > 1;
    }

    /**
     * Inhaltsbereiche einer Instanz in Baumreihenfolge.
     *
     * @param int $instanceId
     * @return array  area_id => name. Leer, wenn die Instanz unbekannt ist.
     */
    public function areas(int $instanceId): array
    {
        return $this->names((array)($this->instance($instanceId)['areas'] ?? []));
    }

    /**
     * Inhaltsbereiche ALLER Instanzen - für Abfragen ohne Instanzbeschränkung
     * (Backend). Reihenfolge: Instanzen nach position, darin Baumreihenfolge.
     *
     * @return array  area_id => name
     */
    public function allAreas(): array
    {
        return $this->names((array)($this->all()['areas'] ?? []));
    }

    /**
     * Angaben zu EINEM Bereich: name, type, instance_id - oder null, wenn es ihn
     * nicht gibt. Ersetzt die Abfrage "SELECT instance_id, type FROM area" in den
     * Dataset-Klassen; `deleted` und die übrigen Spalten stehen nicht hier.
     *
     * @param int $areaId
     * @return array|null  ['name' => string, 'type' => string, 'instance_id' => int,
     *                      'trigger_template_ids' => int[] (Reihenfolge der Ausführung),
     *                      'overview_template_id' => int (0 = keins)]
     */
    public function areaInfo(int $areaId): ?array
    {
        if ($areaId < 1) return null;
        $info = $this->all()['areas'][$areaId] ?? null;
        if (!is_array($info)) return null;
        return [
            'name'        => (string)($info['name'] ?? ''),
            'type'        => (string)($info['type'] ?? ''),
            'instance_id' => (int)($info['instance_id'] ?? 0),
            'trigger_template_ids' => array_map('intval', (array)($info['trigger_template_ids'] ?? [])),
            'overview_template_id' => (int)($info['overview_template_id'] ?? 0),
        ];
    }

    /**
     * Typ eines Bereichs (record, profile, ugc, template, folder); '' = unbekannt.
     */
    public function areaType(int $areaId): string
    {
        return (string)($this->areaInfo($areaId)['type'] ?? '');
    }

    /**
     * Instanz eines Bereichs; 0 = unbekannt.
     */
    public function areaInstanceId(int $areaId): int
    {
        return (int)($this->areaInfo($areaId)['instance_id'] ?? 0);
    }

    /**
     * Bereiche bestimmter Typen, wahlweise nur bestimmter Instanzen (leer = alle),
     * in der Reihenfolge von areasOf().
     *
     * @param string[] $types        z. B. Area::CONTENT_TYPES; leer = alle Typen
     * @param int[]    $instanceIds  leer = alle Instanzen
     * @return array   area_id => ['name', 'type', 'instance_id']
     */
    public function areasOfType(array $types, array $instanceIds = []): array
    {
        $result = [];
        $source = empty($instanceIds) ? [(array)($this->all()['areas'] ?? [])] : [];
        foreach ($instanceIds as $instanceId) {
            $source[] = (array)($this->instance((int)$instanceId)['areas'] ?? []);
        }
        foreach ($source as $areas) {
            foreach ($areas as $areaId => $info) {
                if (!is_array($info)) continue;
                if (!empty($types) && !in_array((string)($info['type'] ?? ''), $types, true)) continue;
                $result[(int)$areaId] = [
                    'name'        => (string)($info['name'] ?? ''),
                    'type'        => (string)($info['type'] ?? ''),
                    'instance_id' => (int)($info['instance_id'] ?? 0),
                ];
            }
        }
        return $result;
    }

    /**
     * Slug-Präfix eines Bereichs in einer Sprache (`area`.`details`.slug_prefix).
     *
     * Ein Inhaltsbereich kann seinen Datensätzen einen Präfix voranstellen: aus dem
     * Slug "blau" im Bereich mit dem Präfix "farben" wird die URL /farben/blau
     * (Cms::recordUrl(), Cms::recordIdsBySlugPath()). Der Präfix ist mehrsprachig
     * mit Fallback auf die Hauptsprache der Instanz des Bereichs; im Cache steht er
     * bereits aufgelöst für jede ihrer Sprachen. Eine Sprache, die die Instanz nicht
     * führt, liest - wie die Datensätze selbst - die Hauptsprache.
     *
     * @return string  Präfix ohne Slash, '' = keiner (auch Ordner, Template-Bereiche)
     */
    public function slugPrefix(int $areaId, int $languageId): string
    {
        $prefixes = $this->slugPrefixes($areaId);
        if (empty($prefixes)) return '';
        if (isset($prefixes[$languageId])) return $prefixes[$languageId];
        $instanceId = $this->areaInstanceId($areaId);
        if (in_array($languageId, $this->languageIds($instanceId), true)) return '';
        return $prefixes[$this->primaryLanguageId($instanceId)] ?? '';
    }

    /**
     * Alle Slug-Präfixe eines Bereichs: language_id => Präfix (nur Sprachen mit
     * Präfix, Fallback bereits aufgelöst - siehe slugPrefix()).
     */
    public function slugPrefixes(int $areaId): array
    {
        return (array)($this->all()['areas'][$areaId]['slug_prefix'] ?? []);
    }

    /**
     * Bereiche mit Slug-Präfix in den angegebenen Instanzen (leer = alle).
     *
     * @param int[] $instanceIds
     * @return array  area_id => [language_id => Präfix]
     */
    public function slugPrefixAreas(array $instanceIds = []): array
    {
        $result = [];
        foreach ((array)($this->all()['areas'] ?? []) as $areaId => $info) {
            if (!is_array($info) || empty($info['slug_prefix'])) continue;
            if (!empty($instanceIds) && !in_array((int)($info['instance_id'] ?? 0), $instanceIds, true)) continue;
            $result[(int)$areaId] = (array)$info['slug_prefix'];
        }
        return $result;
    }

    /**
     * Rohwerte eines Slug-Präfixes (language_id => Eingabe) für die Sprachen einer
     * Instanz auflösen: leere Sprachen erhalten den Präfix der Hauptsprache.
     *
     * @param array $raw          language_id => Präfix (wie in details.slug_prefix)
     * @param int[] $languageIds  Sprachen der Instanz
     * @param int   $primaryId    ihre Hauptsprache
     * @return array  language_id => Präfix, nur nicht leere
     */
    public static function resolveSlugPrefixes(array $raw, array $languageIds, int $primaryId): array
    {
        $primary = trim((string)($raw[$primaryId] ?? ''));
        $resolved = [];
        foreach ($languageIds as $languageId) {
            $languageId = (int)$languageId;
            $prefix = trim((string)($raw[$languageId] ?? ''));
            if ($prefix === '') $prefix = $primary;
            if ($prefix !== '') $resolved[$languageId] = $prefix;
        }
        return $resolved;
    }

    /**
     * Ids aller Benutzergruppen (Reihenfolge: group_id), aus instances.php.
     *
     * @return int[]
     */
    public function groupIds(): array
    {
        return array_map('intval', (array)($this->all()['group_ids'] ?? []));
    }

    /**
     * area_id => name aus einer Bereichsliste des Caches.
     */
    private function names(array $areas): array
    {
        $names = [];
        foreach ($areas as $areaId => $info) {
            $names[(int)$areaId] = is_array($info) ? (string)($info['name'] ?? '') : (string)$info;
        }
        return $names;
    }

    /**
     * Inhaltsbereiche mehrerer Instanzen (eigene Instanz + lesbare), in der
     * Reihenfolge der übergebenen Instanzen; leere Liste = alle Instanzen.
     *
     * @param int[] $instanceIds
     * @return array  area_id => name
     */
    public function areasOf(array $instanceIds): array
    {
        if (empty($instanceIds)) return $this->allAreas();
        $result = [];
        foreach ($instanceIds as $instanceId) {
            foreach ($this->areas((int)$instanceId) as $areaId => $name) {
                $result[(int)$areaId] = $name;
            }
        }
        return $result;
    }

    /**
     * Instanzen, innerhalb derer ein `unique`-Wert einmalig sein muss.
     *
     * Ein Frontend-Aufruf sieht die eigene Instanz und deren lesbare Instanzen
     * (`readable_instances`, siehe Page::resolveInstance()). Ein Wert, der dort
     * doppelt vorkäme, wäre für den Aufruf nicht mehr eindeutig - etwa ein Slug.
     * Deshalb zählen für die Eindeutigkeit eines Datensatzes der Instanz X:
     *
     *     X selbst
     *     die Instanzen, die X lesen darf         (aus X heraus sichtbar)
     *     die Instanzen, die X lesen dürfen       (X ist aus ihnen heraus sichtbar)
     *
     * Nicht transitiv: liest A die Instanz B und B die Instanz C, sieht A von C
     * nichts - C und A müssen sich also nicht ausschließen.
     *
     * @param int $instanceId
     * @return int[]  instance_ids, die eigene zuerst; [$instanceId] bei unbekannter Instanz
     * @see Camindo\Core\Dataset::validateValue()   Metafelder mit 'unique'
     * @see Camindo\Core\Dataset::uniqueVirtualOk() virtuelle Felder mit 'unique'
     */
    public function uniqueScope(int $instanceId): array
    {
        if ($instanceId < 1) return [];
        $scope = [$instanceId];
        foreach ((array)($this->instance($instanceId)['readable_instances'] ?? []) as $readableId) {
            $readableId = (int)$readableId;
            if ($readableId > 0 && !in_array($readableId, $scope, true)) $scope[] = $readableId;
        }
        // Umgekehrte Richtung: wer darf $instanceId lesen?
        foreach ((array)($this->all()['instance_ids'] ?? []) as $otherId) {
            $otherId = (int)$otherId;
            if (in_array($otherId, $scope, true)) continue;
            $readable = array_map('intval', (array)($this->instance($otherId)['readable_instances'] ?? []));
            if (in_array($instanceId, $readable, true)) $scope[] = $otherId;
        }
        return $scope;
    }

    /**
     * Sprache zu einem Sprachkürzel innerhalb einer Instanz.
     *
     * Verglichen wird ohne Rücksicht auf Groß-/Kleinschreibung und mit '-' und '_'
     * als gleichwertigen Trennzeichen ("de-AT" findet "de_at").
     *
     * @param int    $instanceId
     * @param string $code       Sprachkürzel aus der URL
     * @return array|null        Sprachzeile oder null, wenn die Instanz sie nicht führt
     */
    public function languageByCode(int $instanceId, string $code): ?array
    {
        return $this->findByCode($this->languages($instanceId), $code);
    }

    /**
     * Sprache zu einem Kürzel in einer gegebenen Sprachliste (languagesOf()).
     *
     * @param array  $languages  language_id => Sprachzeile
     * @param string $code
     * @return array|null
     */
    public function findByCode(array $languages, string $code): ?array
    {
        $needle = $this->normalizeCode($code);
        if ($needle === '') return null;
        // 1. exakte Übereinstimmung ("de-at" = "de_AT")
        foreach ($languages as $language) {
            if ($this->normalizeCode((string)($language['code'] ?? '')) === $needle) {
                return $language;
            }
        }
        // 2. Grundsprache: "/de/" findet "de_DE", "/de-ch/" findet "de" - die erste
        //    passende Sprache in der Reihenfolge des Auftritts. Bei canonical = 1
        //    leitet Page danach auf das genaue Kürzel um.
        $needleBase = explode('-', $needle)[0];
        foreach ($languages as $language) {
            $langCode = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($langCode === $needleBase || explode('-', $langCode)[0] === $needle) {
                return $language;
            }
        }
        return null;
    }

    /**
     * Passendste Sprache einer Instanz zum Accept-Language-Header des Browsers.
     *
     * Der Header wird nach Gewichtung (q) durchgegangen. Je Eintrag zählt zuerst
     * eine exakte Übereinstimmung ("de-at" = "de_AT"), dann eine Übereinstimmung
     * der Grundsprache ("de-at" trifft "de" oder "de_DE"). Passt nichts, bleibt es
     * bei der Hauptsprache.
     *
     * @param int    $instanceId
     * @param string $acceptLanguage  Inhalt von $_SERVER['HTTP_ACCEPT_LANGUAGE']
     * @return int                    language_id
     */
    public function negotiateLanguage(int $instanceId, string $acceptLanguage): int
    {
        return $this->negotiate($this->languages($instanceId), $this->primaryLanguageId($instanceId), $acceptLanguage);
    }

    /**
     * Wie negotiateLanguage(), aber über eine gegebene Sprachliste (languagesOf()).
     *
     * @param array  $languages       language_id => Sprachzeile, Hauptsprache zuerst
     * @param int    $primaryId       Rückfall, wenn nichts passt
     * @param string $acceptLanguage  Inhalt von $_SERVER['HTTP_ACCEPT_LANGUAGE']
     * @return int                    language_id
     */
    public function negotiate(array $languages, int $primaryId, string $acceptLanguage): int
    {
        if (count($languages) < 2 || trim($acceptLanguage) === '') return $primaryId;

        // Header zerlegen: "de-AT,de;q=0.9,en;q=0.8" -> [['de-at', 1.0], ['de', 0.9], ...]
        $wanted = [];
        foreach (explode(',', $acceptLanguage) as $i => $part) {
            $pieces = explode(';', trim($part));
            $tag = $this->normalizeCode($pieces[0] ?? '');
            if ($tag === '' || $tag === '*') continue;
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)/i', $param, $m)) $q = (float)$m[1];
            }
            if ($q <= 0) continue;
            // Reihenfolge im Header als Nachrang, damit die Sortierung stabil bleibt
            $wanted[] = [$tag, $q, $i];
        }
        usort($wanted, fn($a, $b) => ($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));

        // Kürzel der Instanz-Sprachen vorbereiten: exakt und Grundsprache
        $exact = [];
        $base  = [];
        foreach ($languages as $languageId => $language) {
            $code = $this->normalizeCode((string)($language['code'] ?? ''));
            if ($code === '') continue;
            $exact[$code] = (int)$languageId;
            $baseCode = explode('-', $code)[0];
            // Erste Sprache je Grundsprache gewinnt (Reihenfolge der Instanz)
            if (!isset($base[$baseCode])) $base[$baseCode] = (int)$languageId;
        }

        foreach ($wanted as [$tag]) {
            if (isset($exact[$tag])) return $exact[$tag];
            $baseTag = explode('-', $tag)[0];
            if (isset($base[$baseTag])) return $base[$baseTag];
        }
        return $primaryId;
    }

    /**
     * Instanz zu einer Domain ermitteln.
     *
     * Reihenfolge - der erste Treffer gewinnt:
     *     1. `instance`.`primary_domain`
     *     2. `rel_instance_domain` - wörtlich, oder als regulärer Ausdruck, wenn dort
     *        `regex` = 1 steht; in der Reihenfolge instance.position, rel.position
     *     3. die Standard-Instance
     *
     * @param string $host  Hostname ohne Port, kleingeschrieben
     * @return int          instance_id
     */
    public function resolveDomain(string $host): int
    {
        $all = $this->all();
        $id = $this->matchDomain($host);
        // 3. Standard-Instance
        return $id > 0 ? $id : (int)($all['default_id'] ?? 1);
    }

    /**
     * Wie resolveDomain(), aber ohne Rückfall auf die Standard-Instanz:
     * 0 = die Domain gehört keiner Instanz (z. B. die feste Backend-Domain).
     */
    public function matchDomain(string $host): int
    {
        $all = $this->all();
        $host = mb_strtolower(trim($host));
        if ($host === '') return 0;

        // 1. Haupt-Domain
        $primaryId = (int)($all['primary_domains'][$host] ?? 0);
        if ($primaryId > 0) return $primaryId;

        // 2. Zusatz-Domains
        foreach ((array)($all['additional_domains'] ?? []) as $row) {
            $domain = (string)($row['domain'] ?? '');
            if (empty($row['regex'])) {
                if ($domain === $host) return (int)$row['instance_id'];
                continue;
            }
            // Regulärer Ausdruck aus dem Backend: ein ungültiges Muster darf den
            // Request nicht abbrechen, es zählt einfach nicht als Treffer.
            $pattern = '~^(?:' . str_replace('~', '\\~', $domain) . ')$~ui';
            if (@preg_match($pattern, $host) === 1) return (int)$row['instance_id'];
        }
        return 0;
    }

    // ====================
    // Schreiben
    // ====================

    /**
     * Alle Cache-Dateien neu schreiben.
     *
     * Aufzurufen, wenn sich Instanzen, deren Relation-Tabellen, Sprachen oder
     * Inhaltsbereiche geändert haben (Hooks der Module Instance, Language und Area).
     * Läuft unter der Sperre, damit
     * nicht zwei Schreiber gleichzeitig am Werk sind.
     */
    public function rebuild(): void
    {
        $this->withLock(function () {
            $this->writeAll();
        });
        // Im Speicher gehaltene Kopien verwerfen
        $this->all = null;
        $this->instances = [];
        $this->groups = [];
    }

    /**
     * Cache nur dann neu erzeugen, wenn die Sammeldatei fehlt (oder eine Einzeldatei
     * zu einer Instanz, die es laut Sammeldatei gibt).
     *
     * Wird von den Lesemethoden gerufen. Unter der Sperre wird noch einmal geprüft:
     * ein anderer Request kann die Dateien inzwischen geschrieben haben.
     */
    private function rebuildMissing(): void
    {
        $this->withLock(function () {
            if ($this->isComplete()) return;
            $this->writeAll();
        });
        $this->all = null;
        $this->instances = [];
        $this->groups = [];
    }

    /**
     * Sind alle Dateien vorhanden, die es laut Sammeldatei geben muss?
     * (Eine Sammeldatei ohne 'group_ids', 'collations' oder 'language_codes' stammt
     * aus einer früheren Version und gilt als unvollständig.)
     */
    private function isComplete(): bool
    {
        $all = $this->includeFile($this->cacheDir() . '/' . self::FILE_ALL);
        if ($all === null || !isset($all['group_ids'], $all['collations'], $all['language_codes'])) return false;
        foreach ((array)($all['instance_ids'] ?? []) as $instanceId) {
            if (!is_file($this->instanceFile((int)$instanceId))) return false;
        }
        foreach ((array)$all['group_ids'] as $groupId) {
            if (!is_file($this->groupFile((int)$groupId))) return false;
        }
        return true;
    }

    /**
     * Kollation je Sprache (Collation::forLocale()); '' wo sie der Kollation der
     * Textspalten entspricht - dort bleibt ein Index auf `data` nutzbar.
     *
     * @param array $languages  language_id => ['code' => …]
     * @return array            language_id => Kollation oder ''
     */
    private function languageCollations(array $languages): array
    {
        $available = Collation::available($this->db);
        $this->db->query("SELECT `COLLATION_NAME` FROM `information_schema`.`COLUMNS`"
            . " WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'data_txt' AND `COLUMN_NAME` = 'data'");
        $column = (string)($this->db->fetchNum()[0] ?? '');
        $result = [];
        foreach ($languages as $languageId => $language) {
            $collation = Collation::forLocale((string)$language['code'], $available);
            $result[(int)$languageId] = $collation === $column ? '' : $collation;
        }
        return $result;
    }

    /**
     * Die Dateien aus der Datenbank aufbauen und schreiben.
     * Nur innerhalb von withLock() aufrufen.
     */
    private function writeAll(): void
    {
        $this->db->pushResult();
        try {
            // Sprachen
            $languages = [];
            // (backend_code und timezone: Snippet-Sprache und Zeitzone, die
            //  Cms::setLanguage() im Frontend zu dieser Inhaltssprache einstellt)
            $this->db->query("SELECT `language_id`, `code`, `backend_code`, `label`, `endonym`, `timezone`, `position` FROM `language` ORDER BY `position` ASC");
            while ($row = $this->db->fetch()) {
                $languages[(int)$row['language_id']] = [
                    'language_id'  => (int)$row['language_id'],
                    'code'         => (string)$row['code'],
                    'backend_code' => (string)$row['backend_code'],
                    'label'        => $this->htmlSanitizer->clean((string)$row['label']),
                    'endonym'      => $this->htmlSanitizer->clean((string)$row['endonym']),
                    'timezone'     => (string)$row['timezone'],
                ];
            }
            $collations = $this->languageCollations($languages);

            // Instanzen
            $instances = [];
            $this->db->query("SELECT * FROM `instance` ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql());
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                $instances[$id] = [
                    'instance_id'          => $id,
                    'label'                => $this->htmlSanitizer->clean((string)$row['label']),
                    'position'             => (int)$row['position'],
                    'primary_language_id'  => (int)$row['primary_language_id'],
                    'primary_domain'       => mb_strtolower((string)$row['primary_domain']),
                    'protocol'             => (string)$row['protocol'],
                    'canonical'            => (int)$row['canonical'],
                    'record_id'            => (int)($row['record_id'] ?? 0),
                    'template_id'          => (int)($row['template_id'] ?? 0),
                    // Zusatzangaben: Script-Policy (SecurityHeaders)
                    'details'              => json_decode((string)($row['details'] ?? ''), true) ?: [],
                    'additional_languages' => [],
                    'languages'            => [],
                    'readable_instances'   => [],
                    'additional_domains'   => [],
                    'areas'                => [],
                ];
            }

            // Inhaltsbereiche (alle Typen, auch als gelöscht markierte - siehe
            // Klassenkommentar). Baumreihenfolge je Instanz; für die Sammeldatei
            // zusätzlich nach Instanz-Position, daher der JOIN.
            $allAreas = [];
            $rawSlugPrefixes = []; // area_id => details.slug_prefix (roh, je language_id)
            // Trigger-Templates je Bereich in ihrer Reihenfolge (TriggerTemplates)
            $triggers = [];
            $this->db->query("SELECT `area_id`, `template_id` FROM `rel_area_trigger` ORDER BY `area_id`, `position`, `template_id`");
            while ($row = $this->db->fetchNum()) {
                $triggers[(int)$row[0]][] = (int)$row[1];
            }

            $sql = "SELECT `a`.`area_id`, `a`.`instance_id`, `a`.`name`, `a`.`type`, `a`.`overview_template_id`, `a`.`details`, `a`.`deleted` FROM `area` `a`"
                 . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `a`.`instance_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i') . ", `a`.`lft` ASC";
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $areaId = (int)$row['area_id'];
                $info = [
                    'name'        => (string)$row['name'],
                    'type'        => (string)$row['type'],
                    'instance_id' => $id,
                ];
                // nur wenn gesetzt - hält die Cachedateien klein (TriggerTemplates)
                if (isset($triggers[$areaId])) {
                    $info['trigger_template_ids'] = $triggers[$areaId];
                }
                // Übersichts-Template der Präfix-Adresse (Page::resolveRequest())
                if (!empty($row['overview_template_id'])) {
                    $info['overview_template_id'] = (int)$row['overview_template_id'];
                }
                // Slug-Präfix (nur Inhaltsbereiche, nicht gelöschte) - aufgelöst
                // wird er unten, wenn die Sprachen der Instanz feststehen
                if (empty($row['deleted']) && in_array($info['type'], ['record', 'profile', 'ugc'], true)) {
                    $details = json_decode((string)($row['details'] ?? ''), true);
                    if (is_array($details) && !empty($details['slug_prefix']) && is_array($details['slug_prefix'])) {
                        $rawSlugPrefixes[$areaId] = $details['slug_prefix'];
                    }
                }
                $instances[$id]['areas'][$areaId] = $info;
                $allAreas[$areaId] = $info;
            }

            // Weitere Sprachen (eigene Reihenfolge je Instanz)
            $this->db->query("SELECT `instance_id`, `language_id` FROM `rel_instance_language` ORDER BY `instance_id` ASC, `position` ASC");
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $instances[$id]['additional_languages'][] = (int)$row['language_id'];
            }

            // Zusatz-Domains
            $additionalDomains = [];
            $sql = "SELECT `r`.`instance_id`, `r`.`domain`, `r`.`regex`"
                 . " FROM `rel_instance_domain` `r`"
                 . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `r`.`instance_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i') . ", `r`.`position` ASC";
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $entry = [
                    'instance_id' => $id,
                    'domain'      => mb_strtolower((string)$row['domain']),
                    'regex'       => (int)$row['regex'],
                ];
                $additionalDomains[] = $entry;
                $instances[$id]['additional_domains'][] = ['domain' => $entry['domain'], 'regex' => $entry['regex']];
            }

            // Lesbare Instanzen (Reihenfolge der Instanzen selbst)
            $sql = "SELECT `r`.`instance_id`, `r`.`readable_id` FROM `rel_instance_instance` `r`"
                 . " LEFT JOIN `instance` `i` ON `i`.`instance_id` = `r`.`readable_id`"
                 . " ORDER BY " . \Camindo\Core\Modules\Instance\Instance::orderSql('i');
            $this->db->query($sql);
            while ($row = $this->db->fetch()) {
                $id = (int)$row['instance_id'];
                if (!isset($instances[$id])) continue;
                $readableId = (int)$row['readable_id'];
                if ($readableId > 0 && $readableId !== $id) {
                    $instances[$id]['readable_instances'][] = $readableId;
                }
            }
            // Benutzergruppen und ihre Rechte (siehe buildGroups())
            $groupRows = $this->readGroups();
            $this->db->query("SELECT `status_id` FROM `status` ORDER BY `position` ASC");
            $statusIds = array_map('intval', $this->db->fetchColumn());
        } finally {
            $this->db->popResult();
        }

        // Sprachliste je Instanz: Hauptsprache zuerst, dann die weiteren in ihrer
        // Reihenfolge. Sprachen, die es nicht (mehr) gibt, fallen heraus.
        $primaryLanguages = [];
        $primaryDomains   = [];
        foreach ($instances as $id => &$instance) {
            $list = [];
            $primaryId = $instance['primary_language_id'];
            if (isset($languages[$primaryId])) {
                $list[$primaryId] = $languages[$primaryId];
            }
            foreach ($instance['additional_languages'] as $languageId) {
                if ($languageId === $primaryId) continue;
                if (!isset($languages[$languageId])) continue;
                $list[$languageId] = $languages[$languageId];
            }
            $instance['languages'] = $list;
            $instance['additional_languages'] = array_values(array_diff(array_keys($list), [$primaryId]));
            $primaryLanguages[$id] = $primaryId;
            if ($instance['primary_domain'] !== '') {
                // Erste Instanz gewinnt, falls dieselbe Domain doppelt eingetragen ist
                $primaryDomains[$instance['primary_domain']] ??= $id;
            }
        }
        unset($instance);

        // Slug-Präfixe je Sprache der Instanz, mit Fallback auf die Hauptsprache
        // (siehe slugPrefix()); Bereiche ohne Präfix bekommen keinen Eintrag
        foreach ($rawSlugPrefixes as $areaId => $raw) {
            $id = (int)$allAreas[$areaId]['instance_id'];
            $resolved = self::resolveSlugPrefixes(
                $raw,
                array_keys($instances[$id]['languages']),
                (int)$instances[$id]['primary_language_id']
            );
            if (empty($resolved)) continue;
            $allAreas[$areaId]['slug_prefix'] = $resolved;
            $instances[$id]['areas'][$areaId]['slug_prefix'] = $resolved;
        }

        $groups = $this->buildGroups($groupRows, array_keys($instances), $primaryLanguages, $allAreas, array_keys($languages), $statusIds);

        $all = [
            'default_id'         => \Camindo\Core\Modules\Instance\Instance::DEFAULT_ID,
            'instance_ids'       => array_keys($instances),
            'primary_domains'    => $primaryDomains,
            'additional_domains' => $additionalDomains,
            'primary_languages'  => $primaryLanguages,
            'collations'         => $collations,
            'language_codes'     => array_map(fn(array $language) => $language['code'], $languages),
            'areas'              => $allAreas,
            'group_ids'          => array_keys($groups),
        ];

        $dir = $this->cacheDir();
        $this->system->createDir($this->config->get('paths.var_dir'), 'cache');

        // Einzeldateien
        foreach ($instances as $id => $instance) {
            $this->writeFile($this->instanceFile($id), $instance);
        }
        foreach ($groups as $id => $group) {
            $this->writeFile($this->groupFile($id), $group);
        }
        // Sammeldatei zuletzt: sie ist das Signal, dass der Bestand vollständig ist
        $this->writeFile($dir . '/' . self::FILE_ALL, $all);

        // Dateien gelöschter Instanzen und Gruppen entfernen
        foreach ((array)glob($dir . '/instance-*.php') as $file) {
            if (!preg_match('/instance-(\d+)\.php$/', $file, $m)) continue;
            if (isset($instances[(int)$m[1]])) continue;
            @unlink($file);
        }
        foreach ((array)glob($dir . '/group-*.php') as $file) {
            if (!preg_match('/group-(\d+)\.php$/', $file, $m)) continue;
            if (isset($groups[(int)$m[1]])) continue;
            @unlink($file);
        }
    }

    /**
     * Gruppen und ihre Rechte-Tabellen lesen. Nur innerhalb von writeAll()
     * (unter pushResult()) aufrufen.
     *
     * @return array  group_id => ['label', 'details' (Array), 'instance_ids',
     *                'areas' (area_id => Flags), 'languages' (language_id => can_write),
     *                'statuses' (status_ids)]
     */
    private function readGroups(): array
    {
        // Signaturen (Integrity): eine Zeile ohne gültige Signatur zählt nicht -
        // bei `group` fallen die details (Module) weg, bei den Relation-Tabellen die
        // Zeile. Die Superuser-Gruppe braucht keine: ihre Rechte stehen nicht in der
        // Datenbank (buildGroups()).
        $groups = [];
        $this->db->query("SELECT `group_id`, `label`, `details`, `sig` FROM `group` ORDER BY `group_id` ASC");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            $detailsOk = $id === self::SUPERUSER_GROUP_ID
                || $this->integrity->verifyGroup($id, (string)($row['details'] ?? ''), (string)($row['sig'] ?? ''));
            $groups[$id] = [
                'label'        => (string)$row['label'],
                'details'      => $detailsOk ? (json_decode((string)($row['details'] ?? ''), true) ?: []) : [],
                'instance_ids' => [],
                'areas'        => [],
                'languages'    => [],
                'statuses'     => [],
            ];
        }
        $this->db->query("SELECT `group_id`, `instance_id`, `sig` FROM `rel_group_instance`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_instance', $row)) continue;
            $groups[$id]['instance_ids'][] = (int)$row['instance_id'];
        }
        $this->db->query("SELECT * FROM `rel_group_area`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_area', $row)) continue;
            $groups[$id]['areas'][(int)$row['area_id']] = [
                'group_write'  => !empty($row['group_write']),
                'group_delete' => !empty($row['group_delete']),
                'other_write'  => !empty($row['other_write']),
                'other_delete' => !empty($row['other_delete']),
                'set_owner'    => !empty($row['set_owner']),
            ];
        }
        $this->db->query("SELECT `group_id`, `language_id`, `can_write`, `sig` FROM `rel_group_language`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_language', $row)) continue;
            $groups[$id]['languages'][(int)$row['language_id']] = !empty($row['can_write']);
        }
        $this->db->query("SELECT `group_id`, `status_id`, `sig` FROM `rel_group_status`");
        while ($row = $this->db->fetch()) {
            $id = (int)$row['group_id'];
            if (!isset($groups[$id]) || !$this->integrity->verifyRight('rel_group_status', $row)) continue;
            $groups[$id]['statuses'][] = (int)$row['status_id'];
        }
        return $groups;
    }

    /**
     * Inhalt der Gruppendateien zusammenstellen (Format siehe Klassenkommentar).
     *
     * Die Superuser-Gruppe bekommt alles: alle Instanzen, alle Bereiche (auch
     * Ordner), alle Module mit allen Zusatzrechten, alle Sprachen mit Schreibrecht,
     * alle Status.
     * Bei den übrigen Gruppen zählen nur Bereiche zugewiesener Instanzen; Rechte
     * auf Bereiche, deren Instanz der Gruppe nicht (mehr) zugewiesen ist, fallen
     * weg.
     *
     * @param array $groupRows          readGroups()
     * @param int[] $instanceIds        alle Instanzen in Backend-Reihenfolge
     * @param array $primaryLanguages   instance_id => primary_language_id
     * @param array $allAreas           area_id => ['name', 'type', 'instance_id']
     * @param int[] $languageIds        alle Sprachen
     * @param int[] $statusIds          alle Status (Reihenfolge wie `position`)
     * @return array  group_id => Datei-Inhalt
     */
    private function buildGroups(array $groupRows, array $instanceIds, array $primaryLanguages, array $allAreas, array $languageIds, array $statusIds = []): array
    {
        // Module aus var/config/components/modules.php - Cms hält sie; der Container
        // liefert dieselbe Instanz, ein Zirkelbezug entsteht nicht (Cms braucht
        // InstanceCache nur zur Laufzeit)
        $modules = [];
        $app = $GLOBALS['app'] ?? null;
        if ($app instanceof ContainerInterface) {
            foreach ((array)$app->get(Cms::class)->modules as $name => $settings) {
                $modules[(string)$name] = array_map('strval', array_keys((array)($settings['access'] ?? [])));
            }
        }

        $groups = [];
        foreach ($groupRows as $groupId => $row) {
            $isSuperuser = ($groupId === self::SUPERUSER_GROUP_ID);
            $file = [
                'group_id'          => $groupId,
                'superuser'         => $isSuperuser,
                'label'             => $row['label'],
                'instance_ids'      => [],
                'primary_languages' => [],
                'areas'             => [],
                'modules'           => [],
                'interfaces'        => $isSuperuser
                    ? Access::INTERFACES
                    : Access::normalizeInterfaces(isset($row['details']['interfaces']) ? (array)$row['details']['interfaces'] : null),
                'rights'            => ['areas' => [], 'languages' => [], 'statuses' => []],
            ];
            if ($isSuperuser) {
                $file['instance_ids'] = $instanceIds;
                $file['primary_languages'] = $primaryLanguages;
                $file['areas'] = $allAreas;
                $file['modules'] = $modules;
                foreach ($allAreas as $areaId => $info) {
                    if ($info['type'] === 'folder') continue;
                    $file['rights']['areas'][$areaId] = [
                        'group_write' => true, 'group_delete' => true,
                        'other_write' => true, 'other_delete' => true, 'set_owner' => true,
                    ];
                }
                foreach ($languageIds as $languageId) {
                    $file['rights']['languages'][$languageId] = true;
                }
                $file['rights']['statuses'] = $statusIds;
                $groups[$groupId] = $file;
                continue;
            }

            // Instanzen in Backend-Reihenfolge
            $assigned = array_values(array_intersect($instanceIds, $row['instance_ids']));
            $file['instance_ids'] = $assigned;
            foreach ($assigned as $instanceId) {
                $file['primary_languages'][$instanceId] = (int)($primaryLanguages[$instanceId] ?? 0);
            }
            // Lesbare Bereiche (Reihenfolge wie allAreas) und ihre Flags
            foreach ($allAreas as $areaId => $info) {
                if (!isset($row['areas'][$areaId])) continue;
                if (!in_array($info['instance_id'], $assigned, true)) continue;
                if ($info['type'] === 'folder') continue;
                $file['areas'][$areaId] = $info;
                $file['rights']['areas'][$areaId] = $row['areas'][$areaId];
            }
            // Module: nur bekannte, Zusatzrechte nur definierte
            foreach ((array)($row['details']['modules'] ?? []) as $name => $rights) {
                if (!isset($modules[$name])) continue;
                $file['modules'][$name] = array_values(array_intersect($modules[$name], array_map('strval', (array)$rights)));
            }
            // Sprachen: nur vorhandene
            foreach ($row['languages'] as $languageId => $canWrite) {
                if (!in_array($languageId, $languageIds, true)) continue;
                $file['rights']['languages'][$languageId] = (bool)$canWrite;
            }
            // Setzbare Status: nur vorhandene, Reihenfolge wie `position`
            $file['rights']['statuses'] = array_values(array_intersect($statusIds, $row['statuses']));
            $groups[$groupId] = $file;
        }
        return $groups;
    }

    // ====================
    // Hilfsfunktionen
    // ====================

    private function cacheDir(): string
    {
        return $this->config->get('paths.var_dir') . '/cache';
    }

    private function instanceFile(int $instanceId): string
    {
        return $this->cacheDir() . '/instance-' . $instanceId . '.php';
    }

    private function groupFile(int $groupId): string
    {
        return $this->cacheDir() . '/group-' . $groupId . '.php';
    }

    /**
     * Cache-Datei einbinden.
     *
     * @return array|null  null, wenn die Datei fehlt oder kein Array liefert
     */
    private function includeFile(string $file): ?array
    {
        if (!is_file($file)) return null;
        $data = @include $file;
        return is_array($data) ? $data : null;
    }

    /**
     * Array als PHP-Datei schreiben - erst temporär, dann per rename() an den Platz.
     */
    private function writeFile(string $file, array $data): void
    {
        $content = "<?php\n// Generiert von " . self::class . " am " . gmdate('Y-m-d H:i:s') . " UTC - nicht von Hand ändern.\n"
                 . "return " . var_export($data, true) . ";\n";
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \Exception("InstanceCache: could not write \"{$tmp}\".");
        }
        @chmod($tmp, $this->config->get('permissions.file') ?? 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \Exception("InstanceCache: could not rename \"{$tmp}\" to \"{$file}\".");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * Aufgabe unter der Sperrdatei ausführen (blockierend).
     */
    private function withLock(callable $task): void
    {
        $this->system->createDir($this->config->get('paths.var_dir'), 'cache');
        $lockFile = $this->cacheDir() . '/' . self::FILE_LOCK;
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            // Ohne Sperrdatei trotzdem arbeiten - besser ein doppelter Aufbau als keiner
            $task();
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $task();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Sprachkürzel vergleichbar machen: klein, '_' -> '-'.
     */
    private function normalizeCode(string $code): string
    {
        return str_replace('_', '-', mb_strtolower(trim($code)));
    }
}
