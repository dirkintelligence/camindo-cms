<?php
/**
 * Rechte der angemeldeten Benutzergruppe im Backend.
 *
 * WOHER DIE RECHTE KOMMEN
 * --------------------
 * Session::create()/load() ruft load($groupId); die Klasse liest dann die
 * Gruppendatei var/cache/group-{group_id}.php (InstanceCache::group()) und
 * beantwortet alle Fragen aus diesem Array - ohne weitere Datenbankabfragen. Nur
 * groupOf() fragt `admin` (einmal je Owner und Request), um die Gruppe des
 * Besitzers eines Datensatzes zu erfahren.
 *
 * Was die Gruppendatei enthält, steht in InstanceCache ("Gruppendateien"); das
 * Rechtemodell selbst in docs/developers/12-group-rights.md:
 *
 *     Modul        module(), moduleRight()     Zugriff ja/nein, Zusatzrechte aus
 *                                              {Modul}/install/settings.php 'access'
 *     Instanz      instance(), instanceIds()   zugewiesene Instanzen
 *     Bereich      areaRead()                  Zeile in rel_group_area = Leserecht
 *                  areaWrite()/areaDelete()    je nachdem, ob der Owner des
 *                                              Datensatzes zur eigenen Gruppe gehört
 *                                              (group_*) oder nicht (other_*)
 *                  areaCreate()                Anlegen = Schreiben mit sich selbst
 *                                              als Owner
 *                  areaSetOwner()              Zusatzrecht "Owner anpassen"
 *                  templateRead()/-Write()     Template-Bereiche (nur group_write)
 *     Sprache      languageRead()/-Write()     für die Inhalte im Modul Record
 *     Status       statusWrite()               welche Status die Gruppe einem
 *                                              Datensatz geben darf (lesen: alle);
 *                                              ein Datensatz in einem nicht setzbaren
 *                                              Status ist für sie nicht änderbar
 *                                              (recordWrite())
 *     Oberfläche   interface()                 über welche Oberfläche sich Admins
 *                                              der Gruppe anmelden dürfen (INTERFACES)
 *
 * OBERFLÄCHEN
 * --------------------
 * Dieselben Rechte gelten auf jeder Oberfläche - dem Backend, der Kommandozeile
 * (`camindo.php api`) und der PHP-API (Camindo\Core\Api). Welche davon eine Gruppe
 * benutzen darf, steht in `group`.`details`.`interfaces`; ohne Angabe nur das
 * Backend (DEFAULT_INTERFACES). Geprüft wird bei der Anmeldung
 * (Admin::loginByName(), ApiSession) und bei jedem Laden einer Session
 * (Session::load()) - ein entzogenes Recht beendet laufende Sessions.
 *
 * UNGELADEN = KEINE PRÜFUNG
 * --------------------
 * Ohne Session (Frontend, Cron) bleibt die Klasse ungeladen und verweigert nichts:
 * das Frontend hat sein eigenes Rechtemodell (RecordUser), Hintergrundprozesse
 * laufen als System (Session::setCliAdmin() lädt bewusst keine Rechte - siehe
 * dort). Die Superuser-Gruppe (1) bekommt auf jede Frage ein Ja.
 *
 * Schreibrecht setzt Leserecht voraus; ein Bereich ohne Zeile ist damit auch
 * nicht beschreibbar, unabhängig von den Flags.
 *
 * @see Camindo\Core\InstanceCache::group()   Aufbau der Gruppendatei
 * @see Camindo\Core\Session                  lädt die Rechte je Request
 * @see Camindo\Core\Backend::isSuperuser()
 */
namespace Camindo\Core;

class Access {

    /** Oberflächen, für die eine Gruppe freigeschaltet werden kann */
    public const INTERFACES = ['backend', 'cli', 'php'];

    /** Oberflächen einer Gruppe ohne Angabe in details.interfaces */
    public const DEFAULT_INTERFACES = ['backend'];

    /** Inhalt der Gruppendatei; null = ungeladen */
    private ?array $group = null;
    /** admin_id => group_id, je Request gemerkt (groupOf()) */
    private array $groupOfAdmin = [];
    /** admin_id der Session (für areaCreate()) */
    private int $adminId = 0;

