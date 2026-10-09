<?php
/**
 * Dataset-Kindklasse für die Tabelle `instance` - eine "Instance" ist eine Website
 * bzw. ein eigenständiger Frontend-Auftritt.
 *
 * Die Tabelle selbst ist schmal; der größere Teil der Angaben liegt in drei
 * Relation-Tabellen, die über Felder vom Typ 'reltable' im Schema vertreten sind
 * (siehe Cms::$dataSuffix):
 *
 *     additional_languages   `rel_instance_language`   Sprachen neben der Hauptsprache
 *     additional_domains     `rel_instance_domain`     Domains neben der Haupt-Domain
 *     readable_instances     `rel_instance_instance`   fremde Instanzen, deren Inhalte
 *                                                      im Frontend lesbar sind
 *
 * FELDER OHNE EIGENE SPALTE ('reltable')
 * --------------------
 * Der Typ steht nur deshalb im Schema, damit Dataset::save() seine Eingabeprüfung
 * (min/max, Pflichtfeld) auch auf diese Felder anwendet. Dataset::save() übergeht
 * sie beim Schreiben der Tabellenspalten - gelesen und geschrieben werden sie hier:
 *
 *     hookOnLoaded()   Relation-Tabelle -> $data
 *     hookOnSaved()    $data -> Relation-Tabelle   (innerhalb der Transaktion)
 *
 * ZUGEHÖRIGKEIT DER INHALTE
 * --------------------
 * Ein Inhaltsbereich (`area`, alle drei Typen) gehört über `area`.`instance_id` zu
 * genau einer Instanz. Seine Datensätze und Templates erben die Zuordnung:
 * `record`.`instance_id` wird von Record::hookOnSave() aus dem Bereich geholt,
 * Templates hängen über fk_template_area_id am Bereich.
 *
 * DATEICACHE UND SPRACHEN
 * --------------------
 * Das Frontend liest die Instanzen nicht aus der Datenbank, sondern aus
 * var/cache/instances.php und instance-{id}.php (Camindo\Core\InstanceCache).
 * hookOnSaved() und hookOnDeleted() schreiben diese Dateien neu. Ändert sich die
 * Sprachliste einer Instanz, zieht hookOnSaved() außerdem die Inhaltszeilen der
 * Datensätze nach (Camindo\Core\LanguageData): je Sprache der Instanz eine Zeile
 * pro Feld, siehe VirtualFieldsTrait::saveVirtualFields().
 *
 * MEDIENVERZEICHNIS
 * --------------------
 * Die Mediendateien liegen je Instanz unter media/{instance_id}/{field_id}/. Jedes
 * Instanz-Verzeichnis trägt eine .htaccess, die den direkten Zugriff auf die
 * Domains der Instanz und ihrer lesenden Instanzen beschränkt. Beide Hooks
 * schreiben diese Dateien für ALLE Instanzen neu (Camindo\Core\MediaAccess) -
 * die Sperre von B hängt davon ab, ob A sie lesen darf.
 *
 * DIE STANDARD-INSTANCE
 * --------------------
 * `instance_id` 1 (Instance::DEFAULT_ID) ist das Sammelbecken für alles was keine
 * konfigurierte Domain matcht. Zusatz-Domains ist immer ".*" (PCRE)
 * Geändert werden kann aber alles andere.
 * Sie steht überall, wo Instanzen aufgezählt werden, am Ende - unabhängig von ihrer
 * `position` (orderSql(), InstanceCache) - und lässt sich nicht löschen: ohne sie
 * liefe jeder Aufruf ins Leere, dessen Domain nirgends eingetragen ist.
 *
 * @see Camindo\Core\Modules\Instance\InstanceBackend
 * @see Camindo\Core\Modules\Area\Area   Zuordnung der Inhaltsbereiche
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Instance;

use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\LanguageData;
use Camindo\Core\MediaAccess;
use Camindo\Core\SecurityHeaders;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Snippets\Snippets;

class Instance extends Dataset {

    /**
     * Die Standard-Instance - siehe Klassenkommentar.
     * Auch der Datenbank-Default von `area`.`instance_id` und `record`.`instance_id`.
     */
    public const DEFAULT_ID = 1;

    /**
     * ORDER-BY-Ausdruck für die Reihenfolge der Instanzen: nach `position`, die
     * Standard-Instance immer zuletzt (Klassenkommentar). Systemweit dieselbe
     * Reihenfolge - Abfragen, die nach Instanzen sortieren, nehmen diesen Ausdruck.
     *
     * @param string $alias  Tabellen-Alias von `instance` ('' = ohne)
     */
    public static function orderSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : "`$alias`.";
        return "{$prefix}`instance_id` = " . self::DEFAULT_ID . " ASC, {$prefix}`position` ASC";
    }

    /** Höchstzahl der Domains je Instanz (Haupt-Domain nicht mitgezählt) */
    public const MAX_DOMAINS = 32;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'instance';
        $this->moduleName = 'Instance';
        // Pseudo-Feld 'instance' (Cms::select()): Reihenfolge des Instanz-Caches,
        // die Standard-Instance zuletzt
        $this->defaultSortOrder = 'instance ASC';
        $this->schema = [
            'instance_id' => [
                'type'  => 'integer',
                'label' => '#instance.field_instance_id',
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#instance.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#instance.field_position',
                'display_type' => 'select',
            ],
            // `instance`.`primary_language_id` ist NOT NULL (kein Fremdschlüssel),
            // deshalb 'integer' und nicht 'id' - 'id' schriebe bei 0 ein NULL.
            'primary_language_id' => [
                'type'  => 'integer',
                'label' => '#instance.field_primary_language_id',
                'display_type' => 'select',
                'min'   => 0,
            ],
            // Tabelle `rel_instance_language`
            // 'sortable': die Reihenfolge der angehakten Sprachen ist der Wert
            // (sie landet als `position` in der Relation-Tabelle)
            'additional_languages' => [
                'type'  => 'reltable',
                'display_type' => 'sortable',
                'label' => '#instance.field_additional_languages',
                'tooltip' => '#instance.tooltip_additional_languages',
            ],
            'primary_domain' => [
                'type'  => 'chars',
                'label' => '#instance.field_primary_domain',
                'note'  => '#instance.note_field_domain',
                'max'   => 64,
                'flags' => ['trim'],
            ],
            'protocol' => [
                'type'  => 'chars',
                'label' => '#instance.field_protocol',
                'display_type' => 'select',
                'max'   => 5,
                'flags' => ['not_empty'],
                'default' => 'https',
            ],
            'canonical' => [
                'type'  => 'bit',
                'label' => '#instance.field_canonical',
                'tooltip' => '#instance.tooltip_canonical',
                'display_type' => 'switch',
                'default' => 1,
            ],
            // Tabelle `rel_instance_domain`
            // Das Feld hat kein eigenes Eingabefeld: die Domains stehen als
            // Zeilenpaare data[domain][] / data[regex][] im Formular (siehe
            // InstanceBackend::_editForm()). Es muss aber im Schema stehen, damit
            // hookOnSaved() es in $fields wiederfindet.
            'additional_domains' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#instance.field_additional_domains',
                'max'   => self::MAX_DOMAINS,
            ],
            // Tabelle `rel_instance_instance`
            'readable_instances' => [
                'type'  => 'reltable',
                'display_type' => 'multiselect',
                'label' => '#instance.field_readable_instances',
                'tooltip' => '#instance.tooltip_readable_instances',
                'height' => 'auto',
            ],
            // Startseite - beide Spalten sind NULLable (Fremdschlüssel mit
            // ON DELETE SET NULL), deshalb 'id': der Typ schreibt bei 0 ein NULL.
            'record_id' => [
                'type'  => 'id',
                'label' => '#instance.field_record_id',
                'tooltip' => '#instance.tooltip_record_id',
                'display_type' => 'lookup',
                'table' => 'record',
                'max'   => 1,
            ],
            'template_id' => [
                'type'  => 'id',
                'label' => '#instance.field_template_id',
                'tooltip' => '#instance.tooltip_template_id',
                'display_type' => 'lookup',
                'table' => 'template',
                'max'   => 1,
            ],
            // Zusatzangaben (JSON): Script-Policy des Frontends
            'details' => [
                'type'  => 'json',
                'label' => '#instance.field_details',
            ],
            // Script-Policy des Frontends (SecurityHeaders::frontendPolicy(),
            // developer_docs/19-csp.md §9): '' = Vorgabe security.frontend_csp
            'details.csp' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#instance.field_csp',
                'tooltip' => '#instance.tooltip_csp',
            ],
            // weitere Script-Quellen, je Zeile eine (https://js.example.com)
            'details.csp_script_sources' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#instance.field_csp_script_sources',
                'tooltip' => '#instance.tooltip_csp_script_sources',
                'max'   => 2000,
            ],
            // 'strict-dynamic': was ein vertrautes Script nachlädt, läuft auch
            'details.csp_strict_dynamic' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#instance.field_csp_strict_dynamic',
                'tooltip' => '#instance.tooltip_csp_strict_dynamic',
            ],
            // form-action: Formulare nur an die eigene Website (+ Quellen)
            'details.csp_form_action' => [
                'type'  => 'bit',
                'display_type' => 'switch',
                'label' => '#instance.field_csp_form_action',
                'tooltip' => '#instance.tooltip_csp_form_action',
            ],
            'details.csp_form_sources' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#instance.field_csp_form_sources',
                'tooltip' => '#instance.tooltip_csp_form_sources',
                'max'   => 2000,
            ],
        ];
    }

    /**
     * Vor dem Erstellen: neue Instance ans Ende der Sortierung stellen.
     * Die Position ist manuell änderbar; ohne Vorbelegung stünde jede neue Instance
     * mit position = 0 vorne.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `instance`";
        $this->db->query($sql);
        $data['position'] = (int)($this->db->fetchNum()[0] ?? 0);

        // Feld 'additional_domains': in der Tabelle `rel_instance_domain` stehen je
        // Zeile ZWEI Werte (domain + regex). Das Formular führt sie deshalb als zwei
        // parallele Arrays, nicht als einen Wert im Feld selbst.
        $data['domain'] = [];
        $data['regex']  = [];
    }

    /**
     * Vor dem Speichern: prüfen und normalisieren.
     *
     * Die Inhalte der drei 'reltable'-Felder werden hier in die Form gebracht, in der
     * hookOnSaved() sie erwartet - Dataset::save() schreibt zwischen beiden Hooks nur
     * die echten Tabellenspalten.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // Sprachen
        // --------------------
        if (in_array('additional_languages', $fields, true)) {
            $primaryLanguageId = (int)($data['primary_language_id'] ?? 0);
            // "Keine Hauptsprache" wird nicht an der 0 festgemacht - maßgeblich ist,
            // ob es die Sprache in `language` überhaupt gibt.
            $additionalLanguages = $this->system->sanitizeIds(
                (array)($data['additional_languages'] ?? [])
            );

            // Weitere Sprachen ohne gültige Hauptsprache ergeben keine Sprachfolge
            if (!empty($additionalLanguages)
                && empty($this->existingIds('language', [$primaryLanguageId]))
            ) {
                $errorMsg = $this->cms->snippet('instance.error_primary_language');
                $this->cms->error(
                    $errorMsg,
                    'primary_language_id',
                    $this->cms->textOrSnippet($this->schema['primary_language_id']['label'] ?? '')
                );
                throw new \Exception('#' . $errorMsg);
            }

            // Die Hauptsprache steht nicht noch einmal in den weiteren Sprachen
            $additionalLanguages = array_values(
                array_diff($additionalLanguages, [$primaryLanguageId])
            );

            // Nur Sprachen, die es wirklich gibt (der Fremdschlüssel würde sonst
            // erst in hookOnSaved() zuschlagen - dort ohne verwertbare Meldung)
            $additionalLanguages = $this->existingIds('language', $additionalLanguages);

            $data['additional_languages'] = $additionalLanguages;
        }

        // Domains
        // --------------------
        // Aus den beiden parallelen Arrays des Formulars eine bereinigte Liste bauen:
        // leere Zeilen fliegen raus, Domains werden kleingeschrieben, Dubletten
        // entfallen (`rel_instance_domain` hat den Primärschlüssel instance_id+domain,
        // eine doppelte Zeile ließe das INSERT scheitern).
        // Domains sind nicht schreibungsempfindlich - klein geschrieben ablegen,
        // sonst stünde dieselbe Domain in zwei Schreibweisen in der Liste
        $primaryDomain = mb_strtolower(trim((string)($data['primary_domain'] ?? '')));
        if (in_array('primary_domain', $fields, true)) {
            $data['primary_domain'] = $primaryDomain;
        }

        if (in_array('additional_domains', $fields, true)) {
            $domains = (array)($data['domain'] ?? []);
            $regex   = (array)($data['regex'] ?? []);

            $cleanDomains = [];
            $cleanRegex   = [];
            foreach ($domains as $key => $domain) {
                $domain = mb_strtolower(trim((string)$domain));
                if ($domain === '') continue;
                if (mb_strlen($domain) > 64) {
                    $this->createFieldError(
                        'error_max_le_length',
                        'additional_domains',
                        $this->schema['additional_domains']['label'] ?? '',
                        '64'
                    );
                    throw new \Exception('#' . $this->cms->errorMsgLast());
                }
                // Die Haupt-Domain gehört nicht noch einmal in die Liste
                if ($domain === $primaryDomain) continue;
                if (in_array($domain, $cleanDomains, true)) continue;
                $cleanDomains[] = $domain;
                $cleanRegex[]   = !empty($regex[$key]) ? 1 : 0;
            }
            $data['domain'] = $cleanDomains;
            $data['regex']  = $cleanRegex;
            // Der Feldwert selbst trägt die Domains - so greifen 'max' und die
            // Anzeige auf der Info-Seite (Backend::backendValue behandelt 'reltable'
            // wie 'json': Liste der Einträge).
            $data['additional_domains'] = $cleanDomains;
        }

        // Zusatzangaben
        // --------------------
        // Script-Policy: nur bekannte Modi, Quellen nur als Host-Angabe
        // (SecurityHeaders::cleanSources()).
        if (in_array('details', $fields, true) || array_key_exists('details', $data)) {
            $details = is_array($data['details'] ?? null) ? $data['details'] : [];
            $csp = (string)($details['csp'] ?? '');
            if (!in_array($csp, SecurityHeaders::FRONTEND_MODES, true)) unset($details['csp']);
            foreach (['csp_script_sources', 'csp_form_sources'] as $key) {
                if (!array_key_exists($key, $details)) continue;
                $details[$key] = implode("\n", SecurityHeaders::cleanSources((string)$details[$key]));
                if ($details[$key] === '') unset($details[$key]);
            }
            foreach (['csp_strict_dynamic', 'csp_form_action'] as $key) {
                if (empty($details[$key])) unset($details[$key]);
                else $details[$key] = 1;
            }
            $data['details'] = $details;
        }

        // Lesbare Instanzen
        // --------------------
        if (in_array('readable_instances', $fields, true)) {
            $readable = $this->system->sanitizeIds(
                (array)($data['readable_instances'] ?? [])
            );
            // Sich selbst zu lesen ergibt keinen Sinn - und der Primärschlüssel
            // instance_id+readable_id ließe es zwar zu, die Auswertung im Frontend
            // liefe damit aber im Kreis.
            $readable = array_values(array_diff($readable, [$instanceId]));
            $readable = $this->existingIds('instance', $readable);
            $data['readable_instances'] = $readable;
        }
    }

    /**
     * Nach dem Speichern: die drei Relation-Tabellen neu schreiben.
     *
     * Läuft innerhalb der Transaktion von Dataset::save() - wirft eine der Abfragen,
     * wird auch die Instanz-Zeile selbst zurückgerollt.
     *
     * Immer erst DELETE, dann INSERT: die Tabellen tragen keine eigene Id, ein
     * Abgleich Zeile für Zeile wäre aufwendiger als das Neuschreiben.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // 'additional_domains' -> `rel_instance_domain`
        // --------------------
        // Zwei Werte je Zeile (domain + regex) und eine eigene Sortierung.
        if (in_array('additional_domains', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_domain` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $domains = (array)($data['domain'] ?? []);
            $regex   = (array)($data['regex'] ?? []);
            $rows    = [];
            $types   = '';
            $values  = [];
            foreach (array_values($domains) as $i => $domain) {
                $rows[]   = '(?, ?, ?, ?)';
                $types   .= 'isii';
                $values[] = $instanceId;
                $values[] = $domain;
                $values[] = (int)($regex[$i] ?? 0);
                $values[] = $i;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_domain` (`instance_id`, `domain`, `regex`, `position`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // 'additional_languages' -> `rel_instance_language`
        // --------------------
        // `position` hält die Reihenfolge fest, in der der Redakteur die Sprachen
        // sortiert hat (display_type 'sortable').
        if (in_array('additional_languages', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_language` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $languageIds = (array)($data['additional_languages'] ?? []);
            $rows   = [];
            $types  = '';
            $values = [];
            foreach (array_values($languageIds) as $i => $languageId) {
                $rows[]   = '(?, ?, ?)';
                $types   .= 'iii';
                $values[] = $instanceId;
                $values[] = (int)$languageId;
                $values[] = $i;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_language` (`instance_id`, `language_id`, `position`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // 'readable_instances' -> `rel_instance_instance`
        // --------------------
        // Ohne eigene Sortierung: gelesen wird in der Reihenfolge der Instanzen
        // selbst (`instance`.`position`, siehe hookOnLoaded()).
        if (in_array('readable_instances', $fields, true)) {
            $this->db->query("DELETE FROM `rel_instance_instance` WHERE `instance_id` = ?", 'i', [$instanceId]);

            $readableIds = (array)($data['readable_instances'] ?? []);
            $rows   = [];
            $types  = '';
            $values = [];
            foreach ($readableIds as $readableId) {
                $rows[]   = '(?, ?)';
                $types   .= 'ii';
                $values[] = $instanceId;
                $values[] = (int)$readableId;
            }
            if (!empty($rows)) {
                $sql = 'INSERT INTO `rel_instance_instance` (`instance_id`, `readable_id`)'
                    . ' VALUES ' . implode(', ', $rows);
                $this->db->query($sql, $types, $values);
            }
        }

        // Sprachliste geändert? -> Inhaltszeilen nachziehen
        // --------------------
        // Jeder Datensatz der Instanz führt je Sprache eine Zeile pro Feld (siehe
        // LanguageData). Kommt eine Sprache hinzu, bekommt sie die Werte der
        // Hauptsprache als Fallback; fällt eine weg, verschwinden ihre Zeilen und
        // eigenen Dateien (siehe LanguageData::removeLanguage()).
        // Wechselt die Hauptsprache, stimmen die Fallback-Werte der übrigen
        // Sprachen nicht mehr - der Bestand bleibt stehen (er wird beim nächsten
        // Speichern des Datensatzes richtig), nur `record_cache` wird verworfen.
        // Der Volltext ist nach den Regeln der Sprache gefaltet: faltet eine neue
        // Sprache anders als die Hauptsprache, wird er als veraltet markiert.
        $refreshFulltext = false;
        if (!empty($previousData)
            && (in_array('additional_languages', $fields, true) || in_array('primary_language_id', $fields, true))
        ) {
            $oldPrimary = (int)($previousData['primary_language_id'] ?? 0);
            $newPrimary = (int)($data['primary_language_id'] ?? $oldPrimary);
            $oldLanguages = array_values(array_unique(array_merge(
                [$oldPrimary],
                array_map('intval', (array)($previousData['additional_languages'] ?? []))
            )));
            $newLanguages = array_values(array_unique(array_merge(
                [$newPrimary],
                array_map('intval', (array)($data['additional_languages'] ?? $previousData['additional_languages'] ?? []))
            )));

            $languageData = $this->languageData();
            foreach (array_diff($newLanguages, $oldLanguages) as $languageId) {
                $languageData->addLanguage($instanceId, $languageId, $oldPrimary);
                if (!$languageData->sameRules($languageId, $oldPrimary)) $refreshFulltext = true;
            }
            $removed = array_diff($oldLanguages, $newLanguages);
            foreach ($removed as $languageId) {
                $languageData->removeLanguage($instanceId, $languageId);
            }
            // war eine entfernte Sprache Hauptsprache, zeigen Datei-Fallbacks ins Leere
            if (!empty($removed)) {
                $languageData->repairFileFallbacks($instanceId);
            }
            if ($newPrimary !== $oldPrimary) {
                $languageData->clearRecordCache($instanceId);
            }
        }

        // Cache-Dateien erneuern (var/cache/instances.php, instance-{id}.php)
        // --------------------
        // Läuft noch in der Transaktion: die Abfragen des Neuaufbaus sehen den
        // gerade geschriebenen Stand. Rollt die Transaktion danach zurück, stimmt
        // der Cache bis zur nächsten Änderung nicht - im Backend ist das sichtbar
        // und behebt sich mit dem nächsten Speichern.
        $this->instanceCache()->rebuild();

        // Volltext neu falten: nur markieren - "Jetzt aktualisieren" bzw. der Zeitplaner
        // baut ihn neu auf (FulltextState)
        if ($refreshFulltext) $this->languageData()->markFulltextStale($instanceId);

        // Domain-Sperren der Medienverzeichnisse neu schreiben (aus dem Cache)
        // --------------------
        $this->mediaAccess()->rebuildAll();
    }

    /**
     * Nach dem endgültigen Löschen: Cache-Dateien, Medien-Sperren und das
     * Snippet-Set der Instanz.
     * Die Datei instance-{id}.php der gelöschten Instanz wird dabei entfernt; ihr
     * Medienverzeichnis bleibt stehen (die Bereiche wurden in hookOnDelete()
     * einzeln abgeräumt, ihre Dateien sind weg) und bekommt eine Vollsperre.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
        $this->mediaAccess()->rebuildAll();
        // Snippet-Set der Instanz (Modul Snippets, var/custom/snippets/{id})
        $this->container->get(Snippets::class)->delete((int)($data['instance_id'] ?? 0));
    }

    /**
     * Nach dem Laden: die Inhalte der drei Relation-Tabellen nachreichen.
     *
     * Die 'reltable'-Felder stehen nicht in der Ergebniszeile - sie haben keine
     * Spalte. Ohne diesen Hook kämen sie als NULL im Formular an.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // `rel_instance_domain` -> 'additional_domains' + 'domain'/'regex'
        // --------------------
        // Das Formular braucht die beiden parallelen Arrays (eine Zeile je Domain),
        // das Feld selbst die reine Domain-Liste. Sortierung erhalten.
        $data['domain'] = [];
        $data['regex']  = [];
        $sql = "SELECT `domain`, `regex` FROM `rel_instance_domain`"
            . " WHERE `instance_id` = ? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$instanceId]);
        while ($row = $this->db->fetch()) {
            $data['domain'][] = (string)$row['domain'];
            $data['regex'][]  = (int)$row['regex'];
        }
        $data['additional_domains'] = $data['domain'];

        // `rel_instance_language` -> 'additional_languages'
        // --------------------
        // Eigene Sortierung je Instanz (siehe hookOnSaved())
        $sql = "SELECT `language_id` FROM `rel_instance_language`"
            . " WHERE `instance_id` = ? ORDER BY `position` ASC";
        $this->db->query($sql, 'i', [$instanceId]);
        $data['additional_languages'] = array_map('intval', $this->db->fetchColumn());

        // `rel_instance_instance` -> 'readable_instances'
        // --------------------
        // Reihenfolge der Instanzen selbst - die Kantentabelle führt keine eigene.
        $sql = "SELECT `r`.`readable_id` FROM `rel_instance_instance` `r`"
            . " LEFT JOIN `instance` `i` ON `i`.`instance_id` = `r`.`readable_id`"
            . " WHERE `r`.`instance_id` = ?"
            . " ORDER BY " . self::orderSql('i');
        $this->db->query($sql, 'i', [$instanceId]);
        $data['readable_instances'] = array_map('intval', $this->db->fetchColumn());
    }

    /**
     * Vor dem Löschen: die gesamten Inhalte dieser Website entfernen.
     *
     * WAS HIER PASSIERT, IST NICHT UMKEHRBAR. `instance` hat kein `deleted`-Feld, es
     * gibt für Instanzen also keinen Papierkorb - Dataset::delete() löscht sofort
     * endgültig. Das Backend warnt vorher mit den gezählten Mengen
     * (InstanceBackend::_editDeleteForm()).
     *
     * WARUM HIER UND NICHT IN hookOnDeleted()
     * --------------------
     * Sobald die `instance`-Zeile weg ist, räumen die Fremdschlüssel auf: `area` und
     * `record` hängen mit ON DELETE CASCADE an ihr, `field` und `template` wiederum
     * am Bereich. Die Datenbank löscht dabei nur Zeilen - die Mediendateien im
     * Dateisystem blieben als Leichen zurück, und kein Hook eines Moduls liefe.
     * Deshalb wird der Inhalt hier, VOR dem DELETE, ordentlich abgeräumt; die
     * Fremdschlüssel sind danach nur noch das Sicherheitsnetz.
     *
     * Gelöscht wird über die Inhaltsbereiche: Area::hookOnDelete() nimmt die
     * Datensätze des Bereichs einzeln (samt Mediendateien), `field` und `template`
     * fallen per Fremdschlüssel mit dem Bereich. Blätter zuerst (cascadeIds mit
     * 'purge'), damit kein Bereich per ON DELETE CASCADE über area.parent_id an
     * seinem eigenen Hook vorbei verschwindet.
     *
     * Läuft in der Transaktion von Dataset::purge(): wirft ein Schritt, bleibt alles
     * stehen.
     */
    protected function hookOnDelete(array $data): void
    {
        $instanceId = (int)($data['instance_id'] ?? 0);

        // Die Standard-Instance fängt alle Aufrufe auf, die keine konfigurierte Domain
        // treffen - ohne sie liefe jeder solche Aufruf ins Leere. Sie bleibt.
        if ($instanceId === self::DEFAULT_ID) {
            $errorMsg = $this->cms->snippet('instance.error_default_instance');
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        $area = $this->areaDataset();
        foreach ($area->cascadeIds('instance_id', $instanceId, 'purge') as $areaId) {
            if (!$area->finalDelete($areaId)) {
                // Fehlermeldung steht bereits in Cms::errors -> Transaktion verwerfen
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }

        // `rel_instance_domain`, `rel_instance_language`, `rel_instance_instance`
        // räumen die Fremdschlüssel ab (ON DELETE CASCADE auf instance_id bzw.
        // readable_id) - hier ist nichts zu tun.
    }

    // Sonderfunktionen für Instance
    // --------------------

    /**
     * Was verschwindet mit dieser Instanz?
     *
     * Grundlage der Warnung im Löschformular. Gezählt wird über die Inhaltsbereiche
     * der Instanz - genau das, was hookOnDelete() abräumt.
     *
     * @param int $instanceId
     * @return array  ['areas' => int, 'fields' => int, 'records' => int, 'templates' => int]
     */
    public function contentCount(int $instanceId): array
    {
        $count = ['areas' => 0, 'fields' => 0, 'records' => 0, 'templates' => 0];
        if ($instanceId < 1) return $count;

        $queries = [
            'areas'     => "SELECT COUNT(*) FROM `area` WHERE `instance_id` = ?",
            'fields'    => "SELECT COUNT(*) FROM `field` `f`"
                         . " INNER JOIN `area` `a` ON `a`.`area_id` = `f`.`area_id`"
                         . " WHERE `a`.`instance_id` = ?",
            'records'   => "SELECT COUNT(*) FROM `record` WHERE `instance_id` = ? AND `tmp` = 0",
            'templates' => "SELECT COUNT(*) FROM `template` `t`"
                         . " INNER JOIN `area` `a` ON `a`.`area_id` = `t`.`area_id`"
                         . " WHERE `a`.`instance_id` = ?",
        ];
        $this->db->pushResult();
        foreach ($queries as $key => $sql) {
            $this->db->query($sql, 'i', [$instanceId]);
            $count[$key] = (int)($this->db->fetchNum()[0] ?? 0);
        }
        $this->db->popResult();

        return $count;
    }

    /**
     * Aus einer Id-Liste die Ids heraussuchen, die es in $tableName wirklich gibt.
     *
     * Die Relation-Tabellen tragen Fremdschlüssel; eine Id aus einem veralteten
     * Formular ließe das INSERT in hookOnSaved() mit einer nackten MySQL-Meldung
     * scheitern. Hier fällt sie vorher heraus.
     *
     * @param string $tableName  'language' oder 'instance' - fest im Code, kein Request-Wert
     * @param array  $ids        bereits durch System::sanitizeIds() gegangen
     * @return array             die vorhandenen Ids, Reihenfolge wie übergeben
     */
    private function existingIds(string $tableName, array $ids): array
    {
        if (empty($ids)) return [];
        $idField = $tableName . '_id';
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT `{$idField}` FROM `{$tableName}` WHERE `{$idField}` IN ($placeholders)";

        $this->db->pushResult();
        $this->db->query($sql, str_repeat('i', count($ids)), $ids);
        $found = array_map('intval', $this->db->fetchColumn());
        $this->db->popResult();

        // Reihenfolge der Übergabe erhalten (bei den Sprachen ist sie die Sortierung)
        return array_values(array_filter($ids, fn($id) => in_array((int)$id, $found, true)));
    }

    /**
     * Area-Instanz bereitstellen.
     *
     * Bewusst NICHT über den Konstruktor injiziert: Dataset-Kindklassen erben den
     * Konstruktor der Basisklasse und deklarieren keinen eigenen (siehe
     * Area::recordDataset(), dieselbe Überlegung).
     */
    private function areaDataset(): Area
    {
        return $GLOBALS['app']->get(Area::class);
    }

    /**
     * Dateicache der Instanzen (siehe Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

    /**
     * Pflege der sprachabhängigen Inhaltszeilen (siehe Camindo\Core\LanguageData).
     */
    private function languageData(): LanguageData
    {
        return $this->container->get(LanguageData::class);
    }

    /**
     * .htaccess-Dateien des Medienverzeichnisses (siehe Camindo\Core\MediaAccess).
     */
    private function mediaAccess(): MediaAccess
    {
        return $this->container->get(MediaAccess::class);
    }
}
