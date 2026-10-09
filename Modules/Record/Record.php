<?php
/**
 * Dataset-Kindklasse für die Tabelle `record` - die Inhaltsdatensätze.
 *
 * Die zentrale Klasse des CMS und zugleich die kürzeste der Modul-Datasets. Das ist
 * kein Zufall: fast alles, was einen Datensatz ausmacht, steckt in den drei Traits
 * und in der Basisklasse. Hier bleibt nur, was `record` von allen anderen Tabellen
 * unterscheidet - und das sind die Mediendateien.
 *
 * DAS SCHEMA KOMMT VON AUSSEN
 * --------------------
 * $this->schema = $this->cms->schema. Die Meta-Felder eines Datensatzes sind in
 * Cms::$schema definiert, nicht hier:
 *
 *     record_id area_id                 Zugehörigkeit
 *     online_at offline_at status_id    Sichtbarkeit im Frontend
 *     parent_id parent_ids position
 *     depth lft rgt                     Baumstruktur (zwei Verfahren parallel)
 *     template_id                       Darstellung
 *     created_by changed_by
 *     created_at changed_at             Herkunft und Änderungsstand
 *     needs_review                      Gegenlese-Aufgabe (siehe UserBackend)
 *     tmp origin_id deleted             Arbeitskopie und Papierkorb
 *
 * Der Grund für die Auslagerung: Cms::select() muss die Meta-Felder kennen, um sie von
 * den benutzerdefinierten Feldern unterscheiden zu können - und Cms kommt ohne diese
 * Klasse aus, umgekehrt nicht.
 *
 * ALLES ÜBRIGE IST BENUTZERDEFINIERT
 * --------------------
 * Was ein Datensatz sonst noch enthält, legt der Redakteur je Inhaltsbereich selbst
 * fest (Tabelle `field`). Diese Inhalte stehen typgetrennt in den `data_*`-Tabellen und
 * werden von VirtualFieldsTrait gelesen und geschrieben - für den Aufrufer sehen sie
 * aus wie normale Felder.
 *
 * VIER TRAITS
 * --------------------
 *     TreeManagerTrait     Baumstruktur. Nested Set (lft/rgt) und Adjacency List
 *                          (parent_id/position/depth/parent_ids) werden parallel
 *                          geführt: das eine für Teilbaum-Abfragen in einem Rutsch,
 *                          das andere für günstiges Umsortieren.
 *     VirtualFieldsTrait   die benutzerdefinierten Felder aus den data_*-Tabellen,
 *                          einschließlich Dateiablage und Thumbnail-Erzeugung.
 *     TemporaryTrait       Arbeitskopien. Wer einen Datensatz bearbeitet, arbeitet
 *                          nicht am Original, sondern an einer Kopie mit tmp=1 und
 *                          origin_id; erst das Speichern (checkIn) überträgt sie.
 *                          Dadurch überstehen Eingaben eine fehlgeschlagene Prüfung,
 *                          und das Frontend sieht nie einen halbfertigen Stand.
 *     MoveTrait            Bereichswechsel. Ein Datensatz wird mit einer Abbildung
 *                          seiner Felder auf die des Zielbereichs über eine
 *                          Arbeitskopie neu gespeichert (moveToArea()).
 *     LinkTrait            Fest verknüpfte Inhalte (Felder vom Typ 'link'): die
 *                          Zieldatensätze gehören dem Quelldatensatz und werden mit
 *                          ihm ausgecheckt, eingecheckt, verworfen, gelöscht,
 *                          wiederhergestellt und kopiert.
 *
 * DIE HOOKS HIER KÜMMERN SICH UM DIE DATEIEN
 * --------------------
 * Mediendateien liegen im Dateisystem (Core/public_master/media/{field_id}/{record_id}.{ext})
 * und nicht in der Datenbank - ein DELETE räumt sie also nicht mit ab. Deshalb sind
 * drei der Hooks belegt, und zwar spiegelbildlich zum dreistufigen Löschen:
 *
 *     hookOnSoftDelete   Dateien nach var/deleted verschieben  (Papierkorb)
 *     hookOnRestore      Dateien zurückholen
 *     hookOnDelete       Dateien endgültig entfernen           (nur Systemtool/Cron)
 *
 * Zusätzlich reicht jeder Hook sein Ereignis an das Trigger-Template des Bereichs
 * weiter (`rel_area_trigger`, siehe TriggerTemplates).
 *
 * Die data_fil-Einträge bleiben beim Soft-Delete ausdrücklich stehen: ohne sie wären
 * Feld, Dateiendung und Version beim Wiederherstellen nicht mehr zu ermitteln.
 *
 * @see Camindo\Core\Cms                            $schema, select(), fetch()
 * @see Camindo\Core\TreeManagerTrait               Baumverwaltung
 * @see Camindo\Core\VirtualFieldsTrait             data_*-Felder, Dateien, Thumbnails
 * @see Camindo\Core\TemporaryTrait                 Arbeitskopien (checkOut / checkIn)
 * @see Camindo\Core\Modules\Record\RecordBackend   Backend-Oberfläche
 * @see Camindo\Core\Modules\Area\Area              Inhaltsbereich mit der Felddefinition
 * @see docs/developers/02-datenmodell.md
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\Dataset;
use Camindo\Core\DirectoryHelpers;
use Camindo\Core\InstanceCache;
use Camindo\Core\TreeManagerTrait;
use Camindo\Core\VirtualFieldsTrait;
use Camindo\Core\TemporaryTrait;
use Camindo\Core\MoveTrait;
use Camindo\Core\LinkTrait;
use Camindo\Core\TriggerTemplates;

class Record extends Dataset {

    use TreeManagerTrait, VirtualFieldsTrait, MoveTrait, LinkTrait;
    use TemporaryTrait {
        cancel as protected tmpCancel;
    }

    /**
     * Tabellendefinition.
     *
     * Das Schema wird nicht hier geschrieben, sondern aus Cms übernommen (siehe
     * Klassenkommentar).
     *
     * Standardsortierung 'changed_at DESC' - zuletzt Bearbeitetes zuerst; das ist die
     * Reihenfolge, in der ein Redakteur seine Datensätze sucht. Für die Anzeige im
     * Bereich gilt stattdessen meist die dort eingestellte Sortierung
     * (area.sort_field_id / sort_order).
     *
     * $hasTmp schaltet die Arbeitskopien scharf: Dataset behandelt Datensätze mit
     * tmp=1 danach als solche und löst sie beim Laden auf ihr Original auf.
     */
    protected function hookDefinition(): void
    {
        $this->tableName = 'record';
        $this->moduleName = 'Record';
        $this->defaultSortOrder = 'changed_at DESC';
        $this->schema = $this->cms->schema;
        $this->hasTmp = true; // field "tmp" requires the fields "origin_id", "deleted", "changed_by", "changed_at"
    }

    /**
     * Vor dem Erstellen: Zugehörigkeit sicherstellen.
     *
     * Ein Datensatz ohne Inhaltsbereich ergibt keinen Sinn - erst der Bereich sagt,
     * welche Felder er hat. Deshalb ist area_id hier Pflicht, und init() lässt sich
     * für `record` nicht ohne Vorgabe aufrufen.
     *
     * Die eigentliche Vorbelegung der benutzerdefinierten Felder übernimmt danach
     * VirtualFieldsTrait aus den Defaults der Felddefinitionen.
     */
    protected function hookOnCreate(array &$data): void
    {
        // Default-Werte erstellen
        $areaId = (int) $data['area_id'];
        if (empty($areaId)) {
            throw new \Exception('Error: Record::init needs parameter data[area_id]!');
        }
        $this->trigger($areaId, 'onCreate', $data);
    }

    // hookOnLoaded() ist bewusst leer: Laden und Speichern der benutzerdefinierten
    // Felder erledigt VirtualFieldsTrait, das Umschalten zwischen Arbeitskopie und
    // Original TemporaryTrait - beides innerhalb von Dataset, ohne dass hier etwas zu
    // ergänzen wäre. hookOnSave() und hookOnSaved() haben je eine Aufgabe, siehe dort.

    /**
     * Nach dem Laden...
     */
    protected function hookOnLoaded(array &$data): void
    {
        $this->trigger((int)($data['area_id'] ?? 0), 'onLoaded', $data);
    }

    /**
     * Vor dem Speichern: leeren Slug füllen, reine Zahlen entschärfen.
     *
     * 'slug' ist ein optionales virtuelles Feld - es existiert nur, wenn der
     * Inhaltsbereich es angelegt hat. Der Grund für ein virtuelles statt eines
     * Meta-Feldes: virtuelle Felder können später mehrsprachig geführt werden, die
     * Spalten von `record` nicht.
     *
     * Lässt der Redakteur das Feld leer, entsteht der Slug aus dem Default-Feld des
     * Bereichs (dem "Titel", area.default_field_id) über Cms::textToUri(): nur
     * Wortzeichen und Slash bleiben stehen, gekürzt auf system.link_length.
     *
     * Hat der Bereich kein Default-Feld oder ist dessen Inhalt leer, bleibt der Slug
     * leer. Das ist kein Mangel: CMS:URL fällt dann auf die record_id zurück
     * ("/42"), und Page::resolveRequest() löst einen rein numerischen Wert ohnehin
     * direkt über die Id auf.
     *
     * KEINE REINEN ZAHLEN
     * --------------------
     * Genau deshalb darf ein Slug nie eine reine Ziffernfolge sein: "/42" wäre sonst
     * mehrdeutig - einmal die record_id 42, einmal der Datensatz mit dem Slug "42".
     * Ein solcher Slug bekommt darum ein Minus vorangestellt: aus dem Titel "2024"
     * wird "-2024". Das gilt für den erzeugten Slug ebenso wie für einen von Hand
     * eingetragenen.
     *
     * Ein von Hand eingetragener Slug wird genauso normalisiert wie der erzeugte
     * (Cms::textToUri(): Kleinschreibung, Sonderzeichen zu "-", gekürzt). Bleibt
     * dabei nichts übrig, wird er wie ein leeres Feld behandelt.
     *
     * Geprüft wird auf reine Ziffern, nicht mit is_numeric() - das hielte auch das
     * Ergebnis "-2024" wieder für eine Zahl und die Ausweichregel liefe ins Leere.
     * Page::resolveRequest() unterscheidet Id und Slug nach derselben Regel.
     *
     * Ein einmal gesetzter Slug wird NICHT nachgeführt, wenn sich der Titel später
     * ändert - ein wandernder Slug macht jeden bereits geteilten Link ungültig.
     * Wer ihn ändern will, leert das Feld und lässt es neu füllen.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // Trigger-Template zuerst: was es in $data setzt, durchläuft alles Weitere
        // (Slug, Prüfung, Schreiben) wie eine Eingabe
        $this->trigger((int)($data['area_id'] ?? $previousData['area_id'] ?? 0), 'onSave', $fields, $data, $previousData);

        // Anmeldename eines Profils: user_* ist für anonymisierte Profile reserviert
        // (Modul Privacy, Lösch-Policy) - erlaubt ist nur user_{record_id} dieses
        // Datensatzes, also nicht bei der Registrierung
        $this->checkReservedLoginName($fields, $data, $previousData);

        // instance_id
        // --------------------
        // Kein Eingabefeld: der Datensatz gehört zu der Website, zu der sein
        // Inhaltsbereich gehört. Der Wert wird bei JEDEM Speichern von dort geholt -
        // so wandert ein Datensatz, der den Bereich wechselt, gleich mit.
        // (Area::hookOnSaved() deckt den umgekehrten Fall ab: der Bereich selbst
        //  wechselt die Instanz, seine Datensätze bleiben stehen.)
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId > 0) {
            $data['instance_id'] = $this->areaInstanceId($areaId);
            if (!in_array('instance_id', $fields, true)) $fields[] = 'instance_id';
        }

        // owner_id
        // --------------------
        // Ändern nur mit dem Zusatzrecht "Owner anpassen" der Benutzergruppe im
        // Bereich (Lookup-Feld am Ende der Maske, RecordBackendEdit). Ohne das
        // Recht bleibt der gespeicherte Owner - beim Anlegen setzt Dataset::save()
        // den Session-Admin (12-group-rights.md).
        if (in_array('owner_id', $fields, true) && $areaId > 0) {
            $access = $this->container->get(\Camindo\Core\Access::class);
            // (ein geleertes Lookup-Feld nimmt dem Datensatz nicht den Owner; ein
            //  Nicht-Superuser darf nur Admins der eigenen Gruppe zum Owner machen)
            $newOwnerId = (int)($data['owner_id'] ?? 0);
            $keep = $newOwnerId < 1;
            if (!$keep && $access->isLoaded() && !$access->isSuperuser()) {
                $keep = !$access->areaSetOwner($areaId) || $access->groupOf($newOwnerId) !== $access->groupId();
            }
            if ($keep) {
                if (!empty($previousData) && array_key_exists('owner_id', $previousData)) {
                    $data['owner_id'] = (int)$previousData['owner_id'];
                }
                else {
                    unset($data['owner_id']);
                }
            }
        }

        // Bereichswechsel (MoveTrait::moveToArea())
        // --------------------
        // Die Inhalte in den Feldern des bisherigen Bereichs müssen weg, BEVOR
        // saveVirtualFields() die Felder des neuen schreibt - siehe
        // MoveTrait::removeAreaData(). Die Dateien folgen in hookOnSaved().
        // Nur beim endgültigen Speichern: ein Einzelschritt (Reiterwechsel in der
        // Eingabemaske einer verschobenen Arbeitskopie, Simulation) schreibt nur
        // den Arbeitsstand der Kopie - das Original bleibt bis zum Check-in, wie
        // es ist, und kann per Abbrechen unverändert zurückbleiben.
        $recordId  = (int)($data['record_id'] ?? 0);
        $oldAreaId = (int)($previousData['area_id'] ?? 0);
        if (!$this->savingTemporary && $recordId > 0 && $oldAreaId > 0 && $areaId > 0 && $oldAreaId !== $areaId) {
            $this->removeAreaData($recordId, $oldAreaId);
        }

        // slug
        // --------------------
        // Feld gar nicht im Spiel? (Bereich ohne Slug-Feld, oder nicht übermittelt)
        if (!in_array('slug', $fields, true)) return;

        // Mehrsprachiges Slug-Feld: je Sprache ein Slug (Array language_id => Wert,
        // siehe Dataset::validateFieldValue()). Die Regeln gelten je Sprache; als
        // Quelle dient das Default-Feld in derselben Sprache, sofern es mehrsprachig
        // ist, sonst sein einziger Wert. Page::resolveRequest() löst einen Slug über
        // Cms::select() in der angeforderten Sprache auf - jede Sprache findet also
        // ihren eigenen.
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        $isMultilingual = is_array($data['slug'] ?? null);

        // Default-Feld des Bereichs suchen (area.default_field_id spiegelt sich in
        // field.default_field, siehe Area::hookOnSaved())
        $sourceName = '';
        if ($areaId > 0) {
            foreach ($this->virtualSchema($areaId, false) as $name => $colDef) {
                if ((int)($colDef['default_field'] ?? 0) !== 1) continue;
                $sourceName = $name;
                break;
            }
        }
        $langCtx = $isMultilingual ? $this->languageContext($areaId) : [];
        $primaryId = (int)($langCtx['primary_id'] ?? 0);

        $slugs = $isMultilingual ? $data['slug'] : [null => $data['slug'] ?? ''];
        foreach ($slugs as $languageId => $slug) {
            // Ein von Hand eingetragener Slug durchläuft dieselbe Normalisierung wie
            // der aus dem Default-Feld erzeugte; bleibt davon nichts übrig ("###"),
            // gilt er als leer
            $slug = trim((string)$slug);
            if ($slug !== '') $slug = $this->cms->textToUri($slug);

            if ($slug === '') {
                // Eine weitere Sprache ohne Slug bleibt leer: dann gilt der Fallback
                // aus der Hauptsprache (saveVirtualFields())
                if ($isMultilingual && (int)$languageId !== $primaryId) continue;

                $source = ($sourceName !== '') ? ($data[$sourceName] ?? '') : '';
                if (is_array($source)) {
                    // mehrsprachiges Default-Feld: Wert derselben Sprache, sonst Hauptsprache
                    $source = $source[$languageId] ?? $source[$primaryId] ?? (reset($source) ?: '');
                    if (is_array($source)) $source = '';
                }
                $source = trim((string)$source);

                // Kein Default-Feld, leerer Titel oder ein Titel ganz ohne verwertbare
                // Zeichen ("###") -> Slug bleibt leer, die URL läuft über die Id
                if ($source === '') continue;
                $slug = $this->cms->textToUri($source);
                if ($slug === '') continue;
            }

            // Reine Ziffernfolge -> Minus voranstellen, siehe Kommentar oben
            if (preg_match('/^\d+$/', $slug)) $slug = '-' . $slug;

            if ($isMultilingual) $data['slug'][$languageId] = $slug;
            else $data['slug'] = $slug;
        }
    }

    /**
     * Eindeutigkeit eines Slugs (Flag 'unique', das Field::hookOnSave() dem Feld
     * `slug` immer gibt) - aufgerufen aus Dataset::validateValue().
     *
     * Eindeutig sein muss nicht der Wert, sondern der URL-Pfad: Slug-Präfix des
     * Bereichs + Slug (Cms::recordUrl(), Cms::recordIdsBySlugPath()). Innerhalb der
     * Instanz und der mit ihr zusammengeschalteten Instanzen (InstanceCache::
     * uniqueScope()) gilt je Sprache:
     *
     *   1. Hat der Bereich in dieser Sprache keinen Präfix, darf der erste Pfadteil
     *      des Slugs nicht der Präfix eines anderen Bereichs sein ("farben/gelb"
     *      in einem Bereich ohne Präfix, während der Bereich Farben "farben" führt) -
     *      der Aufruf /farben/gelb landete sonst im Bereich Farben.
     *   2. Kein anderer Datensatz eines Bereichs mit demselben Präfix (bzw. ebenfalls
     *      ohne) trägt denselben Slug. Gleiche Slugs in Bereichen mit verschiedenen
     *      Präfixen sind erlaubt: /farben/rot und /rot.
     *
     * Sprachen: bei einem mehrsprachigen Feld die des Wertes, sonst alle Sprachen der
     * Instanz (der Präfix kann je Sprache verschieden sein). Ein leerer Slug ist nie
     * ein Konflikt - die URL läuft dann über die record_id.
     *
     * @param int $excludeId  der Datensatz selbst (produktiv); eine Arbeitskopie
     *                        fällt über tmp = 0 ohnehin heraus
     * @return bool  false: Meldung steht in Cms::errors (unter $errName)
     */
    protected function slugPathOk(string $slug, array $colDef, string $errName, string $label, int $excludeId, int $areaId, ?int $languageId, int $instanceId): bool
    {
        $slug = trim($slug);
        if ($slug === '' || $areaId < 1) return true;
        $cache = $this->container->get(InstanceCache::class);
        if ($instanceId < 1) $instanceId = $cache->areaInstanceId($areaId);
        $scope = $cache->uniqueScope($instanceId) ?: [$instanceId];
        $languageIds = ($languageId !== null) ? [$languageId] : $cache->languageIds($instanceId);
        $lower = fn(string $text): string => mb_strtolower($text, 'UTF-8');

        // 1. reservierter Präfix
        // --------------------
        $first = $lower(explode('/', $slug, 2)[0]);
        $prefixAreas = $cache->slugPrefixAreas($scope);
        foreach ($languageIds as $lang) {
            if ($cache->slugPrefix($areaId, (int)$lang) !== '') continue;
            foreach (array_keys($prefixAreas) as $otherId) {
                $prefix = $cache->slugPrefix($otherId, (int)$lang);
                if ($prefix === '' || $lower($prefix) !== $first) continue;
                $this->cms->error(strtr($this->cms->snippet('record.error_slug_prefix_reserved'), [
                    '{prefix}' => htmlspecialchars($prefix . '/'),
                    '{area}'   => htmlspecialchars($this->areaLabel($otherId)),
                ]), $errName, $this->cms->textOrSnippet($label));
                return false;
            }
        }

        // 2. derselbe Pfad
        // --------------------
        $vf = $this->cms->resolveVirtualField('slug');
        $suffix = $this->cms->dataSuffix[(string)($colDef['type'] ?? 'chars')] ?? '';
        if ($vf === null || $suffix === '') return true;
        $sql = "SELECT `d`.`language_id`, `r`.`area_id` FROM `data_{$suffix}` `d`"
             . " INNER JOIN `record` `r` ON `r`.`record_id` = `d`.`record_id` AND `r`.`tmp` = 0 AND `r`.`deleted` = 0"
             . " WHERE `d`.`fieldname_id` = ? AND `d`.`data` = ? AND `d`.`record_id` != ?"
             . " AND `r`.`instance_id` IN (" . implode(',', array_map('intval', $scope)) . ")";
        $types = 'isi';
        $values = [(int)$vf['fieldname_id'], $slug, $excludeId];
        if ($languageId !== null) {
            $sql .= " AND `d`.`language_id` = ?";
            $types .= 'i';
            $values[] = $languageId;
        }
        $this->db->pushResult();
        $this->db->query($sql . " LIMIT 200", $types, $values);
        $rows = $this->db->fetchAll();
        $this->db->popResult();
        foreach ($rows as $row) {
            $lang = (int)$row['language_id'];
            if ($lower($cache->slugPrefix((int)$row['area_id'], $lang)) !== $lower($cache->slugPrefix($areaId, $lang))) continue;
            $this->createFieldError('error_unique_value', $errName, $label);
            return false;
        }
        return true;
    }

    /**
     * Beschriftung eines Bereichs für Meldungen: "Label (name)".
     */
    private function areaLabel(int $areaId): string
    {
        $this->db->pushResult();
        $this->db->query("SELECT `label`, `name` FROM `area` WHERE `area_id` = ?", 'i', [$areaId]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return (string)$areaId;
        return $this->cms->_strip_tags($this->cms->textOrSnippet((string)$row['label'])) . ' (' . $row['name'] . ')';
    }

    /**
     * Nach dem Speichern: Bereichswechsel - die Mediendateien der alten Felder
     * löschen (Gegenstück zu hookOnSave(), siehe MoveTrait::removeAreaFiles()).
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $this->removeAreaFiles();
        $this->trigger((int)($data['area_id'] ?? $previousData['area_id'] ?? 0), 'onSaved', $fields, $data, $previousData);
    }

    /**
     * Ereignis an die Trigger-Templates des Bereichs weitergeben
     * (`rel_area_trigger`, siehe TriggerTemplates).
     */
    private function trigger(int $areaId, string $event, mixed &...$args): void
    {
        $this->container->get(TriggerTemplates::class)->run($this, $areaId, $event, $this->savingTemporary, ...$args);
    }

    // ====================
    // Rechte der Benutzergruppe (12-group-rights.md)

    /**
     * Sprachliste auf die lesbaren Sprachen der Benutzergruppe beschränken
     * (Reiter der Detailansicht und Eingabemaske). Ohne geladene Rechte /
     * Superuser unverändert; Reihenfolge bleibt die der Instanz.
     *
     * @param array $languages  language_id => Sprachzeile (InstanceCache::languages())
     */
    public function readableLanguages(array $languages): array
    {
        $access = $this->container->get(\Camindo\Core\Access::class);
        if (!$access->isLoaded() || $access->isSuperuser()) return $languages;
        return array_filter($languages, fn($languageId) => $access->languageRead((int)$languageId), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Darf die Gruppe die Zielbereiche ALLER Verknüpfungsfelder eines Bereichs
     * lesen? Sonst lassen sich seine Datensätze nicht verschieben (die
     * Verknüpfungen könnten nicht mitgenommen werden).
     */
    public function relationTargetsReadable(int $areaId): bool
    {
        foreach ($this->virtualSchema($areaId, false) as $colDef) {
            if (!$this->relationTargetReadable($colDef)) return false;
        }
        return true;
    }

    /**
     * Arbeitsstand einer Arbeitskopie - für die Eingabemaske, die daraus
     * die Werte aller Sprachen holt (RecordBackendEdit::_editLanguageValues()).
     *
     * @param int $tmpId  Id der Arbeitskopie
     * @return array      Arbeitsstand-Format, siehe VirtualFieldsTrait::_loadTmpData()
     */
    public function loadTmpData(int $tmpId): array
    {
        return $this->_loadTmpData($tmpId);
    }

    /**
     * Datei- und Passwort-Eingaben an einer Arbeitskopie sichern, ohne zu speichern -
     * das, was Dataset::save() vor der Eingabeprüfung tut (secureUploads(),
     * securePasswords()). Für die VORLAGE der Stapelbearbeitung (RecordBackendBatch):
     * sie wird nie selbst gespeichert, ihre Dateien (physisch unter ihrer Id, im
     * Arbeitsstand mit 'temporary') und Passwort-Hashes wandern beim Ausführen in
     * jeden Datensatz des Umfangs (RecordBatch::editJob()).
     *
     * @param int   $tmpId  Arbeitskopie des Session-Admins
     * @param array $data   Eingaben (data[...] der Maske; Uploads aus $_FILES)
     * @return bool         false: Datei abgelehnt oder fremde Arbeitskopie (Cms::errors)
     */
    public function secureInputs(int $tmpId, array $data): bool
    {
        if (!$prodData = $this->getProdData($tmpId)) {
            $this->cms->error($this->cms->snippet('error_tmp_record_access_denied'));
            return false;
        }
        $schemaVirtual = $this->virtualSchema((int)$prodData[1], false);
        if (!$this->secureUploads($tmpId, $schemaVirtual, $data)) return false;
        $this->securePasswords($tmpId, $schemaVirtual, $data);
        return true;
    }

    /**
     * Sprachen der Instanz eines Datensatzes (Hauptsprache zuerst) - auch für
     * Arbeitskopien, die tragen die instance_id ihres Bereichs.
     *
     * @return int[]
     */
    public function languageIdsOf(int $recordId): array
    {
        return $this->recordLanguageIds($recordId);
    }

    /**
     * Vor dem "gelöscht"-Markieren (Soft-Delete)
     *
     * Schiebt die Mediendateien des Datensatzes in den Papierkorb var/deleted
     * ({fieldId}_{recordId}_{version}.{ext}). Die `data_fil`-Einträge bleiben stehen -
     * ohne sie wären Feld, Extension und Version beim Wiederherstellen nicht mehr
     * zu ermitteln.
     *
     * Läuft je Datensatz genau einmal, auch bei Massenlöschungen: Dataset::delete()
     * schickt jeden Datensatz einzeln durch softDeleteOne().
     */
    protected function hookOnSoftDelete(array $data): void
    {
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onSoftDelete', $data);
        $this->trashRecordFiles($recordId);
        // Fest verknüpfte Inhalte (link) gehen mit in den Papierkorb
        $this->cascadeDependents($recordId, 'delete');
    }

    /**
     * Anmeldename eines Profil-Datensatzes prüfen (hookOnSave()): user_* ist den
     * Profilen vorbehalten, die eine Lösch-Policy anonymisiert hat - erlaubt ist
     * genau user_{record_id} des Datensatzes selbst (Arbeitskopie: des Originals).
     */
    private function checkReservedLoginName(array $fields, array $data, array $previousData): void
    {
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId < 1) return;
        $this->db->pushResult();
        try {
            $this->db->query("SELECT f.`name`, f.`label` FROM `area` a JOIN `field` f ON f.`field_id` = a.`login_name_field_id`"
                . " WHERE a.`area_id` = ? AND a.`type` = 'profile'", 'i', [$areaId]);
            $field = $this->db->fetch();
        }
        finally {
            $this->db->popResult();
        }
        if (!$field) return;
        $name = (string)$field['name'];
        if ((!empty($fields) && !in_array($name, $fields, true)) || !array_key_exists($name, $data)) return;
        $values = is_array($data[$name]) ? $data[$name] : [$data[$name]];
        $previous = array_map('strval', (array)($previousData[$name] ?? []));
        $prodId = (int)($data['origin_id'] ?? 0) ?: (int)($previousData['origin_id'] ?? 0);
        foreach ($values as $value) {
            $value = trim((string)$value);
            if (!preg_match('/^user_/i', $value)) continue;
            // unverändert (schon anonymisiert) oder genau der eigene Name
            if (in_array($value, $previous, true) || ($prodId > 0 && $value === 'user_' . $prodId)) continue;
            $this->createFieldError('privacy.error_login_reserved', $name, (string)$field['label']);
            throw new \Exception('#' . $this->cms->errorMsgLast());
        }
    }

    /**
     * Vor dem Wiederherstellen
     *
     * Gegenstück zu hookOnSoftDelete(): holt die Mediendateien aus dem Papierkorb
     * zurück ins Medienverzeichnis.
     */
    protected function hookOnRestore(array $data): void
    {
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onRestore', $data);
        $this->untrashRecordFiles($recordId);
        // Offene Aufträge der Lösch-Policy (Modul Privacy) verfallen - der Datensatz lebt weiter
        $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->cancel($recordId);
        // Fest verknüpfte Inhalte (link) desselben Löschvorgangs kommen mit zurück
        $this->cascadeDependents($recordId, 'restore');
    }

    /**
     * Vor dem endgültigen Löschen...
     * Läuft nur beim endgültigen Löschen (Systemtool: deleted=1, älter als
     * admin.trash_retention_days). Beim Soft-Delete greift stattdessen
     * hookOnSoftDelete() - dort werden Dateien nur verschoben, nicht gelöscht.
     *
     * Muss hier im Hook geschehen, weil Dataset::finalDeleteOne() hookOnDelete() VOR
     * dem DELETE der record-Zeile aufruft: danach fallen die data_fil-Einträge per
     * FK-Cascade weg und Extension und Version wären nicht mehr ermittelbar.
     * Tree-Kinder werden nicht beachtet - Dataset::purge() schickt jeden Datensatz
     * des Teilbaums einzeln hier durch, Blätter zuerst.
     */
    protected function hookOnDelete(array $data): void
    {
        // Dataset::finalDeleteOne() -> loadProductive() liefert hier immer den
        // produktiven Datensatz (temporäre Id wurde bereits aufgelöst).
        $recordId = (int)($data['record_id'] ?? 0);
        if ($recordId < 1) return;
        $this->trigger((int)($data['area_id'] ?? 0), 'onDelete', $data);

        // Mediendateien endgültig entfernen - im Papierkorb (Normalfall, der Datensatz
        // war soft-gelöscht) UND im Medienverzeichnis (falls das Verschieben beim
        // Soft-Delete fehlschlug). Räumt auch die Dateien noch vorhandener
        // Arbeitskopien mit ab: Dataset::finalDeleteOne() entfernt deren Zeilen im
        // selben Vorgang per SQL, ohne eigenen hookOnDelete().
        $this->purgeRecordFiles($recordId);
        // Gab es für den Datensatz nie Aufträge der Lösch-Policy (etwa vor ihrer
        // Einführung gelöscht), behandelt die Policy des Bereichs wenigstens seinen
        // Verlauf im Audit-Log (Blöcke des Auslösers 'area')
        $this->container->get(\Camindo\Core\Modules\Privacy\PrivacyJobs::class)->scheduleWithoutPolicy($recordId);
        // Fest verknüpfte Inhalte (link): endgültig mit löschen - VOR dem DELETE der
        // Zeile, danach wären ihre Kanten per FK-Cascade weg
        $this->cascadeDependents($recordId, 'purge');
    }

    /**
     * Nach dem endgültigen Löschen: nur das Trigger-Template.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->trigger((int)($data['area_id'] ?? 0), 'onDeleted', $data);
    }

    /**
     * Bearbeitung abbrechen (TemporaryTrait::cancel()) - mit den Arbeitskopien der
     * fest verknüpften Inhalte (LinkTrait::cancelDependents()), bevor die Kanten
     * mit der Arbeitskopie verschwinden.
     */
    public function cancel(int $tmpId): void
    {
        if ($tmpId < 1) return;
        if ($this->getProdData($tmpId)) {
            $this->cancelDependents($tmpId);
        }
        $this->tmpCancel($tmpId);
    }

    /**
     * Alle offenen Arbeitskopien des Session-Admins verwerfen - beim Abmelden
     * (StartBackend::_logout()). Damit fallen seine Bearbeitungssperren sofort und
     * nicht erst nach admin.edit_lock_sec (TemporaryTrait::getLock()).
     *
     * Verworfen wird auch die Arbeitskopie eines noch nie gespeicherten neuen
     * Datensatzes (ohne origin_id): nach dem Abmelden ist keine Maske mehr offen, die
     * sie noch speichern könnte. Bereits ungültig gemachte Kopien (`deleted` = 1)
     * sperren nichts mehr und bleiben dem Aufräumen im Modul Tools überlassen.
     *
     * Die Kopien fest verknüpfter Inhalte hängen am selben Admin und fallen mit
     * ihrer Quelle (cancel() -> cancelDependents()) oder, falls die schon weg ist,
     * einzeln - cancel() übergeht eine bereits verworfene Id.
     *
     * @return int  Anzahl der gefundenen Arbeitskopien
     */
    public function cancelAll(): int
    {
        return $this->_cancelWorkingCopies(null);
    }

    /**
     * Die Arbeitskopien einer Session verwerfen (Session::registerWorkingCopy()) -
     * beim Abmelden nur dieses Browsers. Masken, die derselbe Admin auf einem anderen
     * Gerät offen hat, bleiben bestehen.
     *
     * @return int  Anzahl der gefundenen Arbeitskopien
     */
    public function cancelSession(string $sessionId): int
    {
        if ($sessionId === '') return 0;
        return $this->_cancelWorkingCopies($sessionId);
    }

    /**
     * Die Arbeitskopien aller ANDEREN Sessions des Session-Admins verwerfen - bei
     * "Alle anderen Sitzungen beenden" (AdminBackend::devices()), wie das Abmelden
     * auf allen Geräten, nur ohne die eigene Session: deren offene Masken bleiben.
     * Erfasst werden die Kopien, die einer anderen Session zugeordnet sind
     * (Session::registerWorkingCopy()); Kopien fest verknüpfter Inhalte fallen mit
     * ihrer Quelle. Vor Session::closeAllOf() aufrufen - das löscht die Zuordnungen.
     *
     * @param string $keepSessionId  die laufende Session
     * @return int  Anzahl der gefundenen Arbeitskopien
     */
    public function cancelOtherSessions(string $keepSessionId): int
    {
        if ($keepSessionId === '') return 0;
        return $this->_cancelWorkingCopies($keepSessionId, except: true);
    }

    /**
     * Arbeitskopien des Session-Admins verwerfen: alle ($sessionId = null), die
     * einer Session oder ($except) die aller übrigen Sessions.
     */
    private function _cancelWorkingCopies(?string $sessionId, bool $except = false): int
    {
        $adminId = (int)$this->session->get('admin_id');
        if ($adminId < 1) return 0;

        $this->db->pushResult();
        if ($sessionId === null) {
            $this->db->query(
                "SELECT `record_id` FROM `record` WHERE `tmp` = 1 AND `deleted` = 0 AND `changed_by` = ? ORDER BY `record_id`",
                'i',
                [$adminId]
            );
        }
        else {
            $this->db->query(
                "SELECT `record`.`record_id` FROM `record`"
                . " JOIN `admin_session_record` ON `admin_session_record`.`record_id` = `record`.`record_id`"
                . " WHERE `admin_session_record`.`session_id` " . ($except ? '<>' : '=') . " ? AND `tmp` = 1 AND `deleted` = 0 AND `changed_by` = ?"
                . " ORDER BY `record`.`record_id`",
                'si',
                [$sessionId, $adminId]
            );
        }
        $tmpIds = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();

        foreach ($tmpIds as $tmpId) {
            $this->cancel($tmpId);
        }
        return count($tmpIds);
    }
       

    // ====================
    // Import (RecordImport)

    /**
     * Speichern für den Import: wie save(), nur dass Werte aus einer Exportdatei
     * bereits im Datenbankformat vorliegen können ($storedFields, siehe
     * Dataset::$storedFormatFields) und Passwörter als fertiger Hash ($prehashed,
     * siehe Dataset::$prehashedFields). Beide Listen gelten für diesen einen Aufruf.
     *
     * @param string[] $fields         wie save()
     * @param array    $data           wie save()
     * @param string[] $storedFields   Felder im Datenbankformat
     * @param string[] $prehashed      Passwort-Felder, deren Wert der Hash ist
     * @param bool     $stayTemporary  Simulation: prüfen, kein checkIn
     * @param bool     $deferRelations erster Durchlauf: Anzahl-Prüfung der
     *                                 Verknüpfungsfelder auslassen (Dataset::$deferRelationChecks)
     */
    public function importSave(array $fields, array $data, array $storedFields = [], array $prehashed = [], bool $stayTemporary = false, bool $deferRelations = false): ?int
    {
        $this->storedFormatFields = $storedFields;
        $this->prehashedFields = $prehashed;
        $this->deferRelationChecks = $deferRelations;
        try {
            return $this->save($fields, $data, $stayTemporary);
        }
        finally {
            $this->storedFormatFields = [];
            $this->prehashedFields = [];
            $this->deferRelationChecks = false;
        }
    }

    /**
     * Eine record_id für den Import frei machen: belegt sie eine Arbeitskopie oder
     * ein gelöschter Datensatz (Papierkorb), wird dieser endgültig entfernt - samt
     * Dateien. Ein produktiver Datensatz bleibt unangetastet.
     *
     * @return string  'free' (Id ist frei bzw. wurde frei gemacht),
     *                 'productive' (belegt), 'tmp'/'deleted' wenn das Entfernen scheiterte
     */
    public function freeRecordId(int $id): string
    {
        if ($id < 1) return 'productive';
        $this->db->pushResult();
        $this->db->query("SELECT `tmp`, `deleted` FROM `record` WHERE `record_id` = ?", 'i', [$id]);
        $row = $this->db->fetch();
        $this->db->popResult();
        if (!$row) return 'free';
        if (!empty($row['tmp'])) {
            // Arbeitskopie: Dateien weg, Zeile weg (record_cache und data_rel per FK)
            $this->deleteRecordFiles($id);
            $this->db->pushResult();
            $this->db->query("DELETE FROM `record` WHERE `record_id` = ? AND `tmp` = 1", 'i', [$id]);
            $this->db->popResult();
            return $this->_recordExists($id) ? 'tmp' : 'free';
        }
        if (!empty($row['deleted'])) {
            // Papierkorb: endgültig löschen wie die Zeitplaner-Aufgabe trash (mit Hooks)
            $this->finalDelete($id);
            return $this->_recordExists($id) ? 'deleted' : 'free';
        }
        return 'productive';
    }

    private function _recordExists(int $id): bool
    {
        $this->db->pushResult();
        $this->db->query("SELECT 1 FROM `record` WHERE `record_id` = ?", 'i', [$id]);
        $exists = $this->db->num_rows > 0;
        $this->db->popResult();
        return $exists;
    }

    /**
     * Ursprünglichen Dateinamen einer Datei ändern (Info-Seite, Stift hinter
     * "Original filename") - ohne Arbeitskopie, direkt am produktiven Datensatz.
     *
     * Nur der Name vor der Endung ist wählbar; die Endung bleibt die der Datei
     * (`data_fil`.extension). Geändert werden alle Zeilen, die dieselbe physische
     * Datei beschreiben: bei einsprachigen Feldern die Zeilen aller Sprachen
     * (file_language_id NULL), bei mehrsprachigen die eigene Zeile der Sprache
     * samt der Fallback-Zeilen, die auf sie verweisen. `version` bleibt - die
     * Datei selbst ist unverändert, und der Name im Verweis ändert sich ohnehin
     * (Cms::cmsMedia()). Der Inhalts-Cache wird verworfen und beim nächsten
     * Zugriff neu aufgebaut.
     *
     * Abgelehnt, solange eine Arbeitskopie besteht (auch die eigene): ihr
     * Check-in schriebe den alten Namen zurück.
     *
     * @param int      $recordId        produktiver Datensatz
     * @param int      $fieldId         field_id des Datei-Feldes
     * @param ?int     $fileLanguageId  Sprache der Datei (null: einsprachiges Feld)
     * @param string   $baseName        neuer Name ohne Endung
     * @return ?string                  der gespeicherte Dateiname, null bei Fehler (cms->error)
     */
    public function renameFile(int $recordId, int $fieldId, ?int $fileLanguageId, string $baseName): ?string
    {
        $this->db->pushResult();
        try {
            $this->db->query(
                'SELECT `extension` FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?'
                . ' AND `file_language_id` <=> ? AND `size` > 0 LIMIT 1',
                'iii', [$recordId, $fieldId, $fileLanguageId]
            );
            $row = $this->db->fetch();
            if (!$row) {
                $this->cms->error($this->cms->snippet('record.error_file_rename_nofile'));
                return null;
            }
            $extension = (string)$row['extension'];

            // Arbeitskopie vorhanden (nicht verwaist, siehe getLock())?
            $outdatedAt = gmdate('Y-m-d H:i:s', time() - (int)$this->config->get('admin.edit_lock_sec'));
            $this->db->query(
                'SELECT 1 FROM `record` WHERE `tmp` = 1 AND `deleted` = 0 AND `origin_id` = ? AND `changed_at` >= ?',
                'is', [$recordId, $outdatedAt]
            );
            if ($this->db->num_rows > 0) {
                $this->cms->error($this->cms->snippet('record.error_file_rename_locked'));
                return null;
            }

            // Endung abschneiden, falls mit eingegeben; dann säubern wie beim Upload
            $baseName = trim($baseName);
            if ($extension !== '' && str_ends_with(strtolower($baseName), '.' . strtolower($extension))) {
                $baseName = substr($baseName, 0, -strlen($extension) - 1);
            }
            $baseName = rtrim($baseName, '. ');
            if ($baseName === '') {
                $this->cms->error($this->cms->snippet('error_value_mandatory'));
                return null;
            }
            $filename = DirectoryHelpers::sanitizeFilename($baseName . ($extension !== '' ? '.' . $extension : ''));

            $this->db->query(
                'UPDATE `data_fil` SET `filename` = ?'
                . ' WHERE `record_id` = ? AND `field_id` = ? AND `file_language_id` <=> ?',
                'siii', [$filename, $recordId, $fieldId, $fileLanguageId]
            );
            // Inhalts-Cache verwerfen: Cms::fetch() baut ihn aus den data_*-Zeilen neu auf
            $this->db->query('DELETE FROM `record_cache` WHERE `record_id` = ?', 'i', [$recordId]);
            // Volltext: der Dateiname ist Quelle (Upload-Felder)
            $this->affectedTextFieldIds = [$fieldId];
            $this->refreshAffectedFulltext($recordId);
            return $filename;
        } finally {
            $this->db->popResult();
        }
    }

    /**
     * Metadaten (`data_fil`) einer Datei: filename, extension, mime_type, size ...
     *
     * @param ?int $fileLanguageId  Sprache der Datei (null: einsprachiges Feld)
     */
    public function fileRow(int $recordId, int $fieldId, ?int $fileLanguageId): ?array
    {
        $this->db->pushResult();
        $this->db->query(
            'SELECT * FROM `data_fil` WHERE `record_id` = ? AND `field_id` = ?'
            . ' AND `file_language_id` <=> ? LIMIT 1',
            'iii', [$recordId, $fieldId, $fileLanguageId]
        );
        $row = $this->db->fetch();
        $this->db->popResult();
        return $row ?: null;
    }

    // ====================
    // Audit-Log

    /**
     * Schnappschuss eines Datensatzes für das Audit-Log (siehe Dataset::auditSnapshot()):
     * die Metafelder aus `record`, jedes Feld des Bereichs aus dem Inhalts-Cache -
     * mehrsprachige Felder je Sprache (Schlüssel "{name}@{code}", ein nicht
     * übersetzter Wert zählt als leer) - und die Verknüpfungen als Id-Liste.
     *
     *     password    Vergleich über den Hash aus data_pwd, nie ein Wert
     *     encrypted   Geheimtext aus dem Cache, Vergleich über den HMAC des exakten
     *                 Klartexts (AuditLog::encryptedEntry()) - nicht über
     *                 data_enc.hash, der ist normalisiert (Cms::encryptedHash()) und
     *                 sähe eine geänderte Groß-/Kleinschreibung nicht
     *     file        "Dateiname (Größe, B×H)", Vergleich über Name, Größe, Version
     */
    public function auditSnapshot(int $id): array
    {
        $this->db->pushResult();
        try {
            $this->db->query("SELECT * FROM `record` WHERE `record_id` = ?", 'i', [$id]);
            $row = $this->db->fetch();
            if (!$row) return [];
            $snapshot = $this->auditColumns($row, $this->schema);
            $areaId = (int)$row['area_id'];
            if ($areaId < 1) return $snapshot;

            $schemaVirtual = $this->virtualSchema($areaId, false);
            $langCtx = $this->languageContext($areaId);
            $languageIds = (array)($langCtx['language_ids'] ?? []);
            $primaryId = (int)($langCtx['primary_id'] ?? 0);
            $codes = (array)($langCtx['codes'] ?? []);
            $cache = $this->_loadRecordCacheRows($id);
            // Fehlende Sprachzeilen nachbauen: nach neuen Thumbnails verwirft
            // writeDeferredThumbnails() den Cache (Aufbau sonst erst beim nächsten
            // Lesen) - ohne ihn sähe der Schnappschuss lauter leere Felder
            $missing = array_diff(array_map('intval', $languageIds), array_keys($cache));
            if ($missing) {
                // schreibt alle Sprachzeilen auf einmal
                $this->cms->createRecordCache($id, $areaId, (int)$row['instance_id'], (int)reset($missing));
                $cache = $this->_loadRecordCacheRows($id);
            }

            // Passwort-Hashes
            $secrets = [];
            $this->db->query("SELECT `field_id`, `language_id`, `data` FROM `data_pwd` WHERE `record_id` = ?", 'i', [$id]);
            while ($r = $this->db->fetch()) $secrets[(int)$r['field_id']][(int)$r['language_id']] = hash('sha256', (string)$r['data']);
            // Verknüpfungen je Feld in ihrer Reihenfolge
            $relations = [];
            $this->db->query("SELECT `field_id`, `dest_id` FROM `data_rel` WHERE `src_id` = ? ORDER BY `field_id`, `position`, `dest_id`", 'i', [$id]);
            while ($r = $this->db->fetch()) $relations[(int)$r['field_id']][] = (int)$r['dest_id'];
        }
        finally {
            $this->db->popResult();
        }

        $auditLog = $this->container->get(\Camindo\Core\AuditLog::class);
        foreach ($schemaVirtual as $name => $colDef) {
            $name = (string)$name;
            if (isset($this->cms->schema[$name])) continue;   // Metafeld, steht schon oben
            $type = (string)($colDef['type'] ?? 'text');
            if ($type === 'fulltext') continue;   // abgeleitet aus den Quellfeldern
            $label = (string)(($colDef['label'] ?? '') ?: $name);
            $fieldId = (int)($colDef['field_id'] ?? 0);

            if (in_array($type, $this->cms->relationTypes, true)) {
                $ids = $relations[$fieldId] ?? [];
                $snapshot[$name] = ['l' => $label, 't' => $type, 'g' => '',
                    'v' => implode(', ', array_map(fn(int $d) => '#' . $d, $ids))];
                continue;
            }

            $multilingual = $this->cms->isMultilingualField($colDef) && count($languageIds) > 1;
            foreach ($multilingual ? $languageIds : [$primaryId] as $lang) {
                $lang = (int)$lang;
                $code = $multilingual ? (string)($codes[$lang] ?? $lang) : '';
                $key = $multilingual ? $name . '@' . $code : $name;
                $entry = $cache[$lang][$name] ?? null;
                $fallback = !empty($entry[2]);
                $value = $fallback ? '' : ($entry[1] ?? '');

                if ($type === 'password' || ($colDef['display_type'] ?? '') === 'password') {
                    $snapshot[$key] = ['l' => $label, 't' => 'password', 'g' => $code, 'v' => '',
                        'c' => (string)($secrets[$fieldId][$lang] ?? $secrets[$fieldId][$primaryId] ?? '')];
                }
                elseif ($type === 'encrypted') {
                    $cipher = is_string($value) && $value !== '' ? (string)base64_decode($value, true) : '';
                    $snapshot[$key] = $auditLog->encryptedEntry($label, $cipher, $code);
                }
                elseif ($type === 'file') {
                    $file = is_array($value) ? $value : [];
                    $filename = (string)($file['filename'] ?? '');
                    $size = (int)($file['size'] ?? 0);
                    $text = '';
                    if ($filename !== '' || $size > 0) {
                        $text = ($filename !== '' ? $filename : '.' . (string)($file['extension'] ?? ''))
                            . ' (' . $this->system->convertSize((float)$size)
                            . (!empty($file['width']) ? ', ' . (int)$file['width'] . '×' . (int)$file['height'] : '') . ')';
                    }
                    $snapshot[$key] = ['l' => $label, 't' => 'file', 'g' => $code, 'v' => $text,
                        'c' => $text === '' ? '' : $text . '|' . (int)($file['version'] ?? 0)];
                }
                else {
                    $snapshot[$key] = ['l' => $label, 't' => $type, 'g' => $code,
                        'v' => is_array($value) ? (string)json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value];
                }
            }
        }
        return $snapshot;
    }

    /**
     * Bezeichnung fürs Audit-Log: Inhalt des Default-Feldes des Bereichs
     * (area.default_field_id) in der Hauptsprache.
     */
    protected function auditLabel(int $id, array $snapshot): string
    {
        $label = $this->auditLabelOf($id, $snapshot);
        return $label !== '' ? $label : '#' . $id;
    }

    /**
     * Bezeichnung aus einem Schnappschuss - '' ohne Default-Feld oder Inhalt
     * (auch für RecordPersonalData).
     */
    public function auditLabelOf(int $id, array $snapshot): string
    {
        $areaId = (int)($snapshot['area_id']['v'] ?? 0);
        if ($areaId > 0) {
            $this->db->pushResult();
            try {
                $this->db->query(
                    "SELECT f.`name` FROM `area` a JOIN `field` f ON f.`field_id` = a.`default_field_id` WHERE a.`area_id` = ?",
                    'i', [$areaId]
                );
                $name = (string)($this->db->fetchNum()[0] ?? '');
            }
            finally {
                $this->db->popResult();
            }
            if ($name !== '') {
                foreach ($snapshot as $key => $entry) {
                    if (($key === $name || str_starts_with((string)$key, $name . '@')) && (string)($entry['v'] ?? '') !== ''
                        && !in_array($entry['t'] ?? '', ['password', 'encrypted'], true)) {
                        return mb_substr(strip_tags((string)$entry['v']), 0, 200);
                    }
                }
            }
        }
        return '';
    }
}