    public function __construct(
        private Database $db,
        private InstanceCache $instanceCache,
        private ContainerInterface $container,
    ) {}

    // ====================
    // Laden

    /**
     * Rechte einer Gruppe laden (Session).
     *
     * @param int $groupId  group_id der angemeldeten Gruppe
     * @param int $adminId  admin_id der Session (Owner neuer Datensätze)
     * @return bool         false, wenn es keine Gruppendatei gibt
     */
    public function load(int $groupId, int $adminId = 0): bool
    {
        $this->group = $this->instanceCache->group($groupId);
        $this->adminId = $adminId;
        $this->groupOfAdmin = [];
        if ($adminId > 0 && $groupId > 0) $this->groupOfAdmin[$adminId] = $groupId;
        return $this->group !== null;
    }

    /**
     * Rechte verwerfen (Abmeldung, Wechsel der Session).
     */
    public function unload(): void
    {
        $this->group = null;
        $this->adminId = 0;
        $this->groupOfAdmin = [];
    }

    public function isLoaded(): bool
    {
        return $this->group !== null;
    }

    /**
     * Superuser-Gruppe? Ungeladen: false (es gibt keine Gruppe, die alles dürfte -
     * verweigert wird trotzdem nichts, siehe Klassenkommentar).
     */
    public function isSuperuser(): bool
    {
        return !empty($this->group['superuser']);
    }

    public function groupId(): int
    {
        return (int)($this->group['group_id'] ?? 0);
    }

    // ====================
    // Oberflächen

    /**
     * Darf die geladene Gruppe diese Oberfläche benutzen? Anders als die übrigen
     * Fragen ungeladen ein Nein: ohne Gruppe gibt es keine Anmeldung.
     *
     * @param string $interface  eine aus INTERFACES
     */
    public function interface(string $interface): bool
    {
        if ($this->group === null) return false;
        return in_array($interface, (array)($this->group['interfaces'] ?? []), true);
    }

    /**
     * Oberflächen der geladenen Gruppe. Ungeladen: leer.
     *
     * @return string[]
     */
    public function interfaces(): array
    {
        return array_values((array)($this->group['interfaces'] ?? []));
    }

    /**
     * Darf eine Gruppe diese Oberfläche benutzen - ohne sie zu laden (Anmeldung,
     * bevor es eine Session gibt).
     */
    public function groupInterface(int $groupId, string $interface): bool
    {
        $group = $this->instanceCache->group($groupId);
        return $group !== null && in_array($interface, (array)($group['interfaces'] ?? []), true);
    }

    /**
     * Oberflächen aus `group`.`details` bereinigen: nur bekannte, in der
     * Reihenfolge von INTERFACES; fehlt die Angabe, DEFAULT_INTERFACES.
     *
     * @param array|null $interfaces  details.interfaces (null = nicht gesetzt)
     * @return string[]
     */
    public static function normalizeInterfaces(?array $interfaces): array
    {
        if ($interfaces === null) return self::DEFAULT_INTERFACES;
        $interfaces = array_map('strval', $interfaces);
        return array_values(array_filter(self::INTERFACES, fn($name) => in_array($name, $interfaces, true)));
    }

    // ====================
    // Module

    /**
     * Zugriff auf ein Modul?
     *
     * Module, die nicht in Cms::$modules stehen oder dort nicht 'listed' sind
     * (Start, Messaging), stehen jeder Gruppe offen - sie sind Teil der Oberfläche,
     * nicht der Rechteverwaltung.
     *
     * @param string $module  Modulname wie in Cms::$modules ('Record', 'Tools' ...)
     */
    public function module(string $module): bool
    {
        // Superuser-Modul: der Gruppe 1 vorbehalten, auch wenn eine andere Gruppe
        // es zugewiesen bekommen hat (settings.php 'superuser', z. B. Logfiles).
        // Ungeladen bleibt es wie überall bei "ja" - das Modul selbst prüft
        // zusätzlich mit Backend::isSuperuser(), das auch im CLI antwortet.
        if ($this->isSuperuserModule($module) && !$this->allowsAll()) return false;

        if ($this->allowsAll()) return true;
        if (!$this->isManagedModule($module)) return true;
        return array_key_exists($module, (array)($this->group['modules'] ?? []));
    }

