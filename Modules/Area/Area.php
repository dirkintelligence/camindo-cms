<?php
/**
 * Dataset-Kindklasse für die Tabelle `area` - die Inhaltsbereiche.
 *
 * Ein Inhaltsbereich ist der Container, der festlegt, WELCHE Eingabefelder seine
 * Datensätze haben. Der Redakteur baut sich sein Datenmodell damit selbst zusammen:
 * Bereich anlegen, Felder definieren (Tabelle `field`), Datensätze einpflegen
 * (Tabelle `record`).
 *
 * FÜNF ARTEN VON BEREICHEN (Spalte `type`)
 * --------------------
 *     record     Bereich für Inhaltsdatensätze - der Normalfall
 *     profile    Inhaltsdatensätze, die User-Profile der Frontend-Community sind:
 *                ein Datensatz je User; login_name_field_id und
 *                login_password_field_id nennen die beiden Eingabefelder,
 *                mit denen sich der User an der Website anmeldet (CMS:USER_LOGIN)
 *     ugc        Inhaltsdatensätze, die User im Frontend anlegen ("user generated
 *                content", CMS:USER_INPUT): details.profile_area_ids sagt, aus
 *                welchen Profil-Bereichen die Autoren stammen dürfen
 *     template   Bereich für Frontend-Templates
 *     folder     reiner Ordner zum Gliedern der Baumnavigation, ohne eigene Inhalte
 *
 * record, profile und ugc sind für Eingabemaske, Listen, Suche und Cms::select()
 * dasselbe: Datensatzbereiche (CONTENT_TYPES). Wo bisher auf 'record' geprüft
 * wurde, gilt jetzt isContentType().
 *
 * BAUMSTRUKTUR
 * --------------------
 * Bereiche bilden einen Baum. Über TreeManagerTrait werden parallel Nested Set
 * (lft/rgt) und Adjacency List (parent_id/position/depth/parent_ids) geführt -
 * daher die Standardsortierung 'lft ASC', die den Baum in Anzeigereihenfolge liefert.
 *
 * NAME
 * --------------------
 * `name` (Pflichtfeld, Kleinbuchstaben/Ziffern/Unterstrich, eindeutig innerhalb
 * der Instanz und ihrer lesbaren Instanzen - siehe InstanceCache::uniqueScope()) ist
 * der Bezeichner, über den Templates den Bereich ansprechen: CMS:SELECT übersetzt
 * filter[area]=artikel und sort=area über den Instanz-Dateicache in area_id. Damit
 * die Übersetzung stimmt, schreiben hookOnSaved() und hookOnDeleted() den Cache neu
 * (InstanceCache::rebuild()) - genau wie die Module Instance und Language.
 *
 * VORGABEN FÜR DIE DATENSÄTZE DES BEREICHS
 * --------------------
 *     default_field_id      welches Feld als "Titel" gilt. Sein Inhalt landet
 *                           zusätzlich in data_dft und ist über den Pseudo-Feldnamen
 *                           _default filter- und sortierbar.
 *     default_template_id   Template, wenn der Datensatz keins gewählt hat
 *     list_template_id      Template, das die Datensatzliste im Backend um eine
 *                           Spalte ergänzt (RecordBackendList) und die ausführliche
 *                           Lookup-Vorschau abschließt (RecordBackendEdit) - je
 *                           Datensatz mit dessen Inhalten als $cms ausgeführt
 *     trigger_template_ids  Templates mit Ereignis-Methoden (onCreate, onSave ...),
 *                           die beim Anlegen, Laden, Speichern, Löschen und
 *                           Wiederherstellen der Datensätze nacheinander laufen
 *                           (TriggerTemplates) - Tabelle `rel_area_trigger` mit
 *                           `position`, kein Feld der Tabelle `area`
 *     overview_template_id  Übersichts-Template: die Adresse, die nur aus dem
 *                           Slug-Präfix des Bereichs besteht (/de/modelle), zeigt
 *                           dieses Template ohne Datensatz (Page::resolveRequest(),
 *                           Cms::areaUrl()) - ohne Präfix oder Template: 404
 *     sort_field_id/-order  Standardsortierung der Datensatzliste
 *
 * Jeder Datensatzbereich hat den Volltext `fulltext` über alle Text- und Upload-Felder -
 * ohne eigenes Eingabefeld (VirtualFieldsTrait::refreshStandardFulltext()).
 *
 * LÖSCHEN IN ZWEI STUFEN
 * --------------------
 * Soft-Delete markiert Bereich UND Datensätze mit demselben changed_at-Zeitstempel
 * (hookOnSoftDelete); hookOnRestore macht genau diesen einen Vorgang wieder rückgängig
 * und lässt frühere Löschvorgänge in Ruhe. Endgültig gelöscht wird der Bereich samt
 * seiner Datensätze erst durch das Systemtool (hookOnDelete).
 *
 * Alle drei Hooks schicken die Datensätze EINZELN durch die passende Record-Funktion
 * (softDelete / restoreOne / finalDelete), damit je Datensatz dessen eigene Hooks
 * laufen - insbesondere das Verschieben der Mediendateien in den Papierkorb und zurück.
 * Massen-UPDATEs über area_id gibt es hier bewusst nicht mehr.
 *
 * SLUG-PRÄFIX (details.slug_prefix)
 * --------------------
 * Ein Datensatzbereich kann den URLs seiner Datensätze einen Präfix voranstellen:
 * Bereich Farben mit "farben", Datensatz mit dem Slug "blau" -> /farben/blau. Damit
 * dürfen gleichnamige Slugs in verschiedenen Bereichen vorkommen (/farben/rot und
 * /rot). Der Präfix ist mehrsprachig (language_id => Präfix, Fallback auf die
 * Hauptsprache der Instanz), ein einzelner Pfadteil ohne Slash. Ordner und
 * Template-Bereiche haben keinen.
 *
 * Innerhalb der Instanz und ihrer zusammengeschalteten Instanzen (InstanceCache::
 * uniqueScope()) gilt je Sprache (slugPrefixErrors()):
 *     - kein anderer Bereich führt denselben Präfix
 *     - kein Datensatz eines Bereichs OHNE Präfix hat einen Slug, dessen erster
 *       Pfadteil der neue Präfix ist ("farben" oder "farben/gelb")
 *     - fällt der Präfix weg, darf keiner der eigenen Datensätze danach denselben
 *       Pfad haben wie ein Datensatz eines Bereichs ohne Präfix oder mit dem Präfix
 *       eines anderen Bereichs beginnen
 * Die Gegenrichtung (ein Datensatz bekommt einen Slug, der mit einem Präfix
 * beginnt) prüft Record::slugPathOk(). Aufgelöst werden die Pfade in
 * Cms::recordIdsBySlugPath(), erzeugt in Cms::recordUrl(); der Instanz-Dateicache
 * führt die Präfixe je Sprache (InstanceCache::slugPrefix()).
 *
 * BEREICHSRECHTE (rel_group_area, docs/developers/12-group-rights.md)
 * --------------------
 * Welche Benutzergruppe einen Bereich lesen darf und was sie mit seinen Datensätzen
 * tun darf, steht in `rel_group_area` (Zeile = Leserecht + Flags). Gepflegt wird das
 * im Reiter "Zugriff" des Bereichs (AreaBackend::infoAccess() -> saveAccess(): alle
 * Gruppen, ein Bereich) und in der Gruppe (GroupBackend::access(): alle Bereiche,
 * eine Gruppe). Ein NEUER Bereich bekommt seine Zeilen in hookOnSaved(): für die im
 * Formular gewählten Gruppen (Feld access_group_ids) - fehlt das Feld (Import,
 * Kopie, CLI), für alle Gruppen, denen die Instanz zugewiesen ist. Nur Gruppen mit
 * der Instanz des Bereichs bekommen eine Zeile; Ordner haben keine Rechte.
 * Eine Kopie übernimmt die Rechte des Originals (copyAccess()).
 *
 * @see Camindo\Core\Modules\Area\Field         Eingabefelder des Bereichs
 * @see Camindo\Core\Modules\Area\AreaBackend   Backend-Oberfläche
 * @see Camindo\Core\TreeManagerTrait            Baumverwaltung
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Modules\Record\Record;
use Camindo\Core\Modules\Record\UserSecondFactor;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\Integrity;
use Camindo\Core\MediaAccess;
use Camindo\Core\TreeManagerTrait;
use Camindo\Core\Modules\Template\TemplateFiles;

/**
 * @property-read Field $field  Nachgeladen, siehe $lazyServices
 */