    /**
     * Unterliegt das Modul der Rechteverwaltung (in Cms::$modules und 'listed')?
     */
    public function isManagedModule(string $module): bool
    {
        $settings = $this->container->get(Cms::class)->modules[$module] ?? null;
        return $settings !== null && !empty($settings['listed']);
    }

    /**
     * Ist das Modul in seiner settings.php als Superuser-Modul gekennzeichnet?
     */
    public function isSuperuserModule(string $module): bool
    {
        $settings = $this->container->get(Cms::class)->modules[$module] ?? null;
        return $settings !== null && !empty($settings['superuser']);
    }

    /**
     * Zusatzrecht in einem Modul (settings.php 'access')?
     *
     * @param string $module  Modulname
     * @param string $right   interner Name des Rechts, z. B. 'developer'
     */
    public function moduleRight(string $module, string $right): bool
    {
        if ($this->allowsAll()) return true;
        $rights = $this->group['modules'][$module] ?? null;
        if ($rights === null) return false;
        return in_array($right, (array)$rights, true);
    }

    /**
     * Module, auf die die Gruppe Zugriff hat: Name => gewährte Zusatzrechte.
     * Ungeladen: leer (der Aufrufer muss selbst entscheiden, ob "alles" gilt).
     *
     * @return array  Modulname => string[]
     */
    public function modules(): array
    {
        return (array)($this->group['modules'] ?? []);
    }

    // ====================
    // Instanzen

    /**
     * Zugewiesene Instanzen in Backend-Reihenfolge. Ungeladen: leer.
     *
     * @return int[]
     */
    public function instanceIds(): array
    {
        return array_map('intval', (array)($this->group['instance_ids'] ?? []));
    }

    public function instance(int $instanceId): bool
    {
        if ($this->allowsAll()) return true;
        return in_array($instanceId, $this->instanceIds(), true);
    }

    // ====================
    // Bereiche

    /**
     * Leserecht auf einen Bereich (jeder Typ außer Ordner)?
     */
    public function areaRead(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return isset($this->group['areas'][$areaId]);
    }

    /**
     * Lesbare Bereiche, wahlweise nur bestimmter Typen, in Baumreihenfolge.
     * Ungeladen: leer.
     *
     * @param string[] $types  z. B. Area::CONTENT_TYPES; leer = alle
     * @return int[]
     */
    public function readableAreaIds(array $types = []): array
    {
        $ids = [];
        foreach ((array)($this->group['areas'] ?? []) as $areaId => $info) {
            if (!empty($types) && !in_array((string)($info['type'] ?? ''), $types, true)) continue;
            $ids[] = (int)$areaId;
        }
        return $ids;
    }

    /**
     * Lesbare Bereiche als Filter für Abfragen und Bäume: null, wenn nichts
     * einzuschränken ist (Superuser, ungeladen), sonst die Ids (ggf. leer).
     *
     * @param string[] $types  wie readableAreaIds()
     * @return int[]|null
     */
    public function areaFilter(array $types = []): ?array
    {
        if ($this->allowsAll()) return null;
        return $this->readableAreaIds($types);
    }

    /**
     * Bereiche, in denen die Gruppe Datensätze anlegen darf (Leserecht und
     * group_write) - für Zielbereichslisten (Verschieben, Import); null = keine
     * Einschränkung.
     *
     * @param string[] $types  wie readableAreaIds()
     * @return int[]|null
     */
    public function creatableAreaFilter(array $types = []): ?array
    {
        if ($this->allowsAll()) return null;
        $ids = [];
        foreach ($this->readableAreaIds($types) as $areaId) {
            if (!empty($this->group['rights']['areas'][$areaId]['group_write'])) $ids[] = $areaId;
        }
        return $ids;
    }

    /**
     * Zugewiesene Instanzen als Filter: null = keine Einschränkung.
     *
     * @return int[]|null
     */
    public function instanceFilter(): ?array
    {
        if ($this->allowsAll()) return null;
        return $this->instanceIds();
    }

    /**
     * Schreibrecht auf einen Datensatz in einem Bereich.
     *
     * @param int      $areaId
     * @param int|null $ownerId  owner_id des Datensatzes; null/0 = kein Owner
     *                           (zählt als fremd)
     */
    public function areaWrite(int $areaId, ?int $ownerId): bool
    {
        return $this->areaFlag($areaId, $ownerId, 'write');
    }

    /**
     * Löschrecht auf einen Datensatz in einem Bereich (Parameter wie areaWrite()).
     */
    public function areaDelete(int $areaId, ?int $ownerId): bool
    {
        return $this->areaFlag($areaId, $ownerId, 'delete');
    }

    /**
     * Darf die Gruppe in dem Bereich Datensätze anlegen? Der Owner eines neuen
     * Datensatzes ist der Session-Admin, das Recht also group_write.
     */
    public function areaCreate(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['group_write']);
    }

    /**
     * Zusatzrecht "Owner anpassen" in dem Bereich?
     */
    public function areaSetOwner(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['set_owner']);
    }

    /**
     * Leserecht auf einen Template-Bereich.
     */
    public function templateRead(int $areaId): bool
    {
        return $this->areaRead($areaId);
    }

    /**
     * Schreibrecht auf einen Template-Bereich (Flag group_write; Templates haben
     * keinen Owner).
     */
    public function templateWrite(int $areaId): bool
    {
        if ($this->allowsAll()) return true;
        return $this->areaRead($areaId) && !empty($this->group['rights']['areas'][$areaId]['group_write']);
    }

    /**
     * Rechte-Flags eines Bereichs, wie sie in der Gruppendatei stehen; leer, wenn
     * kein Leserecht besteht. Für die Anzeige (Gruppen-Info, Reiter "Zugriff").
     *
     * @return array  group_write, group_delete, other_write, other_delete, set_owner (bool)
     */
    public function areaFlags(int $areaId): array
    {
        return (array)($this->group['rights']['areas'][$areaId] ?? []);
    }

    /**
     * write/delete je nach Gruppe des Owners.
     */
    private function areaFlag(int $areaId, ?int $ownerId, string $action): bool
    {
        if ($this->allowsAll()) return true;
        if (!$this->areaRead($areaId)) return false;
        $flags = (array)($this->group['rights']['areas'][$areaId] ?? []);
        $ownerId = (int)$ownerId;
        $isOwnGroup = $ownerId > 0 && $this->groupOf($ownerId) === $this->groupId();
        $key = ($isOwnGroup ? 'group_' : 'other_') . $action;
        return !empty($flags[$key]);
    }

    /**
     * Gruppe eines Admins (Owner eines Datensatzes), je Request gemerkt.
     *
     * @return int  group_id; 0, wenn es den Admin nicht gibt
     */
    public function groupOf(int $adminId): int
    {
        if ($adminId < 1) return 0;
        if (array_key_exists($adminId, $this->groupOfAdmin)) return $this->groupOfAdmin[$adminId];
        $this->db->pushResult();
        $this->db->query("SELECT `group_id` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
        $groupId = (int)($this->db->fetchNum()[0] ?? 0);
        $this->db->popResult();
        return $this->groupOfAdmin[$adminId] = $groupId;
    }

    // ====================
    // Sprachen

    /**
     * Leserecht auf eine Sprache (Inhalte im Modul Record)?
     */
    public function languageRead(int $languageId): bool
    {
        if ($this->allowsAll()) return true;
        return array_key_exists($languageId, (array)($this->group['rights']['languages'] ?? []));
    }

    /**
     * Schreibrecht auf eine Sprache?
     */
    public function languageWrite(int $languageId): bool
    {
        if ($this->allowsAll()) return true;
        return !empty($this->group['rights']['languages'][$languageId]);
    }

    /**
     * Lesbare Sprachen (einschließlich der schreibbaren). Ungeladen: leer.
     *
     * @return int[]
     */
    public function readableLanguageIds(): array
    {
        return array_map('intval', array_keys((array)($this->group['rights']['languages'] ?? [])));
    }

    /**
     * Schreibbare Sprachen. Ungeladen: leer.
     *
     * @return int[]
     */
    public function writableLanguageIds(): array
    {
        $ids = [];
        foreach ((array)($this->group['rights']['languages'] ?? []) as $languageId => $canWrite) {
            if ($canWrite) $ids[] = (int)$languageId;
        }
        return $ids;
    }

    // ====================
    // Status

    /**
     * Darf die Gruppe diesen Status setzen (rel_group_status)? Lesen darf jede
     * Gruppe alle Status - eingeschränkt wird nur das Setzen.
     */
    public function statusWrite(int $statusId): bool
    {
        if ($this->allowsAll()) return true;
        return in_array($statusId, (array)($this->group['rights']['statuses'] ?? []), true);
    }

    /**
     * Setzbare Status (Reihenfolge wie `status`.`position`). Ungeladen: leer.
     *
     * @return int[]
     */
    public function settableStatusIds(): array
    {
        return array_map('intval', (array)($this->group['rights']['statuses'] ?? []));
    }

    /**
     * Darf die Gruppe einen Datensatz ändern? Schreibrecht im Bereich (areaWrite())
     * UND das Recht, seinen aktuellen Status zu setzen - wer einen Status nicht
     * vergeben darf, bearbeitet auch keinen Datensatz, der ihn schon trägt (flacher
     * Workflow: ein freigeschalteter Datensatz bleibt für die Redaktion gesperrt).
     *
     * @param int      $areaId
     * @param int|null $ownerId   Owner des Datensatzes (siehe areaWrite())
     * @param int      $statusId  record.status_id
     */
    public function recordWrite(int $areaId, ?int $ownerId, int $statusId): bool
    {
        return $this->areaWrite($areaId, $ownerId) && $this->statusWrite($statusId);
    }

    // ====================
    // Verweigern

    /**
     * Darf ein Backend-Script eines Moduls aufgerufen werden? (Backend::backendPath())
     *
     *     Modul erlaubt    -> ja, sofern das Script kein Zusatzrecht verlangt, das
     *                         fehlt (settings.php 'script_access': Script => Recht,
     *                         z. B. die Reiter des Moduls Tools)
     *     Modul verweigert -> ja nur für ein Script aus settings.php 'shared' im
     *                         Lookup-Aufruf: Auswahllisten und Vorschaublöcke, die
     *                         andere Module in ihren Formularen brauchen (Admin-Lookup
     *                         im Modul Messaging, Datensatz-/Template-Lookup im Modul
     *                         Instance); Lookup-Aufruf = Request-Parameter src_field
     *                      -> ja für ein Script aus settings.php 'personal': die
     *                         eigenen Einstellungen jedes Admins (Admin/settings.php,
     *                         Admin/totp.php) - das Script beschränkt Nicht-Superuser
     *                         selbst auf das eigene Konto
     *
     * @param string $module  Modulname aus dem Pfad ('Admin')
     * @param string $script  Dateiname des Scripts ('admin_list.php')
     */
    public function script(string $module, string $script): bool
    {
        $settings = (array)($this->container->get(Cms::class)->modules[$module] ?? []);
        if ($this->module($module)) {
            $right = (string)($settings['script_access'][$script] ?? '');
            return $right === '' || $this->moduleRight($module, $right);
        }
        if (in_array($script, (array)($settings['personal'] ?? []), true)) return true;
        if (!in_array($script, (array)($settings['shared'] ?? []), true)) return false;
        return trim((string)($_REQUEST['src_field'] ?? '')) !== '';
    }

    /**
     * Backend: Meldung "Zugriff verweigert" ausgeben und abbrechen.
     *
     * Für die Einstiegsscripte der Module ({Modul}/backend/*.php): sie laufen im
     * Layer, eine Meldung im Layer ist deshalb der richtige Ort - nicht
     * '##accessdenied##', das die Anmeldung öffnen würde.
     */
    public function denied(): never
    {
        echo $this->deniedHtml();
        exit;
    }

    /**
     * Die Meldung "Zugriff verweigert" als HTML - für Methoden, die die Seite
     * regulär beenden wollen (container_footer, return).
     */
    public function deniedHtml(): string
    {
        return $this->container->get(Backend::class)->template('alert_danger', [
            '{text}' => $this->container->get(Cms::class)->snippet('error_access_denied'),
        ]);
    }

    // ====================

    /**
     * Ungeladen oder Superuser: jede Frage wird mit Ja beantwortet.
     */
    private function allowsAll(): bool
    {
        return $this->group === null || !empty($this->group['superuser']);
    }
}