class Area extends Dataset {
    
    use TreeManagerTrait;

    /** Bereichstypen, die Datensätze (`record`) enthalten - siehe Klassenkommentar */
    public const CONTENT_TYPES = ['record', 'profile', 'ugc'];

    /** Standard-Icon je Bereichstyp (hookOnSave()) */
    public const DEFAULT_ICONS = [
        'record'   => 'draft',
        'profile'  => 'face',
        'ugc'      => 'chat_bubble',
        'template' => 'extension',
        'folder'   => 'folder',
    ];

    /**
     * Ist es ein Bereichstyp mit Datensätzen (record, profile, ugc)?
     */
    public static function isContentType(string $type): bool
    {
        return in_array($type, self::CONTENT_TYPES, true);
    }

    /**
     * Nachladbare Dienste - hookOnDelete() räumt über Field::cleanupFieldname() die
     * nicht mehr belegten Feldnamen ab. Ohne diesen Eintrag liefe der Zugriff
     * $this->field in die Ausnahme von LazyServicesTrait.
     */
    protected array $lazyServices = [
        'field' => Field::class,
    ];

    protected function hookDefinition(): void
    {
        $this->tableName  = 'area';
        $this->moduleName = 'Area';
        $this->defaultSortOrder = 'instance ASC, lft ASC';
        $this->schema = [
            'area_id' => [
                'type'  => 'integer',
                'label' => 'area_id',
            ],
            // Zu welcher Website gehört dieser Container?
            // Gilt für alle drei Typen (record, template, folder). Die Inhalte des
            // Containers erben die Zuordnung: hookOnSaved() trägt sie in die
            // `record`-Zeilen des Bereichs nach, die Templates hängen über
            // fk_template_area_id ohnehin am Bereich.
            // 0 ist die Standard-Instance und immer vorhanden.
            'instance_id' => [
                'type'  => 'integer', // FK auf `instance`, NOT NULL -> nicht 'id'
                'label' => '#area.field_area_instance_id',
                'tooltip' => '#area.tooltip_field_area_instance_id',
                'display_type' => 'select',
                'min'   => 0,
            ],
            'type' => [
                'type' => 'chars', // enum
                'label' => '#area.field_area_type',
                'display_type' => 'select',
                'options' => ['record' => 'record', 'profile' => 'profile', 'ugc' => 'ugc', 'template' => 'template', 'folder' => 'folder'],
                'flags' => ['not_empty'],
            ],
            // Bezeichner für Templates (CMS:SELECT filter[area]=..., sort=area),
            // siehe Klassenkommentar "Name". Spalte: char(32).
            'name' => [
                'type'  => 'chars',
                'label' => '#area.field_area_name',
                'max'   => 32,
                'flags' => ['trim', 'not_empty', 'name', 'unique', 'ft'],
            ],
            'icon' => [
                'type'  => 'chars',
                'label' => '#area.field_area_icon',
                'max'   => 64,
                'flags' => ['trim', 'name'],
                'extension' => 'IconPicker',   // Core-Extension: Material-Icon auswählen
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#area.field_area_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty', 'ft'],
            ],
            'details' => [
                'type'  => 'json',
                'label' => '#area.field_area_details',
            ],
            // Eingabemaske bei mehrsprachigen Feldern (nur type 'record'):
            //     0  alle Sprachen untereinander auf einer Seite
            //     1  ein Reiter je Sprache (plus "Alle")
            // Ohne weitere Sprachen in der Instanz ohne Wirkung.
            'details.tabs' => [
                'type'  => 'bit',
                'display_type' => 'radio',
                'label' => '#area.field_area_tabs',
                'tooltip' => '#area.tooltip_field_area_tabs',
            ],
            // Slug-Präfix (nur Datensatzbereiche): steht in der URL vor dem Slug der
            // Datensätze - /farben/blau statt /blau. Je Sprache der Instanz
            // (language_id => Präfix, leer = der der Hauptsprache), ohne Slash.
            // Siehe Klassenkommentar "Slug-Präfix".
            'details.slug_prefix' => [
                'type'  => 'json',
                'label' => '#area.field_area_slug_prefix',
                'tooltip' => '#area.tooltip_field_area_slug_prefix',
            ],
            // Abhängiger Bereich (nur Datensatzbereiche): seine Datensätze gehören
            // exklusiv je einem Quelldatensatz, der sie über ein Feld vom Typ 'link'
            // hält (Camindo\Core\LinkTrait). Nur solche Bereiche sind als
            // Zielbereich eines 'link'-Feldes wählbar. Der Bereich fehlt in der
            // Bereichsliste des Moduls Record (RecordBackendFolderList); seine
            // Datensätze erreicht man über die Eingabemaske des Quelldatensatzes
            // bzw. den Reiter "Datensätze" der Bereichs-Info.
            'dependent' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#area.field_area_dependent',
                'tooltip' => '#area.tooltip_field_area_dependent',
            ],
            // Nur beim Anlegen: Benutzergruppen, die den neuen Bereich lesen dürfen
            // (`rel_group_area`, alle Rechte außer set_owner) - siehe Klassenkommentar
            // "Bereichsrechte". Kein Feld der Tabelle ('reltable').
            'access_group_ids' => [
                'type'  => 'reltable',
                'display_type' => 'checkbox',
                'label' => '#area.field_area_access_group_ids',
                'tooltip' => '#area.tooltip_field_area_access_group_ids',
            ],
            // Nur Inhaltsbereiche: Lösch-Policy (Modul Privacy, DeletePolicy) - was
            // beim Löschen eines Datensatzes geschieht. Pflicht; leer = die
            // Standard-Policy des Bereichstyps (hookOnSave())
            'privacy_policy_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_privacy_policy_id',
                'tooltip' => '#area.tooltip_field_area_privacy_policy_id',
                'table' => 'privacy_policy',
            ],
            // Nur type 'profile': Eingabefelder, mit denen sich der User an der
            // Website anmeldet (CMS:USER_LOGIN) - Name: ein Feld vom Typ 'chars' oder
            // 'encrypted', Passwort: ein Feld vom Typ 'password'. Beide gehören zum
            // Bereich selbst (AreaBackend::_editForm()). Eigene Spalten mit
            // Fremdschlüssel auf `field` (ON DELETE SET NULL) - seit 2026-09-14,
            // vorher in details (Migration 2026, Script inzwischen entfernt).
            'login_name_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_login_name_field_id',
                'tooltip' => '#area.tooltip_field_login_name_field_id',
                'table' => 'field',
            ],
            'login_password_field_id' => [
                'type'  => 'id',
                'display_type' => 'select',
                'label' => '#area.field_area_login_password_field_id',
                'tooltip' => '#area.tooltip_field_login_password_field_id',
                'table' => 'field',
            ],
            // Nur type 'profile': zweiter Faktor der Anmeldung (Camindo\Core\Modules\
            // Record\UserSecondFactor, developer_docs/20-frontend-schutz.md B):
            // none | optional | new_device | required
            'details.second_factor' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#area.field_area_second_factor',
                'tooltip' => '#area.tooltip_field_second_factor',
            ],
            // Nur type 'ugc': Profil-Bereiche (type 'profile' der eigenen Instanz
            // und ihrer lesbaren Instanzen), deren User hier Datensätze anlegen
            // dürfen (CMS:USER_INPUT). Leer = kein User darf schreiben.
            'details.profile_area_ids' => [
                'type'  => 'json',
                'display_type' => 'checkbox',
                'label' => '#area.field_area_profile_area_ids',
                'tooltip' => '#area.tooltip_field_profile_area_ids',
            ],
            'note' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#area.field_area_note',
                'tooltip' => '#area.tooltip_field_area_note',
                'max' => '500',
                'extension' => 'Snippets',   // wie label: auch ein Snippet (#custom.…)
            ],
            'default_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_default_field_id',
                'tooltip' => '#area.tooltip_field_default_field',
            ],
            'sort_field_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_sort_field_id',
                'tooltip' => '#area.tooltip_field_sort_field_id',
            ],
            'sort_order' => [
                'type'  => 'chars',
                'label' => '#area.field_area_sort_order',
                'max' => '4',
            ],
            // template access: table `rel_area_template`
            'default_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_default_template_id',
                'tooltip' => '#area.tooltip_field_default_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            // Darstellung im Backend (nur Datensatzbereiche): formatiert je Datensatz
            // die letzte Spalte der Liste und den Abschluss der ausführlichen
            // Lookup-Vorschau. FK auf `template`, ON DELETE SET NULL.
            'list_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_list_template_id',
                'tooltip' => '#area.tooltip_field_list_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            // Ereignis-Methoden für die Datensätze des Bereichs (TriggerTemplates,
            // Aufruf aus den Hooks von Record) - mehrere, in Ausführungsreihenfolge.
            // Kein Feld der Tabelle ('reltable'): `rel_area_trigger`, FK auf
            // `template` ON DELETE CASCADE. Laden: hookOnLoaded(), Schreiben:
            // hookOnSaved() - nur wenn das Feld übergeben wird.
            'trigger_template_ids' => [
                'type'  => 'reltable',
                'label' => '#area.field_area_trigger_template_ids',
                'tooltip' => '#area.tooltip_field_trigger_template_ids',
                'table' => 'template',
            ],
            // Übersichts-Template für die Präfix-Adresse (/de/modelle) - siehe
            // Klassenkommentar. FK auf `template`, ON DELETE SET NULL.
            'overview_template_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_overview_template_id',
                'tooltip' => '#area.tooltip_field_overview_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max' => 1
            ],
            'parent_id' => [
                'type'  => 'id',
                'label' => '#area.field_area_parent_id'
            ],
            'parent_ids' => [
                'type'  => 'json',
                'label' => '#area.field_area_parent_ids',
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#area.field_area_position',
                'display_type' => 'select',
            ],
            'depth' => [
                'type'  => 'integer',
                'label' => '#area.field_area_depth',
                'min' => 0,
            ],
            'lft' => [
                'type'  => 'integer',
                'label' => '#area.field_area_lft',
                'min' => 0,
            ],
            'rgt' => [
                'type' => 'integer',
                'min' => 0,
            ],
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#area.field_area_changed_at',
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#area.field_area_changed_by',
                'table' => 'admin'
            ],
            'deleted' => [
                'type' => 'bit'
            ]
        ];
    }

    /**
     * Vor dem Erstellen: neuen Bereich ans Ende seiner Geschwister stellen.
     *
     * COALESCE(MAX(position), -1) + 1 startet bei 0, wenn es noch keine Geschwister gibt.
     * Gelöschte Bereiche zählen nicht mit - ihre Positionen dürfen wieder vergeben werden.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Neue position = MAX(position unter gleichem Parent) + 1
        // Für Wurzel-Datensätze (parent_id = 0): MAX über alle Wurzeln
        $parentId = (int)($data['parent_id'] ?? 0);
        $this->db->query(
            "SELECT COALESCE(MAX(`position`), -1) + 1 FROM `area` WHERE `parent_id` = ? AND `deleted`=0",
            'i', [$parentId]
        );
        $data['position'] = (int)($this->db->fetchNum()[0] ?? 0);
    }

    /**
     * Nach dem Laden: Trigger-Templates in ihrer Reihenfolge (`rel_area_trigger`).
     */
    protected function hookOnLoaded(array &$data): void
    {
        $areaId = (int)($data['area_id'] ?? 0);
        if ($areaId < 1) return;
        $this->db->pushResult();
        $this->db->query("SELECT `template_id` FROM `rel_area_trigger` WHERE `area_id` = ? ORDER BY `position`, `template_id`", 'i', [$areaId]);
        $data['trigger_template_ids'] = array_map('intval', $this->db->fetchAll()); // flache Liste
        $this->db->popResult();
    }

    /**
     * Vor dem Speichern: Standard-Icon setzen, Community-Angaben bereinigen.
     * Ein Bereich ohne Icon wäre in der Baumnavigation nicht anklickbar dargestellt.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $type = (string)($data['type'] ?? $previousData['type'] ?? 'record');
        // Nur bekannte Typen - die Auswahlliste schützt nicht vor API und manipulierten
        // Formularen, und ein Bereich ohne gültigen Typ wäre nirgends verwendbar
        if (array_key_exists('type', $data) && !isset(self::DEFAULT_ICONS[$type])) {
            $this->createFieldError('error_invalid_format', 'type', $this->schema['type']['label'] ?? 'type');
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
        if (empty($data['icon'])) {
            $data['icon'] = self::DEFAULT_ICONS[$type] ?? 'draft';
        }

        $data['sort_order'] = strtoupper($data['sort_order'] ?? 'DESC');
        if ($data['sort_order'] != 'DESC') $data['sort_order'] = 'ASC';

        // Jeder Mandant führt seinen eigenen Baum: der Vater muss derselben Instanz
        // angehören, sonst stünde der Bereich im Baum eines fremden Mandanten
        // (Backend: RecordBackendFolderList, AreaBackend::list() zeigen je Instanz)
        $parentId = (int)($data['parent_id'] ?? $previousData['parent_id'] ?? 0);
        if ($parentId > 0 && (array_key_exists('parent_id', $data) || array_key_exists('instance_id', $data))) {
            $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
            $parentInstanceId = $this->areaInfo($parentId)['instance_id'] ?? -1;
            if ($parentInstanceId >= 0 && $parentInstanceId !== $instanceId) {
                $this->createFieldError('area.error_parent_instance', 'parent_id', $this->schema['parent_id']['label'] ?? 'parent_id');
                throw new \Exception($this->cms->errorMsgLast());
            }
        }

        // Trigger-Templates: Reihenfolge der Eingabe, ohne 0 und Doppelte; nur
        // vorhandene Templates
        if (array_key_exists('trigger_template_ids', $data)) {
            $ids = is_array($data['trigger_template_ids']) ? $data['trigger_template_ids'] : [];
            $data['trigger_template_ids'] = array_values($this->system->sanitizeIds($ids, true));
            if (!empty($data['trigger_template_ids'])) {
                $placeholders = implode(', ', array_fill(0, count($data['trigger_template_ids']), '?'));
                $this->db->pushResult();
                $this->db->query("SELECT COUNT(*) FROM `template` WHERE `template_id` IN ($placeholders)",
                    str_repeat('i', count($data['trigger_template_ids'])), $data['trigger_template_ids']);
                $found = (int)($this->db->fetchNum()[0] ?? 0);
                $this->db->popResult();
                if ($found !== count($data['trigger_template_ids'])) {
                    $this->createFieldError('error_invalid_format', 'trigger_template_ids', $this->schema['trigger_template_ids']['label'] ?? 'trigger_template_ids');
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
            }
        }

        // Gruppen des neuen Bereichs (nur beim Anlegen ausgewertet, siehe hookOnSaved())
        if (array_key_exists('access_group_ids', $data)) {
            $ids = $this->system->sanitizeIds((array)$data['access_group_ids'], true);
            $data['access_group_ids'] = array_values(array_diff($ids, [InstanceCache::SUPERUSER_GROUP_ID]));
        }

        // Lösch-Policy (Modul Privacy): Inhaltsbereiche brauchen eine - ohne Angabe
        // die Standard-Policy ihres Typs; wählbar sind die für alle Instanzen und die
        // der eigenen Instanz. Andere Bereichstypen haben keine.
        $isNew = empty($previousData);
        if ($isNew || array_key_exists('privacy_policy_id', $data)) {
            $policyId = (int)($data['privacy_policy_id'] ?? 0);
            if (self::isContentType($type)) {
                $policies = $this->container->get(\Camindo\Core\Modules\Privacy\DeletePolicy::class);
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
                if ($policyId < 1 || !array_key_exists($policyId, $policies->optionsFor($instanceId))) {
                    $policyId = $policies->standardId($type);
                }
                $data['privacy_policy_id'] = $policyId ?: null;
            }
            else {
                $data['privacy_policy_id'] = null;
            }
            if (!in_array('privacy_policy_id', $fields, true) && $fields) $fields[] = 'privacy_policy_id';
        }

        // Abhängiger Bereich: nur Datensatzbereiche; ein ausgeschalteter Switch steht
        // nicht im POST
        if (in_array('dependent', $fields, true)) {
            $data['dependent'] = (self::isContentType($type) && !empty($data['dependent'])) ? 1 : 0;
        }

        // Anmeldefelder (type 'profile'): nur Felder dieses Bereichs mit passendem
        // Typ - der Wert kommt aus einer Auswahlliste, ein veraltetes Formular darf
        // aber kein fremdes Feld eintragen
        $areaId = (int)($data['area_id'] ?? 0);
        foreach (['login_name_field_id' => ['chars', 'encrypted'], 'login_password_field_id' => ['password']] as $key => $types) {
            if (!array_key_exists($key, $data)) continue;
            $fieldId = (int)$data[$key];
            if ($fieldId > 0 && $type === 'profile' && $areaId > 0) {
                $placeholders = implode(',', array_fill(0, count($types), '?'));
                $this->db->query(
                    "SELECT 1 FROM `field` WHERE `field_id` = ? AND `area_id` = ? AND `type` IN ($placeholders)",
                    'ii' . str_repeat('s', count($types)),
                    array_merge([$fieldId, $areaId], $types)
                );
                if ($this->db->num_rows < 1) $fieldId = 0;
            }
            elseif ($type !== 'profile') {
                $fieldId = 0;
            }
            $data[$key] = $fieldId;
        }
        if (isset($data['details']) && is_array($data['details'])) {
            // Profil-Bereiche (type 'ugc'): nur Ids bestehender Profil-Bereiche
            if (array_key_exists('profile_area_ids', $data['details'])) {
                $ids = $this->system->sanitizeIds((array)$data['details']['profile_area_ids'], true);
                if (!empty($ids) && $type === 'ugc') {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->query(
                        "SELECT `area_id` FROM `area` WHERE `type` = 'profile' AND `deleted` = 0 AND `area_id` IN ($placeholders)",
                        str_repeat('i', count($ids)),
                        $ids
                    );
                    $ids = array_map('intval', $this->db->fetchColumn());
                }
                elseif ($type !== 'ugc') {
                    $ids = [];
                }
                $data['details']['profile_area_ids'] = array_values($ids);
            }

            // Zweiter Faktor (type 'profile'): nur bekannte Werte, sonst weg
            if (array_key_exists('second_factor', $data['details'])) {
                $policy = (string)$data['details']['second_factor'];
                if ($type !== 'profile' || !in_array($policy, UserSecondFactor::POLICIES, true) || $policy === 'none') {
                    unset($data['details']['second_factor']);
                }
            }

            // Slug-Präfix (siehe Klassenkommentar): nur Datensatzbereiche. Fehlt der
            // Schlüssel, ist der Präfix geleert (leere Eingaben schickt das Formular
            // nicht mit) - auch das wird geprüft
            if (!self::isContentType($type)) {
                unset($data['details']['slug_prefix']);
            }
            else {
                $instanceId = (int)($data['instance_id'] ?? $previousData['instance_id'] ?? 0);
                $clean = [];
                $errors = $this->slugPrefixErrors($data['details']['slug_prefix'] ?? [], $instanceId, $areaId, $clean);
                if (!empty($errors)) {
                    $label = $this->schema['details.slug_prefix']['label'];
                    foreach ($errors as $languageId => $message) {
                        $this->cms->error($message, 'details.slug_prefix.' . $languageId, $this->cms->textOrSnippet($label));
                    }
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                if (empty($clean)) unset($data['details']['slug_prefix']);
                else $data['details']['slug_prefix'] = $clean;
            }
        }
    }

    /**
     * Slug-Präfix eines Bereichs normalisieren und prüfen (Klassenkommentar
     * "Slug-Präfix").
     *
     * Eingabe je Sprache: Leerzeichen und Slashes am Rand fallen weg ("farben/" ->
     * "farben"), ein Slash in der Mitte ist ein Fehler (keine Pfadangaben), der Rest
     * wird wie ein Slug geschrieben (Cms::textToUri(): klein, Sonderzeichen zu '-').
     * Ein einzelner Wert statt eines Arrays gilt für die Hauptsprache; Sprachen, die
     * die Instanz nicht führt, fallen weg.
     *
     * @param mixed $raw         details.slug_prefix aus dem Formular / der Datei
     * @param int   $instanceId  Instanz des Bereichs
     * @param int   $areaId      der Bereich (0 = neu)
     * @param array $clean       Ergebnis: language_id => Präfix (nur nicht leere)
     * @return array  language_id => Fehlermeldung; leer = in Ordnung
     */
    public function slugPrefixErrors(mixed $raw, int $instanceId, int $areaId, array &$clean = []): array
    {
        $cache = $this->instanceCache();
        $languageIds = $cache->languageIds($instanceId);
        $primaryId = $cache->primaryLanguageId($instanceId);
        if (!is_array($raw)) $raw = (trim((string)$raw) === '') ? [] : [$primaryId => (string)$raw];

        // 1. Form
        // --------------------
        $clean = [];
        $errors = [];
        foreach ($raw as $languageId => $prefix) {
            $languageId = (int)$languageId;
            if (!in_array($languageId, $languageIds, true) || is_array($prefix)) continue;
            $prefix = trim(trim((string)$prefix), '/');
            if ($prefix === '') continue;
            if (str_contains($prefix, '/')) {
                $errors[$languageId] = $this->cms->snippet('area.error_slug_prefix_path');
                continue;
            }
            $normalized = $this->cms->textToUri($prefix, 64);
            if ($normalized === '' || str_contains($normalized, '/')) {
                $errors[$languageId] = $this->cms->snippet('error_invalid_format');
                continue;
            }
            $clean[$languageId] = $normalized;
        }
        if (!empty($errors)) return $errors;

        // 2. Konflikte je Sprache
        // --------------------
        // Gemeldet wird am Eingabefeld der Sprache, aus der der Präfix stammt (eine
        // leere Sprache übernimmt den der Hauptsprache)
        $resolved = InstanceCache::resolveSlugPrefixes($clean, $languageIds, $primaryId);
        $previous = ($areaId > 0) ? $cache->slugPrefixes($areaId) : [];
        $scope = $cache->uniqueScope($instanceId) ?: [$instanceId];
        $others = array_diff_key($cache->slugPrefixAreas($scope), [$areaId => true]);
        $lower = fn(string $text): string => mb_strtolower($text, 'UTF-8');
        foreach ($languageIds as $languageId) {
            $errorKey = isset($clean[$languageId]) ? $languageId : $primaryId;
            if (isset($errors[$errorKey])) continue;
            $new = (string)($resolved[$languageId] ?? '');
            $old = (string)($previous[$languageId] ?? '');
            if ($new !== '') {
                // derselbe Präfix bei einem anderen Bereich
                foreach (array_keys($others) as $otherId) {
                    if ($lower($cache->slugPrefix($otherId, $languageId)) !== $lower($new)) continue;
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_used'), [
                        '{prefix}' => htmlspecialchars($new . '/'),
                        '{area}'   => htmlspecialchars($this->areaTitle($otherId)),
                    ]);
                    continue 2;
                }
                // Datensätze ohne Präfix, deren Pfad damit beginnt
                if ($lower($new) !== $lower($old)) {
                    $hit = $this->slugPathsStartingWith([$new], $languageId, $scope, $areaId);
                    if ($hit !== null) {
                        $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_records'), [
                            '{prefix}' => htmlspecialchars($new . '/'),
                            '{record}' => (int)$hit['record_id'],
                            '{path}'   => htmlspecialchars((string)$hit['data']),
                            '{area}'   => htmlspecialchars($this->areaTitle((int)$hit['area_id'])),
                        ]);
                    }
                }
            }
            elseif ($old !== '' && $areaId > 0) {
                // Präfix fällt weg: die eigenen Datensätze stehen danach ohne da
                $hit = $this->slugPathCollision($areaId, $languageId, $scope);
                if ($hit !== null) {
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_remove'), [
                        '{record}' => (int)$hit['record_id'],
                        '{path}'   => htmlspecialchars((string)$hit['data']),
                        '{other}'  => (int)$hit['other_id'],
                        '{area}'   => htmlspecialchars($this->areaTitle((int)$hit['area_id'])),
                    ]);
                    continue;
                }
                $otherPrefixes = [];
                foreach (array_keys($others) as $otherId) {
                    $prefix = $cache->slugPrefix($otherId, $languageId);
                    if ($prefix !== '') $otherPrefixes[$otherId] = $prefix;
                }
                $hit = empty($otherPrefixes) ? null : $this->slugPathsStartingWith(array_values($otherPrefixes), $languageId, [$instanceId], 0, $areaId);
                if ($hit !== null) {
                    $first = $lower(explode('/', (string)$hit['data'], 2)[0]);
                    $otherId = (int)array_key_first(array_filter($otherPrefixes, fn($p) => $lower($p) === $first));
                    $errors[$errorKey] = strtr($this->cms->snippet('area.error_slug_prefix_remove_reserved'), [
                        '{record}' => (int)$hit['record_id'],
                        '{path}'   => htmlspecialchars((string)$hit['data']),
                        '{prefix}' => htmlspecialchars($first . '/'),
                        '{area}'   => htmlspecialchars($this->areaTitle($otherId)),
                    ]);
                }
            }
        }
        return $errors;
    }

    /**
     * Ein produktiver Datensatz, dessen Slug (Sprache $languageId) einer der Präfixe
     * ist oder mit "{präfix}/" beginnt - wahlweise nur in Bereichen OHNE Präfix in
     * dieser Sprache (alle außer $excludeAreaId) oder nur im Bereich $onlyAreaId.
     *
     * @return array|null  ['record_id', 'area_id', 'data'] oder null
     */
    private function slugPathsStartingWith(array $prefixes, int $languageId, array $instanceIds, int $excludeAreaId, int $onlyAreaId = 0): ?array
    {
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = ($vf !== null) ? ($this->cms->dataSuffix[$vf['type']] ?? '') : '';
        if ($suffix === '' || empty($prefixes)) return null;
        $conditions = [];
        $types = 'ii';
        $values = [(int)$vf['fieldname_id'], $languageId];
        foreach ($prefixes as $prefix) {
            $conditions[] = "`d`.`data` = ? OR `d`.`data` LIKE ?";
            $types .= 'ss';
            $values[] = $prefix;
            $values[] = addcslashes($prefix, '\\%_') . '/%';
        }
        $sql = "SELECT `d`.`record_id`, `r`.`area_id`, `d`.`data` FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`fieldname_id` = ? AND `d`.`language_id` = ? AND (" . implode(' OR ', $conditions) . ")"
             . " AND `r`.`instance_id` IN (" . implode(',', array_map('intval', $instanceIds)) . ")";
        if ($onlyAreaId > 0) {
            $sql .= " AND `r`.`area_id` = ?";
            $types .= 'i';
            $values[] = $onlyAreaId;
        }
        else {
            $sql .= " AND `r`.`area_id` != ?";
            $types .= 'i';
            $values[] = $excludeAreaId;
        }
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            if ($onlyAreaId < 1 && $this->instanceCache()->slugPrefix((int)$row['area_id'], $languageId) !== '') continue;
            return $row;
        }
        return null;
    }

    /**
     * Ein Datensatz des Bereichs, dessen Slug (Sprache $languageId) auch ein
     * Datensatz eines Bereichs ohne Präfix trägt - der Pfad wäre ohne den Präfix
     * des Bereichs doppelt.
     *
     * @return array|null  ['record_id', 'data', 'other_id', 'area_id' (des anderen)]
     */
    private function slugPathCollision(int $areaId, int $languageId, array $instanceIds): ?array
    {
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = ($vf !== null) ? ($this->cms->dataSuffix[$vf['type']] ?? '') : '';
        if ($suffix === '') return null;
        $sql = "SELECT `a`.`record_id`, `a`.`data`, `b`.`record_id` AS `other_id`, `rb`.`area_id` FROM `data_{$suffix}` `a`"
             . " INNER JOIN `record` `ra` ON `ra`.`record_id` = `a`.`record_id` AND `ra`.`tmp` = 0 AND `ra`.`deleted` = 0 AND `ra`.`area_id` = ?"
             . " INNER JOIN `data_{$suffix}` `b` ON `b`.`fieldname_id` = `a`.`fieldname_id` AND `b`.`language_id` = `a`.`language_id`"
             . " AND `b`.`data` = `a`.`data` AND `b`.`record_id` != `a`.`record_id`"
             . " INNER JOIN `record` `rb` ON `rb`.`record_id` = `b`.`record_id` AND `rb`.`tmp` = 0 AND `rb`.`deleted` = 0"
             . " AND `rb`.`area_id` != ? AND `rb`.`instance_id` IN (" . implode(',', array_map('intval', $instanceIds)) . ")"
             . " WHERE `a`.`fieldname_id` = ? AND `a`.`language_id` = ? AND `a`.`data` != ''";
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", 'iiii', [$areaId, $areaId, (int)$vf['fieldname_id'], $languageId]);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            if ($this->instanceCache()->slugPrefix((int)$row['area_id'], $languageId) !== '') continue;
            return $row;
        }
        return null;
    }

    /**
     * Beschriftung eines Bereichs für Meldungen: "Label (name)".
     */
    private function areaTitle(int $areaId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label`, `name` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return (string)$areaId;
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
    }

    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $areaId = (int)$data['area_id'];

        // instance_id
        // --------------------
        // Die Datensätze des Bereichs führen eine Kopie der Zuordnung (`record` hat
        // KEINEN Fremdschlüssel auf `area`, der Bereich kann sie also nicht für sie
        // mitverwalten - der Fremdschlüssel auf `instance` hängt an der Kopie).
        // Wechselt der Bereich die Instanz, müssen sie mitwandern; sonst zeigten sie
        // weiter auf die alte Website und verschwänden mit ihr.
        // Dasselbe gilt für die Templates eines Template-Containers: auch sie führen
        // eine Kopie, damit Cms::select() ohne JOIN über den Bereich filtern kann
        // (siehe Cms::$sqlAccess).
        $instanceId = (int)($data['instance_id'] ?? 0);
        $previousInstanceId = (int)($previousData['instance_id'] ?? -1);
        if ($instanceId !== $previousInstanceId) {
            $this->db->query(
                "UPDATE `record` SET `instance_id` = ? WHERE `area_id` = ?",
                'ii',
                [$instanceId, $areaId]
            );
            $this->db->query(
                "UPDATE `template` SET `instance_id` = ? WHERE `area_id` = ?",
                'ii',
                [$instanceId, $areaId]
            );
            // Die Mediendateien liegen unter media/{instance_id}/{field_id} und
            // müssen mit umziehen (Camindo\Core\MediaAccess). Cms merkt sich die
            // Instanz je Feld für den Request - die Zuordnung ist jetzt veraltet.
            if ($previousInstanceId > 0 && !empty($previousData)) {
                $this->cms->clearFieldInstanceCache();
                $this->container->get(MediaAccess::class)->moveAreaMedia($areaId, $previousInstanceId, $instanceId);
            }
        }

        // default_field_id
        // --------------------
        // Wenn default_field_id geändert wurde - dann auch Tabelle `field` aktualisieren
        $defaultFieldId = (int)$data['default_field_id'];
        if ($defaultFieldId !== (int)$previousData['default_field_id']) {
            // -> kein anderes Feld des Bereichs kann das default Field bleiben
            $this->db->query("UPDATE `field` SET `default_field`=0 WHERE `area_id` = ?", 'i', [$areaId]);
            // -> Feld markieren und dabei area_id validieren
            $this->db->query("UPDATE `field` SET `default_field`=1 WHERE `field_id` = ? AND `area_id` = ?", 'ii', [$defaultFieldId, $areaId]);
            if ($this->db->affected_rows < 1) {
                // Feld konnte nicht upgedated werden
                $label = $this->schema['default_field_id']['label'] ?? 'default_field_id';
                $this->createFieldError('error_field_not_found', 'default_field_id', $label);
                throw new \Exception($this->cms->errorMsgLast());
            }
        }

        // Template-Zuweisungen (selectable, url-override)
        // --------------------
        // Nur die übergebenen Listen ersetzen: load() liefert sie nicht mit, ein
        // load()+save() (API area.update, Import ...) ließe sonst nur das
        // Standard-Template übrig. Das Formular schickt beide immer (AreaBackend).
        $selectableTemplates = array_key_exists('selectable_templates', $data)
            ? (array)$data['selectable_templates']
            : $this->templateIds($areaId, 'selectable');
        $overrideTemplates = array_key_exists('override_templates', $data)
            ? (array)$data['override_templates']
            : $this->templateIds($areaId, 'override');

        $this->db->query("DELETE FROM `rel_area_template` WHERE `area_id`= ? ", 'i', [$areaId]);

        // Das Default-Template wird immer auch zu den wählbaren Templates gezählt
        // (vereinfacht die Zugriffsprüfung in class Page)
        // (sanitizeIds wirft 0 oder Duplette wieder raus)
        $selectableTemplates[] = (int)($data['default_template_id'] ?? $previousData['default_template_id'] ?? 0);
        $selectableTemplates = $this->system->sanitizeIds($selectableTemplates, true);

        $overrideTemplates = $this->system->sanitizeIds($overrideTemplates, true);

        // Templates-Ids aus beiden Arrays
        $mergedIds = array_unique(array_merge($selectableTemplates, $overrideTemplates));

        if (count($mergedIds) > 0) {
            $sqlValues = [];
            $placeholders = [];
            
            foreach ($mergedIds as $templateId) {
                $selectable = in_array($templateId, $selectableTemplates, true) ? 1 : 0;
                $override = in_array($templateId, $overrideTemplates, true) ? 1 : 0;
                array_push($sqlValues, $areaId, $templateId, $selectable, $override);
                $placeholders[] = '(?, ?, ?, ?)';
            }
            
            $sqlCols = implode(', ', $placeholders);
            $sqlTypes = str_repeat('iiii', count($mergedIds));            
            $sql = "INSERT INTO `rel_area_template` (`area_id`, `template_id`, `selectable`, `override`) VALUES $sqlCols";
            $this->db->query($sql, $sqlTypes, $sqlValues);
        }

        // Trigger-Templates (`rel_area_trigger`) - nur wenn übergeben; `position`
        // ist die Reihenfolge der Eingabe, hookOnSave() hat die Ids bereinigt
        // --------------------
        if (array_key_exists('trigger_template_ids', $data)) {
            $this->db->query("DELETE FROM `rel_area_trigger` WHERE `area_id` = ?", 'i', [$areaId]);
            $ids = (array)$data['trigger_template_ids'];
            if (!empty($ids)) {
                $values = [];
                foreach (array_values($ids) as $position => $templateId) {
                    array_push($values, $areaId, (int)$templateId, $position + 1);
                }
                $placeholders = implode(', ', array_fill(0, count($ids), '(?, ?, ?)'));
                $this->db->query("INSERT INTO `rel_area_trigger` (`area_id`, `template_id`, `position`) VALUES $placeholders",
                    str_repeat('iii', count($ids)), $values);
            }
        }

        // Bereichsrechte eines NEUEN Bereichs (siehe Klassenkommentar)
        // --------------------
        $type = (string)($data['type'] ?? $previousData['type'] ?? '');
        if (empty($previousData) && $type !== 'folder') {
            $groupIds = array_key_exists('access_group_ids', $data) ? (array)$data['access_group_ids'] : null;
            $this->grantGroups($areaId, $instanceId, $groupIds);
        }

        // Instanz-Dateicache (Bereichsnamen)
        // --------------------
        // Die Übersetzung area_id => name in var/cache/instance-{id}.php und
        // instances.php muss mitziehen (siehe Klassenkommentar "Name") - bei neuem
        // Bereich, geändertem Namen, Instanzwechsel oder Verschieben im Baum. Der
        // Neuaufbau kostet wenige Abfragen und Bereiche ändern sich selten, deshalb
        // ohne Fallunterscheidung. Läuft noch in der Transaktion, die Abfragen sehen
        // also den gerade geschriebenen Stand.
        $this->instanceCache()->rebuild();

        // Quelltext-Verzeichnis der Templates (var/templates/{instance_id}/{name},
        // siehe TemplateFiles): bei neuem Template-Bereich anlegen, bei geändertem
        // Namen oder Instanzwechsel umbenennen bzw. verschieben
        // --------------------
        if ($type === 'template') {
            $name = (string)($data['name'] ?? $previousData['name'] ?? '');
            $oldName = (string)($previousData['name'] ?? '');
            if ($oldName === '') {
                $this->templateFiles()->createAreaDir($instanceId, $name);
            }
            elseif ($oldName !== $name || $previousInstanceId !== $instanceId) {
                $this->templateFiles()->moveAreaDir($previousInstanceId, $oldName, $instanceId, $name);
            }
        }
    }

    /**
     * Gespeicherte Template-Zuweisungen eines Bereichs (`rel_area_template`).
     *
     * @param string $column  'selectable' | 'override'
     * @return int[]
     */
    public function templateIds(int $areaId, string $column): array
    {
        if (!in_array($column, ['selectable', 'override'], true) || $areaId < 1) return [];
        $this->db->pushResult();
        $this->db->query("SELECT `template_id` FROM `rel_area_template` WHERE `area_id` = ? AND `$column` = 1", 'i', [$areaId]);
        $ids = array_map('intval', $this->db->fetchAll()); // flache Liste
        $this->db->popResult();
        return $ids;
    }

    /**
     * vor dem Soft-Löschen...
     *
     * Markiert alle Datensätze des Bereichs mit demselben Zeitstempel wie den Bereich
     * selbst. Nur dadurch weiß hookOnRestore() später, welche Datensätze zu genau
     * diesem Löschvorgang gehören und wiederhergestellt werden dürfen. Danach plant
     * die Lösch-Policy des Bereichs ihre Blöcke für den Auslöser 'area'.
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();

        // getMarkTimestamp(): Vorgangs-Zeitstempel, den auch der area-Datensatz
        // selbst als changed_at erhält -> records bekommen exakt denselben Wert
        // (für die Wiederherstellung nötig)
        $record->setMarkTimestamp($this->getMarkTimestamp());

        // Jeder Datensatz einzeln - so läuft je Datensatz Record::hookOnSoftDelete()
        // und schiebt dessen Mediendateien in den Papierkorb.
        // Reihenfolge: Blätter zuerst (siehe cascadeIds()).
        $recordIds = $record->cascadeIds('area_id', $areaId, 'delete');
        foreach ($recordIds as $recordId) {
            if (!$record->softDelete($recordId)) {
                // Fehlermeldung steht bereits in Cms::errors -> Transaktion verwerfen
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        // Lösch-Policy des Bereichs, Auslöser "Bereich wurde gelöscht" (Modul Privacy) -
        // läuft mit der Zeitplaner-Aufgabe, eine Wiederherstellung verwirft die Aufträge
        if ($recordIds) {
            $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->scheduleArea($areaId, $recordIds);
        }

        // Template-Bereich: Quelltext-Verzeichnis in den Papierkorb (var/templates/
        // .trash/{area_id}) - sonst legte TemplateFiles::sync() zu dem verwaisten
        // Verzeichnis einen neuen Bereich an. hookOnRestore() holt es von dort zurück.
        if ((string)($data['type'] ?? '') === 'template') {
            $this->templateFiles()->trashAreaDir($areaId, (int)$data['instance_id'], (string)$data['name']);
        }
    }

    /**
     * vor dem Endgültigen löschen...
     *
     * Die Datensätze des Bereichs müssen hier weg, bevor die area-Zeile verschwindet:
     * `record` hat KEINEN Fremdschlüssel auf `area`, sie blieben sonst mit einer
     * area_id zurück, die es nicht mehr gibt - samt ihrer Mediendateien.
     * Jeder Datensatz einzeln, damit Record::hookOnDelete() je Datensatz läuft.
     *
     * Eingabefelder (`field`) und deren Inhalte (`data_*`) räumt dagegen die Datenbank
     * selbst ab: fk_field_area und die data_*-Fremdschlüssel stehen auf
     * ON DELETE CASCADE.
     */
    protected function hookOnDelete(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();

        // Datensätze des Bereichs endgültig löschen
        // Blätter zuerst (siehe cascadeIds()), damit kein Datensatz per
        // ON DELETE CASCADE über record.parent_id an seinem Hook vorbei verschwindet.
        foreach ($record->cascadeIds('area_id', $areaId, 'purge') as $recordId) {
            if (!$record->finalDelete($recordId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Template-Bereich: die Templates verschwinden mit der area-Zeile
        // (fk_template_area_id ON DELETE CASCADE) - ohne Template::hookOnDelete().
        // Deshalb hier deren Dateien: kompiliertes PHP (var/php/{area_id}) und
        // Quelltext-Verzeichnis (var/templates/{instance_id}/{name} bzw. dessen
        // Papierkorb-Kopie).
        if ((string)($data['type'] ?? '') === 'template') {
            $phpDir = $this->config->get('paths.var_dir') . '/php/' . $areaId;
            foreach ((array)glob($phpDir . '/*.php') as $file) {
                if (is_file($file)) unlink($file);
            }
            if (is_dir($phpDir)) @rmdir($phpDir);
            $this->templateFiles()->removeAreaDir((int)$data['instance_id'], (string)$data['name'], $areaId);
        }

        // Eingabefelder des Bereichs endgültig löschen
        // (Bidirektionale Gegenfelder werden über FK automatisch gelöscht)
        // -> erledigt fk_field_area ON DELETE CASCADE
    }

    /**
     * nach dem endgültigen Löschen...
     *
     * Die Eingabefelder des Bereichs verschwinden erst MIT der area-Zeile
     * (fk_field_area ON DELETE CASCADE) - also nach hookOnDelete(). Ein Aufräumen
     * der Feldnamen dort käme zu früh: cleanupFieldname() fände die Felder noch und
     * ließe ihre Namen stehen. Deshalb erst hier.
     */
    protected function hookOnDeleted(array $data): void
    {
        // Fieldname-Ids, die nicht mehr benötigt werden, löschen
        $this->field->cleanupFieldname();

        // Bereich aus dem Instanz-Dateicache nehmen (siehe Klassenkommentar "Name")
        $this->instanceCache()->rebuild();
    }

    /**
     * vor dem Wiederherstellen
     * Als "gelöscht" markierte Datensätze des Breichs ebenfalls wiederherstellen -
     * allerdings nur die mit exakt dem Löschzeitpunkt des Bereichs.
     * (Läuft innerhalb Transaktion - wird also im Frontend erst sichtbar, wenn
     * der Bereich selbst wieder sichtbar ist)
     * getRestoreTimestamp(): Zeitstempel des Löschvorgangs, der rückgängig
     *     gemacht wird -> nur records mit exakt diesem Zeitstempel
     * getMarkTimestamp(): neuer, einheitlicher Änderungszeitpunkt
     *     für alle wiederhergestellten Datensätze
     */
    protected function hookOnRestore(array $data): void
    {
        $areaId = (int)$data['area_id'];
        $record = $this->recordDataset();
        $record->setMarkTimestamp($this->getMarkTimestamp());

        // Nur Datensätze mit exakt dem changed_at des rückgängig zu machenden
        // Löschvorgangs - Datensätze aus früheren Löschvorgängen bleiben markiert.
        // Jeder einzeln, damit Record::hookOnRestore() seine Mediendateien
        // aus dem Papierkorb zurückholt. Reihenfolge: Eltern zuerst.
        $ids = $record->cascadeIds('area_id', $areaId, 'restore', $this->getRestoreTimestamp());
        foreach ($ids as $recordId) {
            if (!$record->restoreOne($recordId)) {
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // Template-Bereich: Quelltext-Verzeichnis aus dem Papierkorb zurückholen
        // (hookOnSoftDelete() hatte es dorthin verschoben) - nicht aus der Datenbank
        // neu schreiben, siehe TemplateFiles
        if ((string)($data['type'] ?? '') === 'template') {
            $this->templateFiles()->restoreAreaDir($areaId, (int)$data['instance_id'], (string)$data['name']);
        }
    }

    // ====================
    // Bereichsrechte (rel_group_area) - siehe Klassenkommentar

    /**
     * Rechte aller Gruppen an einem Bereich: group_id => Flags (Zeile = Leserecht).
     *
     * @return array  group_id => ['group_write', 'group_delete', 'other_write',
     *                'other_delete', 'set_owner'] (int 0|1)
     */
    public function accessRows(int $areaId): array
    {
        $rows = [];
        if ($areaId < 1) return $rows;
        $this->db->pushResult();
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$areaId]);
        while ($row = $this->db->fetch()) {
            $rows[(int)$row['group_id']] = [
                'group_write'  => (int)!empty($row['group_write']),
                'group_delete' => (int)!empty($row['group_delete']),
                'other_write'  => (int)!empty($row['other_write']),
                'other_delete' => (int)!empty($row['other_delete']),
                'set_owner'    => (int)!empty($row['set_owner']),
            ];
        }
        $this->db->popResult();
        return $rows;
    }

    /**
     * Gruppen, die für einen Bereich in Frage kommen: alle außer der Superuser-
     * Gruppe, denen die Instanz des Bereichs zugewiesen ist (Reiter "Zugriff",
     * Anlegen eines Bereichs).
     *
     * @return int[]  group_ids
     */
    public function accessCandidates(int $instanceId): array
    {
        $ids = [];
        foreach ($this->instanceCache()->groupIds() as $groupId) {
            if ($groupId === InstanceCache::SUPERUSER_GROUP_ID) continue;
            $group = $this->instanceCache()->group($groupId);
            if (in_array($instanceId, array_map('intval', (array)($group['instance_ids'] ?? [])), true)) $ids[] = $groupId;
        }
        return $ids;
    }

    /**
     * Rechte aller Gruppen an einem Bereich schreiben (AreaBackend::infoAccess()).
     *
     * @param int   $areaId
     * @param array $rows  group_id => ['read' => 1, 'group_write' => 1, ...]; ohne
     *                     'read' fällt die Zeile weg. Nur Gruppen aus
     *                     accessCandidates(); Template-Bereiche behalten nur group_write.
     * @return bool  false: Meldung in Cms::errors
     */
    public function saveAccess(int $areaId, array $rows): bool
    {
        $info = $this->areaInfo($areaId);
        if ($info === null || $info['type'] === 'folder') {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }
        $candidates = $this->accessCandidates($info['instance_id']);
        $isTemplate = ($info['type'] === 'template');

        return (bool)$this->transactional(function () use ($areaId, $rows, $candidates, $isTemplate) {
            $this->db->query("DELETE FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$areaId]);
            foreach ($rows as $groupId => $flags) {
                $groupId = (int)$groupId;
                $flags = (array)$flags;
                if (!in_array($groupId, $candidates, true) || empty($flags['read'])) continue;
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
            // Die Rechte liegen in den Gruppendateien - Access liest sie je Request
            $this->instanceCache()->rebuild();
            return true;
        }, false);
    }

    /**
     * Neuen Bereich für Gruppen freigeben: volle Rechte außer set_owner.
     *
     * @param int[]|null $groupIds  gewählte Gruppen; null = alle Gruppen mit der Instanz
     */
    private function grantGroups(int $areaId, int $instanceId, ?array $groupIds): void
    {
        $candidates = $this->accessCandidates($instanceId);
        if ($groupIds !== null) {
            $candidates = array_values(array_intersect($candidates, array_map('intval', $groupIds)));
        }
        foreach ($candidates as $groupId) {
            // Spalten-Defaults: alle Flags außer set_owner (Signatur ebenso)
            $this->db->query(
                "INSERT IGNORE INTO `rel_group_area` (`group_id`, `area_id`, `set_owner`, `sig`) VALUES (?, ?, 0, ?)",
                'iis', [$groupId, $areaId, $this->integrity()->areaRightSignature($groupId, $areaId, ['set_owner' => 0])]
            );
        }
    }

    /**
     * Rechte eines Bereichs auf einen anderen übertragen (Kopie eines Bereichs).
     * Der Cache wird vom Aufrufer bzw. dem Speichern des Ziels erneuert.
     */
    public function copyAccess(int $sourceId, int $targetId): void
    {
        if ($sourceId < 1 || $targetId < 1) return;
        $this->db->query("DELETE FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$targetId]);
        // Zeile für Zeile statt INSERT ... SELECT: jede Kopie braucht ihre eigene
        // Signatur (Integrity), und nur geprüfte Quellzeilen werden übernommen.
        $this->db->query("SELECT * FROM `rel_group_area` WHERE `area_id` = ?", 'i', [$sourceId]);
        $rows = $this->db->fetchAll();
        foreach ($rows as $row) {
            if (!$this->integrity()->verifyRight('rel_group_area', $row)) continue;
            $groupId = (int)$row['group_id'];
            $this->db->query(
                "INSERT INTO `rel_group_area` (`group_id`, `area_id`, `group_write`, `group_delete`, `other_write`, `other_delete`, `set_owner`, `sig`)"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                'iiiiiiis',
                [
                    $groupId, $targetId,
                    (int)$row['group_write'], (int)$row['group_delete'], (int)$row['other_write'], (int)$row['other_delete'], (int)$row['set_owner'],
                    $this->integrity()->areaRightSignature($groupId, $targetId, $row),
                ]
            );
        }
        $this->instanceCache()->rebuild();
    }

    /**
     * Anzahl der Eingabefelder eines Bereichs ermitteln
     * @param int $areaId  : Id des Bereichs
     */
    public function fieldsNumber(int $areaId): int {
        $sql = "SELECT COUNT(*) FROM `field` WHERE `area_id`= ?";
        $this->db->query($sql, 'i', [$areaId]);
        return (int)$this->db->fetchNum()[0];
    }

    /**
     * Bereiche und Ordner laden, die mindestens einen Bereich in ihrer Baumstruktur
     * (zwischen lft und rgt) enthalten, also nicht nur leere Ordner enthalten.
     *
     * Mit $instanceId nur die Bereiche dieser Instanz - der Baum wird im Backend je
     * Mandant gezeigt (hookOnSave() lässt einen Bereich nur unter einem Ordner
     * derselben Instanz zu, ein Teilbaum ist also einheitlich). Die Nested-Set-
     * Werte bleiben tabellenweit; innerhalb einer Instanz stimmt die lft-Reihenfolge.
     *
     * Mit $areaIds nur diese Bereiche (die lesbaren der Benutzergruppe,
     * Access::areaFilter()) - ein Ordner erscheint dann nur, wenn er mittelbar einen
     * davon enthält; null = keine Einschränkung, [] = nichts.
     *
     * @param array      $areaTypes
     * @param int        $instanceId  0 = alle Instanzen
     * @param bool       $hideDependent
     * @param int[]|null $areaIds
     */
    public function loadAreas(array $areaTypes, int $instanceId = 0, bool $hideDependent = false, ?array $areaIds = null): void
    {    
        $types = "'" . implode("','", $areaTypes) . "'";
        $instanceA = $instanceId > 0 ? " AND a.`instance_id` = " . $instanceId : '';
        $instanceB = $instanceId > 0 ? " AND b.`instance_id` = " . $instanceId : '';
        // Abhängige Bereiche (`dependent` = 1, Zielbereiche von 'link'-Feldern) auf
        // Wunsch weglassen - samt Ordnern, die nur solche enthalten
        $dependentA = $hideDependent ? " AND a.`dependent` = 0" : '';
        $dependentB = $hideDependent ? " AND b.`dependent` = 0" : '';
        // Lesbare Bereiche der Benutzergruppe (Ids aus dem Cache, keine Fremdeingabe)
        $idsA = $idsB = '';
        if ($areaIds !== null) {
            $list = implode(',', array_map('intval', $areaIds)) ?: '0';
            $idsA = " AND a.`area_id` IN ($list)";
            $idsB = " AND b.`area_id` IN ($list)";
        }
        $sql = "SELECT `area_id`, `instance_id`, `type`, `icon`, `label`, `depth`, `lft`, `rgt`"
            ." FROM `area` a"
            ." WHERE ("
            ."    (a.`type` IN ($types)" . $dependentA . $idsA . ")"
            ."    OR (a.`type` = 'folder' AND EXISTS ("
            ."        SELECT 1 FROM `area` b"
            ."        WHERE b.`type` IN ($types)" . $dependentB . $idsB
            ."          AND b.`lft` > a.`lft` AND b.`rgt` < a.`rgt` AND b.`deleted` = 0" . $instanceB
            ."    ))"
            ." )"
            ." AND a.`deleted` = 0" . $instanceA
            ." ORDER BY `lft` ASC";
        $this->db->query($sql);
    }

    /**
     * Record-Instanz bereitstellen (Factory hier unnötig)
     *
     * Bewusst NICHT über den Konstruktor injiziert: Dataset-Kindklassen erben den
     * Konstruktor der Basisklasse und deklarieren keinen eigenen. Eine zusätzliche
     * Abhängigkeit hieße, die komplette Signatur von Dataset::__construct() zu
     * wiederholen. Der Zugriff über den Container holt die Instanz stattdessen erst
     * dann, wenn sie gebraucht wird - dieselbe geteilte Instanz wie überall sonst.
     */
    private function recordDataset() {
        return $GLOBALS['app']->get(Record::class);
    }

    /**
     * Quelltext-Dateien der Templates (siehe TemplateFiles).
     */
    private function templateFiles(): TemplateFiles
    {
        return $this->container->get(TemplateFiles::class);
    }

    /**
     * Dateicache der Instanzen (siehe Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    private function integrity(): Integrity
    {
        return $this->container->get(Integrity::class);
    }
}
