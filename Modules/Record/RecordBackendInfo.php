<?php
/**
 * Detailansicht eines Datensatzes (Erweiterung für RecordBackend).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     record_info.php          -> info()          Detailansicht mit Reitern
 *     ajax_field_details.php   -> fieldDetails()  eine einzelne Zeile nachladen
 *                                                 (DSGVO, RecordPersonalData)
 *     record_second_factor.php -> secondFactor()  zweiter Faktor eines Profils
 *                                                 anzeigen und zurücksetzen
 *
 * Der Button "Verschieben" führt zu RecordBackendMove (record_move.php); er fehlt,
 * wenn die Ansicht aus einem Lookup-Fenster geöffnet wurde (src_id > 0).
 *
 * REITER
 * --------------------
 *     Inhalte     die Felder, die der Redakteur im Bereich definiert hat
 *     Metafelder  die festen Spalten der Tabelle `record` - Id, Zeiten, Status,
 *                 Template, Urheber, Baumposition
 *     Änderungen  der Verlauf aus dem Audit-Log (Camindo\Core\AuditLog), die
 *                 letzten zehn Einträge, je Eintrag Vorher/Nachher aller geänderten
 *                 Felder (Backend::auditHistory() -> AuditDiff); längere Verläufe im Modul Audit.
 *                 Sichtbar für jeden mit Leserecht auf den Bereich - die IP-Adresse
 *                 nur für Superuser. Nicht im Lookup-Fenster (src_field).
 *
 * Führt die Instanz des Datensatzes mehrere Sprachen und teilt der Bereich sie auf
 * Reiter auf (details.tabs), bleibt es - anders als in der Eingabemaske
 * (RecordBackendEdit) - beim einen Reiter "Inhalte"; die Sprache wählt ein Pulldown
 * darunter (je Sprache ein Eintrag, zuletzt "Alle Sprachen"). Eine Sprache lädt nur
 * den Inhalts-Cache dieser Sprache (`record_cache`) und zeigt je Feld eine Zeile;
 * "Alle Sprachen" lädt jede Sprache und zeigt mehrsprachige Felder je Sprache.
 * Nicht übersetzte Werte (Fallback aus der Hauptsprache) erscheinen ausgegraut.
 * Zuerst geöffnet wird die bevorzugte Sprachversion des Admins (persönliche
 * Einstellung), sonst die Hauptsprache - siehe _infoCurrentTab().
 *
 * Umgeschaltet wird über den Request-Parameter tab ('meta', 'all', eine language_id
 * oder leer); alle Reiter und Pulldown-Einträge sind derselbe Einstiegspunkt, nur
 * mit anderem Rumpf (_infoContents() / _infoMeta()).
 *
 * Die Trennung ist mehr als Kosmetik: template_id erscheint im Inhalte-Reiter
 * absichtlich NICHT, obwohl es im Schema steht - es gehört zu den Metafeldern und
 * würde dort doppelt auftauchen. status_id steht dort nur, wenn der Bereich ein
 * Eingabefeld dafür angelegt hat (er arbeitet dann mit dem Workflow) - hervorgehoben
 * ('primary').
 *
 * FLACHER WORKFLOW
 * --------------------
 * Trägt der Datensatz einen Status, den die Gruppe nicht setzen darf
 * (Access::recordWrite()), ist der Bearbeiten-Knopf ausgegraut - auch mit
 * Schreibrecht im Bereich.
 *
 * BAUMFELDER NUR BEI BAUM-BEREICHEN
 * --------------------
 * position, depth, lft und parent_id werden im Metafeld-Reiter nur gezeigt, wenn der
 * Bereich überhaupt eine Hierarchie hat (also ein Eingabefeld für parent_id definiert
 * ist). Ohne das sind die Werte zwar vorhanden, aber ohne Aussage.
 *
 * LANGE INHALTE WERDEN NACHGELADEN
 * --------------------
 * Die Zeilen des Inhalte-Reiters bekommen das Kennzeichen 'expandable': sie zeigen
 * zunächst nur einen Anriss und holen sich den vollen Inhalt bei Bedarf über
 * fieldDetails() nach. Sonst müsste die Detailansicht sämtliche Textfelder,
 * Verknüpfungen und Vorschaubilder eines Datensatzes auf einmal aufbereiten.
 *
 * @see Camindo\Core\Modules\Record\RecordBackend      gemeinsame Basis
 * @see Camindo\Core\Modules\Record\RecordBackendEdit  die Eingabemaske dazu
 * @see Camindo\Core\Backend::infoAdd()                  baut die Zeilen
 */
namespace Camindo\Core\Modules\Record;

use Camindo\Core\AuditLog;
use Camindo\Core\Backend;
use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\Crypt;
use Camindo\Core\Database;
use Camindo\Core\InstanceCache;
use Camindo\Core\MassDeletion;
use Camindo\Core\Session;
use Camindo\Core\System;
use Camindo\Core\Modules\Area\Area;

class RecordBackendInfo extends RecordBackend {

    public function __construct(
        Config $config,
        Backend $backend,
        Crypt $crypt,
        Session $session,
        Database $db,
        System $system,
        Cms $cms,
        MassDeletion $massDeletion,
        Area $area,
        Record $record,
        InstanceCache $instanceCache,
        RecordService $service,
        RecordPresenter $presenter,
        private AuditLog $auditLog,
        private RecordPersonalData $personalData,
        private RecordExport $export,
        private UserSecondFactor $userSecondFactor,
    )
    {
        parent::__construct($config, $backend, $crypt, $session, $db, $system, $cms, $massDeletion, $area, $record, $instanceCache, $service, $presenter);
    }

    /**
     * Detailansicht ausgeben (backend/record_info.php).
     *
     * $this->function wird vorab auf 'info' gesetzt - _resolveParams() schaltet bei
     * einem Fehler auf 'errors' um, der Vergleich danach fängt genau das ab. Dieselbe
     * Stelle steuert außerdem headline(), das nur bei 'info' und 'delete' den
     * Datensatz selbst über die Seite schreibt.
     *
     * Das Status-Symbol erscheint in der Überschrift nur, wenn der Bereich für
     * status_id ein eigenes Eingabefeld angelegt hat - daran erkennt man, dass der
     * Redakteur mit dem Status überhaupt arbeitet. Erkennbar ist das an 'field_id' in
     * der Spaltendefinition: nur virtuelle Felder haben eine.
     *
     * Request: id, area_id, tab ('meta', 'all', language_id oder leer)
     */
    /** Höchstzahl verknüpfter Datensätze je Feld in der Detailansicht (_infoRelated()) */
    private const RELATED_MAX = 20;

    /** Inhalte je Sprache aus record_cache lesen (mehrsprachige Instanz + Felder) */
    private bool $useLanguageCaches = false;

    public function info()
    {
        echo $this->backend->template('container_header');

        $this->function = 'info';

        $this->_resolveParams();

        if ($this->function !== 'info') {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }

        // Seitenelemente
        // --------------------

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Load data
        if (!$this->data = $this->record->load($this->id)) {
            // Fehlermeldungen in $this->cms->errors
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];

        // Rechte der Benutzergruppe: Leserecht auf den Bereich (12-group-rights.md)
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        $ownerId = isset($this->data['owner_id']) ? (int)$this->data['owner_id'] : null;
        // Stift hinter "Original filename" nur mit Schreibrecht (fileRename())
        if ($access->areaWrite($this->areaId, $ownerId)) {
            $this->backend->fileRenameIds[$this->id] = true;
        }

        // Virtuelle Felder laden - Verknüpfungsfelder ohne lesbaren Zielbereich bleiben weg
        $schemaVirtual = array_filter(
            $this->record->virtualSchema($this->areaId, false),
            fn(array $colDef) => $this->record->relationTargetReadable($colDef)
        );
        $this->hasParentId = isset($schemaVirtual['parent_id']);

        // H1
        // Wenn virtuelles Feld auf status_id basiert (field_id in Spaltendefinition enthalten)
        // dann zeige das Status-Icon vor der Headline
        $statusCol = (array)($schemaVirtual['status_id'] ?? []);
        if (!empty($statusCol['field_id'])) {
            $displayIcon = true;
        }
        else {
            $displayIcon = false;
        }
        echo $this->headline($displayIcon);
        
        // Buttons
        // --------------------
        $this->backend->buttonBarReset();
        if ($access->recordWrite($this->areaId, $ownerId, (int)($this->data['status_id'] ?? 0))) {
            $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}');");
        }
        elseif ($access->areaWrite($this->areaId, $ownerId)) {
            // Schreibrecht, aber der Status ist für die Gruppe nicht setzbar: der
            // Datensatz ist für sie abgeschlossen (flacher Workflow)
            $this->backend->buttonAdd('record.button_edit_status_denied', '');
        }
        if ($access->areaCreate($this->areaId)) {
            $this->backend->buttonAdd('button_copy', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}&function=copy');");
        }
        if ($access->areaDelete($this->areaId, $ownerId)) {
            $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Record/record_edit.php?{$this->params}&id={$this->id}&function=delete');");
        }
        /*
          Wenn Default-Template im Bereich definiert ist, oder dem Datensatz ein Template explizit zugewiesen
          wurde, dann Preview-Link anzeigen
        */
        if (
            !empty($this->areaData['default_template_id'])
            || !empty($this->data['template_id'])
        ) {
            $this->backend->buttonAddPreview($this->createPreviewLink(), (int)($this->data['instance_id'] ?? 0));
        }
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        if ($this->srcId < 1) {
            // Menü rechts: Verschieben (Schreiben + Löschen im Quellbereich, keine
            // Verknüpfungsfelder ohne Zielzugriff), Message zu Record schreiben
            $canMove = $access->areaWrite($this->areaId, $ownerId) && $access->areaDelete($this->areaId, $ownerId)
                && $this->record->relationTargetsReadable($this->areaId);
            $this->backend->buttonAdd($canMove ? 'record.record_info_more_dropdown' : 'record.record_info_more_dropdown_nomove', '', '', alignRight:true);
            // Profil-Datensätze: Datenexport (Modul Privacy) - vor {record_id}/{area_id},
            // die Vorlage des Eintrags bringt diese Platzhalter mit
            $this->backend->buttonBarReplace('{extra_items}', ($this->_personalDataAllowed()
                ? $this->backend->template('record.record_info_item_personal_data') : '')
                . ($this->_areaType() === 'profile'
                ? $this->backend->template('record.record_info_item_second_factor') : ''));
            $this->backend->buttonBarReplace('{record_id}', (string)$this->id);
            $this->backend->buttonBarReplace('{area_id}', (string)$this->areaId);
        }
        echo $this->backend->buttonBar();

        // Tabs
        // ----------
        // Inhalte | Metadaten
        // Wenn im Content-Bereich aktiviert, dann mit Tabs je Sprache
        $languages = $this->instanceCache->languages((int)($this->data['instance_id'] ?? 0));
        // Mehrsprachig: Inhalte je Sprache aus dem Inhalts-Cache (auch wenn die
        // Gruppe nur eine Sprache lesen darf - Record::load() liefert die
        // Hauptsprache, und die darf sie vielleicht gerade nicht sehen)
        // Ein einsprachiger Slug mit je Sprache verschiedenem Slug-Präfix zählt wie
        // ein mehrsprachiges Feld (_infoSlug())
        $this->useLanguageCaches = count($languages) > 1
            && ($this->_hasMultilingualField($schemaVirtual) || $this->_slugPrefixMultilingual($schemaVirtual, $languages));
        if (!$this->useLanguageCaches) {
            $languages = array_slice($languages, 0, 1, true);
        }
        else {
            // nur lesbare Sprachen der Benutzergruppe (Reiter und Zeilen)
            $languages = $this->record->readableLanguages($languages);
        }
        // Aktiver Reiter: Request-Wert, sonst die bevorzugte Sprachversion des Admins,
        // sonst die Hauptsprache (siehe _infoCurrentTab()) - NICHT der rohe
        // Request-Parameter, sonst bliebe beim ersten Aufruf (kein tab= in der URL)
        // kein Reiter aktiv und _infoContents() läse (int)'' = Sprache 0 statt der
        // bevorzugten/Haupt-Sprache.
        $tab = $this->_infoCurrentTab($languages);
        $url = "Record/record_info.php?{$this->params}&id={$this->id}";
        $this->backend->tabBarReset();
        // Tab "Inhalte" - auch bei auf Reiter aufgeteilten Sprachen nur ein Reiter,
        // die Sprache wählt das Pulldown darunter (_infoLanguageDropdown())
        $isContents = ($tab !== 'meta' && $tab !== 'audit');
        $this->backend->tabAdd('#record.tab_data_fields', $isContents ? '' : "cApp.layers.replace('{$url}')", $isContents);
        // Tab "Metafelder"
        $this->backend->tabAdd('#record.tab_meta_fields', $tab === 'meta' ? '' : "cApp.layers.replace('{$url}&tab=meta')", $tab === 'meta');
        // Tab "Änderungen" (Audit-Log) - nicht im Lookup-Fenster
        if ($this->auditLog->enabled() && $this->srcField < 1) {
            $this->backend->tabAdd('#tab_audit', $tab === 'audit' ? '' : "cApp.layers.replace('{$url}&tab=audit')", $tab === 'audit');
        }
        echo $this->backend->tabBar();

        if ($tab === 'meta') {
            // Panel "Metafelder"
            $this->_infoMeta($schemaVirtual);
        }
        elseif ($tab === 'audit') {
            // Panel "Änderungen"
            $this->_infoAudit();
        }
        else {
            // Panel "Inhalte": eine Sprache oder alle
            $this->_infoContents($schemaVirtual, $languages, $tab);
        }
    
        echo $this->backend->template('container_footer');
    }

    /**
     * Führt der Bereich mindestens ein mehrsprachiges Feld (field.multilingual)?
     */
    private function _hasMultilingualField(array $schemaVirtual): bool
    {
        foreach ($schemaVirtual as $colDef) {
            if ($this->cms->isMultilingualField((array)$colDef)) return true;
        }
        return false;
    }

    /**
     * Hat der Bereich ein einsprachiges Slug-Feld, aber einen je Sprache
     * verschiedenen Slug-Präfix? Dann ist auch die Adresse je Sprache verschieden,
     * und die Info-Seite zeigt den Slug wie ein mehrsprachiges Feld (_infoSlug()).
     *
     * @param array $languages  language_id => Sprachzeile der Instanz
     */
    private function _slugPrefixMultilingual(array $schemaVirtual, array $languages): bool
    {
        if (!isset($schemaVirtual['slug']) || $this->cms->isMultilingualField((array)$schemaVirtual['slug'])) return false;
        $prefixes = [];
        foreach (array_keys($languages) as $languageId) {
            $prefixes[$this->instanceCache->slugPrefix($this->areaId, (int)$languageId)] = true;
        }
        return count($prefixes) > 1;
    }

    /**
     * Zeile(n) des Slugs: "{Präfix}/" grau vor dem Slug - so, wie er in der Adresse
     * steht (Cms::slugWithPrefix()). Präfix und Slug gelten je Sprache: ein
     * mehrsprachiger Slug kommt aus dem Inhalts-Cache, ein einsprachiger gilt für
     * alle Sprachen. Bei "Alle Sprachen" eine Zeile je Sprache, sonst die gewählte
     * bzw. (ohne Sprachauswahl) die Hauptsprache.
     *
     * @param array      $languageCaches  language_id => Inhalts-Cache (_infoRows())
     * @param array      $languages       Sprachen (siehe _infoContents())
     * @param int|string $tab             'all' oder language_id
     */
    private function _infoSlug(array $colDef, array $schemaVirtual, array $languageCaches, array $languages, int|string $tab, array $flags): void
    {
        $flags = array_values(array_diff($flags, ['expandable']));
        $flags[] = 'html';
        $multilingual = !empty($languageCaches) && $this->cms->isMultilingualField($colDef);

        $row = function (int $languageId, string $langSuffix = '') use ($colDef, $schemaVirtual, $languageCaches, $multilingual, $flags): void {
            $isFallback = false;
            if ($multilingual) {
                [$slug, $isFallback] = $this->_infoCacheValue($languageCaches[$languageId]['slug'] ?? null, $colDef);
            }
            else {
                $slug = $this->data['slug'] ?? '';
            }
            $slug = (string)$slug;
            if ($slug === '') {
                $value = $this->backend->emptyPlaceholder;
            }
            else {
                $prefix = $this->instanceCache->slugPrefix($this->areaId, $languageId);
                $value = ($prefix !== '' ? '<span class="c9-grey-50">' . htmlspecialchars($prefix) . '/</span>' : '')
                    . htmlspecialchars($slug);
            }
            if ($isFallback) $flags[] = 'grey';
            if (empty($this->areaData['default_template_id'])) {
                $flags[] = 'grey';
            }
            $this->backend->infoAdd($this->record, 'slug', $value, $flags, $schemaVirtual, id: $this->id, langSuffix: $langSuffix);
        };

        if (!empty($languageCaches) && $tab === 'all') {
            foreach ($languages as $languageId => $language) {
                $row((int)$languageId, (string)($language['code'] ?? $languageId));
            }
        }
        else {
            $row(is_int($tab) ? $tab : (int)array_key_first($languages));
        }
    }

    /**
     * Aktiver Reiter: 'meta', 'all' oder eine language_id.
     *
     * Ohne Angabe (erster Aufruf) die bevorzugte Sprachversion des Admins
     * (persönliche Einstellung settings.language_id), sofern die Instanz sie führt,
     * sonst die Hauptsprache. Führt die Instanz nur eine Sprache, gibt es keine
     * Sprachauswahl - dann ist 'all' der Inhalte-Reiter.
     *
     * @param array $languages  language_id => Sprachzeile (InstanceCache::languages())
     * @return int|string
     */
    private function _infoCurrentTab(array $languages): int|string
    {
        $tab = (string)($_REQUEST['tab'] ?? '');
        if ($tab === 'meta') return 'meta';
        if ($tab === 'audit') return $this->srcField < 1 ? 'audit' : 'meta';

        $useTabs = $this->useLanguageCaches && $this->_languageTabs();
        if (!$useTabs) return 'all';

        $languageIds = array_map('intval', array_keys($languages));
        if ($tab === 'all') return 'all';
        if ($tab === '') {
            $preferred = (string)($this->backend->adminSettings()['language_id'] ?? '');
            if ($preferred !== '' && in_array((int)$preferred, $languageIds, true)) {
                return (int)$preferred;
            }
            // sonst der erste Reiter (Hauptsprache bzw. erste lesbare Sprache)
            return (int)($languageIds[0] ?? 0);
        }
        if (!in_array((int)$tab, $languageIds, true)) return (int)($languageIds[0] ?? 0);
        return (int)$tab;
    }

    /**
     * Wert eines Feldes aus dem Inhalts-Cache einer Sprache lesen (record_cache):
     * Eintrag [typ, wert, (1 = Fallback aus der Hauptsprache)].
     *
     * @return array  [Wert im Format von Record::load(), bool Fallback]
     */
    private function _infoCacheValue(?array $entry, array $colDef): array
    {
        $value = $entry[1] ?? '';
        $type = (string)($colDef['type'] ?? '');
        if ($type === 'json') {
            $value = json_decode((string)$value, true) ?: [];
        }
        elseif ($type === 'encrypted') {
            $value = $this->crypt->decrypt((string)$value, '', false);
        }
        return [$value, !empty($entry[2])];
    }

    /**
     * Reiter "Inhalte": die vom Redakteur definierten Felder.
     *
     * Läuft über das Schema des Bereichs, überspringt die Felder, die in den
     * Metafeld-Reiter gehören, und lässt die Aufbereitung von Backend::infoAdd()
     * erledigen - die Spaltendefinition sagt ihr, wie ein Wert darzustellen ist.
     *
     * Von Hand behandelt werden nur die drei Fälle, die aus einer Zahl etwas Lesbares
     * machen müssen: needs_review (Symbol und Text), parent_id (Verweis auf den
     * übergeordneten Datensatz).
     *
     * 'expandable' an jeder Zeile: lange Inhalte werden erst auf Klick nachgeladen
     * (siehe fieldDetails()).
     *
     * infoReset() vorweg, weil in dieser Anfrage schon eine andere Infotabelle
     * gesammelt worden sein könnte.
     *
     * @param array      $schemaVirtual  Schema des Bereichs
     * @param array      $languages      Sprachen der Instanz (Hauptsprache zuerst)
     * @param int|string $tab            'all' oder die gewählte language_id
     */
    private function _infoContents(array $schemaVirtual, array $languages = [], int|string $tab = 'all'): void
    {
        // kein infoReset - es geht Tab bereits voraus
        // Sprachauswahl (nur bei auf Reiter aufgeteilten Sprachen)
        if ($this->useLanguageCaches && $this->_languageTabs()) {
            $this->backend->infoStatic('', $this->_infoLanguageDropdown($languages, $tab), ['fullsize']);
        }
        $this->_infoRows($schemaVirtual, $languages, $tab);
        echo $this->backend->infoTable();
    }

    /**
     * Pulldown der Sprachauswahl im Reiter "Inhalte": je lesbare Sprache ein Eintrag
     * "Label (Code)", zuletzt "Alle Sprachen" - aufgemacht wie das Pulldown der
     * Vorschau (Backend::buttonAddPreview()); der Button zeigt die gewählte Sprache.
     *
     * @param array      $languages  Sprachen (siehe _infoContents())
     * @param int|string $tab        'all' oder language_id
     */
    private function _infoLanguageDropdown(array $languages, int|string $tab): string
    {
        $url = "Record/record_info.php?{$this->params}&id={$this->id}&tab=";
        $options = [];
        foreach ($languages as $languageId => $language) {
            $code = (string)($language['code'] ?? $languageId);
            $label = $this->cms->textOrSnippet((string)($language['label'] ?? $code)) . ' (' . $code . ')';
            $options[(string)$languageId] = $label;
        }
        $options['all'] = $this->cms->snippet('record.tab_all_languages');

        $items = '';
        foreach ($options as $key => $label) {
            $isActive = ((string)$tab === (string)$key);
            $items .= $this->backend->template('record.record_info_language_dropdown_item', [
                '{url}' => htmlspecialchars($url . $key),
                '{label}' => htmlspecialchars($label),
                '{class}' => $isActive ? ' active' : '',
                '{current}' => $isActive ? ' aria-current="true"' : '',
            ]) . "\n";
        }
        return $this->backend->template('record.record_info_language_dropdown', [
            '{label}' => htmlspecialchars($options[(string)$tab] ?? ''),
            '{items}' => $items,
        ]);
    }

    /**
     * Die Zeilen des Reiters "Inhalte" für den Datensatz $this->id / $this->data
     * sammeln (Backend::infoAdd()) - für den Datensatz der Seite und, mit $depth > 0,
     * für die fest verknüpften Inhalte eines 'link'-Feldes (_infoLinkBlocks()).
     *
     * Ein 'link'-Feld zeigt auf der Seite selbst seine Inhalte als Blöcke
     * untereinander, jeder mit den Eingabefeldern (visibility_input = 1) seines
     * Bereichs; in einem solchen Block (Tiefe > 0) erscheinen 'link'-Felder wie
     * überall sonst nur mit den Default-Feldern ihrer Inhalte (Backend::backendValue()).
     * 'expandable' gilt nur auf der Seite selbst - der Nachlade-Parameter kennt nur
     * einen Feldnamen.
     *
     * @param array      $schemaVirtual  Schema des Bereichs von $this->id
     * @param array      $languages      Sprachen (siehe _infoContents())
     * @param int|string $tab            'all' oder language_id
     * @param int        $depth          0 = Seite, 1 = Inhalt eines 'link'-Feldes
     */
    private function _infoRows(array $schemaVirtual, array $languages, int|string $tab, int $depth = 0): void
    {
        // Inhalts-Cache je Sprache (`record_cache`): bei einer gewählten Sprache nur
        // diese Sprache, bei "Alle Sprachen" jede Sprache der Instanz. Eine
        // einsprachige Instanz braucht keinen - Record::load() hat die Hauptsprache
        // bereits geliefert. Fallback-Werte (nicht übersetzt, Kennzeichen im Cache)
        // werden als solche markiert.
        $languageCaches = [];
        if ($this->useLanguageCaches) {
            $cacheLanguages = ($tab === 'all') ? array_keys($languages) : [(int)$tab];
            foreach ($cacheLanguages as $languageId) {
                $languageCaches[(int)$languageId] = $this->cms->loadRecordCache($this->id, (int)$languageId) ?? [];
            }
        }

        foreach ($schemaVirtual as $name => $colDef) {
            // Status: als Eingabefeld des Bereichs (field_id) auch hier, hervorgehoben -
            // er ist der Stand des Workflows (Access::statusWrite())
            if ($name === 'status_id') {
                if ($depth === 0 && (int)($colDef['field_id'] ?? 0) > 0) {
                    $this->backend->infoAdd($this->record, $name, $this->backend->teaser('status', (int)($this->data['status_id'] ?? 0)),
                        ['html', 'primary'], $schemaVirtual, id: $this->id);
                }
                continue;
            }
            // Manche Felder nur nur im Metafeld-Tab
            if ($name === 'template_id') continue;
            // Volltext: abgeleitet, eigene Seite (Metafelder -> "Volltext anzeigen")
            if ((string)($colDef['type'] ?? '') === 'fulltext') continue;
            // Inhalt eines 'link'-Feldes: nur seine Eingabefelder
            if ($depth > 0 && (int)($colDef['visibility_input'] ?? 0) !== 1) continue;
            $value = $this->data[$name] ?? null;
            $flags = $depth === 0 ? ['expandable'] : [];
            if ((int)($colDef['field_id'] ?? 0) > 0 && (int)$colDef['field_id'] === (int)($this->areaData['default_field_id'] ?? 0)) {
                $flags[] = 'primary';
            }

            // Fest verknüpfte Inhalte: je Inhalt ein Block mit seinen Feldern
            if ($depth === 0 && in_array((string)($colDef['type'] ?? ''), $this->cms->dependentTypes, true)) {
                $flags[] = 'html';
                $flags[] = 'fullsize';
                $value = $this->_infoLinkBlocks($colDef, $languages, $tab);
                $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
                continue;
            }

            // Verknüpfte Datensätze: untereinander, je mit Symbol und Verweis
            if (in_array((string)($colDef['type'] ?? ''), $this->cms->relationTypes, true)) {
                $flags = array_values(array_diff($flags, ['expandable']));
                $flags[] = 'html';
                $this->backend->infoAdd($this->record, $name, $this->_infoRelated($colDef), $flags, $schemaVirtual, id: $this->id);
                continue;
            }

            // Slug mit dem Slug-Präfix des Bereichs davor
            if ($name === 'slug') {
                $this->_infoSlug($colDef, $schemaVirtual, $languageCaches, $languages, $tab, $flags);
                continue;
            }

            if (!empty($languageCaches) && $this->cms->isMultilingualField($colDef)) {
                if ($tab === 'all') {
                    // eine Zeile je Sprache, mit Sprachkürzel
                    foreach ($languages as $languageId => $language) {
                        [$langValue, $isFallback] = $this->_infoCacheValue($languageCaches[(int)$languageId][$name] ?? null, $colDef);
                        $langFlags = $flags;
                        if ($isFallback) $langFlags[] = 'grey';
                        $this->backend->infoAdd($this->record, $name, $langValue, $langFlags, $schemaVirtual, id: $this->id, langSuffix: (string)($language['code'] ?? $languageId));
                    }
                }
                else {
                    // gewählte Sprache: nur der Wert dieser Sprache
                    [$value, $isFallback] = $this->_infoCacheValue($languageCaches[(int)$tab][$name] ?? null, $colDef);
                    if ($isFallback) $flags[] = 'grey';
                    $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
                }
                continue;
            }
            if  ($name === 'needs_review') {
                $value = $value ? $this->backend->template('user.icon_unreviewed') .' ' . $this->cms->snippet('user.status_unreviewed')
                    : $this->backend->emptyPlaceholder;
                $flags[] = 'html';
            }
            elseif ($name === 'parent_id') {
                $value = $this->backend->teaser('record', (int)$value);
                $flags[] = 'html';
            }
            elseif ($name === 'position') {
                // vorangehenden Datensatz listen
                if ($previousId = $this->presenter->previousId($this->data, $this->hasParentId)) {
                    $value = $this->backend->teaser('record', $previousId);
                    $flags[] = 'html';
                }
                else {
                    $value = '';
                }                
            }
            $this->backend->infoAdd($this->record, $name, $value, $flags, $schemaVirtual, id: $this->id);
        }
    }

    /**
     * Die fest verknüpften Inhalte eines 'link'-Feldes (LinkTrait) als Blöcke
     * untereinander, in ihrer Reihenfolge (data_rel.position): Kopfzeile mit dem
     * Datensatz-Teaser (Link auf seine Info-Seite), darunter seine Eingabefelder
     * in der Darstellung dieser Seite (_infoRows() mit Tiefe 1, Sprachen wie hier).
     * Ein Sonderfall der Info-Seite - in Listen und Vorschauen zeigt
     * Backend::backendValue() wie bei jedem Verknüpfungsfeld nur die Default-Felder.
     *
     * _infoRows() arbeitet auf $this->id / $this->data / $this->areaId /
     * $this->areaData - der Zusammenhang wird je Inhalt ausgetauscht; die Zeilen
     * entstehen in einer eigenen Sammlung (Backend::infoPush()/infoPop()).
     *
     * @param array      $colDef     das 'link'-Feld
     * @param array      $languages  Sprachen (siehe _infoContents())
     * @param int|string $tab        'all' oder language_id
     * @return string  HTML der Blöcke, '' ohne Inhalte
     */
    private function _infoLinkBlocks(array $colDef, array $languages, int|string $tab): string
    {
        $this->db->pushResult();
        $this->cms->select([
            'src_id' => $this->id,
            'relation' => (string)$colDef['name'],
            'sort' => '_custom',
            'order' => 'ASC',
            'limit' => (int)($this->config->get('admin.list_max_datasets') ?? 200),
        ]);
        $destIds = [];
        while ($row = $this->db->fetch()) $destIds[] = (int)$row['record_id'];
        $this->db->popResult();
        if (empty($destIds)) return '';

        $keep = [$this->id, $this->data, $this->areaId, $this->areaData];
        $access = $this->backend->access();
        $blocks = [];
        try {
            foreach ($destIds as $destId) {
                if (!$data = $this->record->load($destId)) continue;
                $areaId = (int)$data['area_id'];
                if (!$areaData = $this->backend->areaData($areaId)) continue;
                $this->id = $destId;
                $this->data = $data;
                $this->areaId = $areaId;
                $this->areaData = $areaData;
                $schemaVirtual = $this->record->virtualSchema($areaId, false);

                // Schreibrecht auf den verknüpften Datensatz: Edit-Button und Stift
                // hinter "Original filename" (fileRename())
                $canWrite = $access->areaWrite($areaId, isset($data['owner_id']) ? (int)$data['owner_id'] : null);
                if ($canWrite) $this->backend->fileRenameIds[$destId] = true;

                $this->backend->infoPush();
                $this->_infoRows($schemaVirtual, $languages, $tab, 1);
                $rows = $this->backend->infoPop();

                // Edit-Button rechts oben im Block: öffnet die Eingabemaske des
                // verknüpften Datensatzes als eigene Ebene (Speichern/Abbrechen
                // schließen sie und aktualisieren diese Seite)
                $button = '';
                if ($canWrite && $access->statusWrite((int)($data['status_id'] ?? 0))) {
                    $button = $this->backend->template('record.button_link_item_edit', [
                        '{area_id}' => $areaId,
                        '{id}' => $destId,
                    ]);
                }

                $blocks[] = $this->backend->template('record.info_link_block', [
                    '{button}' => $button,
                    '{rows}' => $rows
                ]);
            }
        } finally {
            [$this->id, $this->data, $this->areaId, $this->areaData] = $keep;
        }
        return implode("\n", $blocks);
    }

    /**
     * Verknüpfte Datensätze eines Verknüpfungsfeldes als Liste von Teasern
     * (Backend::teaser(): Symbol des Bereichs, Standardfeld, Verweis auf die
     * Detailansicht), höchstens RELATED_MAX. Gibt es mehr, führt "mehr" nicht zum
     * Nachladen, sondern zur Trefferliste aller verknüpften Datensätze
     * (RecordBackendList mit src_id + relation) in einem neuen Layer.
     * Reihenfolge wie in den Listen (Backend::relatedIds()).
     */
    private function _infoRelated(array $colDef): string
    {
        $name = (string)($colDef['name'] ?? '');
        [$ids, $more] = $this->backend->relatedIds($this->id, $colDef, self::RELATED_MAX);
        $items = [];
        foreach ($ids as $relatedId) {
            $teaser = $this->backend->teaser('record', $relatedId);
            if ($teaser !== '') $items[] = '<li>' . $teaser . '</li>';
        }
        if (!$items) return '';
        if ($more && preg_match('/^[a-z0-9_]+$/', $name)) {
            $items[] = '<li>' . $this->backend->template('link_add', [
                '{href}' => "Record/record_list.php?src_id={$this->id}&relation={$name}",
                '{text}' => '{#more}',
            ]) . '</li>';
        }
        return $this->backend->template('ul_unstyled', ['{items}' => implode("\n", $items)]);
    }

    /**
     * Reiter "Metafelder": die festen Spalten der Tabelle `record`.
     *
     * Die Feldliste steht hier ausdrücklich und in fester Reihenfolge - sie soll sich
     * nicht ändern, wenn jemand am Schema arbeitet, und die Reihenfolge ist eine
     * inhaltliche (Zugehörigkeit, Sichtbarkeit, Baum, Herkunft).
     *
     * Vier Felder werden übersprungen, wenn der Bereich keine Hierarchie hat:
     * position, parent_id, lft und depth (siehe Klassenkommentar).
     *
     * Ids werden zu Verweisen: status_id, created_by/changed_by/owner_id und
     * parent_id gehen durch Backend::teaser(), template_id durch _infoTemplate().
     * Am Ende "Verwendet von": Knopf zur Liste aller Datensätze, die auf diesen
     * verweisen.
     */
    private function _infoMeta(array $schemaVirtual): void
    {
        // Metafelder
        $this->backend->infoReset();
        $metaFields = [
            'record_id',
            'area_id',
            'instance_id',
            'online_at',
            'offline_at',
            'status_id',
            'depth',
            'template_id',
            'created_by',
            'changed_by',
            'owner_id',
            'user_id',
            'created_at',
            'changed_at',
        ];
        foreach ($metaFields as $name) {
            $flags = [];
            $value = $this->data[$name] ?? '';
            if ($name === 'area_id') {
                $value = $this->backend->teaser('area', $this->areaId)
                    . ' &middot; area_id : ' . $this->areaId;
                $flags = ['html'];
            }
            elseif ($name === 'instance_id') {
                $instanceId = (int)($this->data['instance_id'] ?? 0);
                $value = $this->backend->teaser('instance', $instanceId)
                    . ' &middot; instance_id : ' . $instanceId;
                $flags = ['html'];
            }
            elseif ($name === 'status_id') {
                $statusId = (int)($this->data[$name] ?? 0); 
                $value = $this->backend->teaser('status', $statusId);
                $flags = ['html'];
            }
            elseif (in_array($name, ['created_by', 'changed_by', 'owner_id'], true)) {
                $adminId = (int)($this->data[$name] ?? 0);
                if (!empty($adminId)) {
                    $value = $this->backend->teaser('admin', $adminId);
                    $flags = ['html'];
                }
            }
            elseif ($name === 'user_id') {
                // Besitzer im Frontend (Profil-Datensatz), nur wenn gesetzt
                $userId = (int)($this->data[$name] ?? 0);
                if (empty($userId)) continue;
                $value = $this->backend->teaser('record', $userId);
                $flags = ['html'];
            }
            elseif ($name === 'parent_id') {
                if (!isset($schemaVirtual['parent_id'])) continue;
                $recordId = (int)($this->data[$name] ?? 0);
                if (!empty($recordId)) {
                    $value = $this->backend->teaser('record', $recordId);
                    $flags = ['html'];
                }
            }
            elseif ($name === 'depth') {
                if (!isset($schemaVirtual['parent_id'])) continue;
            }
            elseif ($name === 'template_id') {
                $templateId = (int)($this->data['template_id'] ?? 0);
                $value = $this->_infoTemplate($templateId);
                $flags = ['html'];
            }
            $this->backend->infoAdd($this->record, $name, $value, $flags);
        }

        // "Verwendet von": alle Datensätze, die über irgendein Verweisfeld auf diesen
        // zeigen - globale Trefferliste mit relation=any + dest_id
        // (RecordBackendList::list(), Cms::filter()); nicht im Lookup-Modus
        if ($this->srcField < 1) {
            $this->backend->infoStatic('#record.label_used_by', $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.add('Record/record_list.php?relation=any&dest_id={$this->id}')",
                '{label}' => htmlspecialchars($this->cms->snippet('record.button_references')),
            ]));
            // Volltext (Standardfeld und Volltextfelder des Bereichs): eigene Seite
            $this->backend->infoStatic('#area.label_field_fulltext', $this->backend->template('button_secondary', [
                '{onclick}' => "cApp.layers.add('Record/record_fulltext.php?id={$this->id}')",
                '{label}' => htmlspecialchars($this->cms->snippet('record.button_show_fulltext')),
            ]));
        }
        echo $this->backend->infoTable();
    }

    /**
     * "Volltext anzeigen" (backend/record_fulltext.php, aus dem Reiter "Metafelder"):
     * was in `data_ftx` für den Datensatz steht - zuerst das Volltext-Standardfeld,
     * dann die Volltextfelder des Bereichs, je lesbare Sprache. Nur lesen; der Text
     * entsteht beim Speichern (VirtualFieldsTrait::refreshStandardFulltext(),
     * refreshFulltext()). Recht: Leserecht auf den Bereich.
     *
     * Request: id
     */
    public function fulltext(): void
    {
        $this->backend->setNaviLabel('#area.label_field_fulltext');
        echo $this->backend->template('container_header');
        $this->id = (int)($_REQUEST['id'] ?? 0);
        if (!$this->data = $this->record->load($this->id)) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }

        echo $this->backend->headline('#area.label_field_fulltext');
        echo '<p>' . $this->backend->teaser('record', $this->id) . '</p>';
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        echo $this->backend->buttonBar();

        // Standardfeld zuerst, dann die Volltextfelder des Bereichs: fieldname_id => Label
        $sources = [$this->record->standardFulltextFieldnameId() => '#area.label_field_fulltext'];
        foreach ($this->record->virtualSchema($this->areaId, false) as $name => $colDef) {
            if ((string)($colDef['type'] ?? '') !== 'fulltext') continue;
            $resolved = $this->cms->resolveVirtualField((string)$name);
            if ($resolved) $sources[(int)$resolved['fieldname_id']] = (string)(($colDef['label'] ?? '') ?: $name) . ' (' . $name . ')';
        }
        $languages = $this->record->readableLanguages($this->instanceCache->languages((int)($this->data['instance_id'] ?? 0)));
        $multilingual = count($languages) > 1;

        foreach ($sources as $fieldnameId => $label) {
            echo $this->backend->template('headline2_mt', ['{text}' => htmlspecialchars($this->cms->textOrSnippet($label))]);
            $this->db->query("SELECT `language_id`, `data` FROM `data_ftx` WHERE `record_id` = ? AND `fieldname_id` = ?", 'ii', [$this->id, $fieldnameId]);
            $texts = $this->db->fetchPairs();
            $this->backend->infoReset();
            $shown = 0;
            foreach ($languages as $languageId => $language) {
                $text = trim((string)($texts[$languageId] ?? ''));
                $code = (string)($language['code'] ?? $languageId);
                $this->backend->infoStatic(
                    $multilingual ? $this->cms->textOrSnippet((string)($language['label'] ?? $code)) . " ($code)" : '',
                    $text === '' ? $this->backend->emptyPlaceholder : '<small>' . htmlspecialchars($text) . '</small>',
                    $multilingual ? ['html'] : ['html', 'fullsize']
                );
                $shown++;
            }
            if ($shown > 0) echo $this->backend->infoTable();
        }
        echo $this->backend->template('alert_success', ['{text}' => '{#record.note_fulltext}']);
        echo $this->backend->template('container_footer');
    }

    /**
     * Template des Datensatzes als verlinkte Beschriftung.
     *
     * Ein Datensatz muss kein Template gesetzt haben - dann gilt das des Bereichs.
     * Das wird hier auch so gezeigt: das Bereichs-Template mit dem Zusatz
     * "(Standard)", damit erkennbar bleibt, dass es nicht am Datensatz hängt. Hat auch
     * der Bereich keins, bleibt nur der Hinweis.
     */
    private function _infoTemplate(int $templateId): string
    {
        if (!empty($templateId)) {
            $value = $this->backend->teaser('template', $templateId);
        }
        else {
            // Default-Tempalte des Bereichs ermitteln
            $defaultTemplateId = (int) $this->areaData['default_template_id'];
            if (!empty($defaultTemplateId)) {
                $value = $this->backend->teaser('template', $defaultTemplateId)
                 . ' ' . $this->cms->snippet('record.default_template_label');
            }
            else {
                $value = $this->cms->snippet('record.default_template_none');
            }
        }
        return $value;
    }

    /**
     * Ajax: eine einzelne Zeile der Detailansicht nachladen.
     *
     * Gegenstück zum Kennzeichen 'expandable' in _infoContents(): der Redakteur klappt
     * ein Feld auf, das Javascript holt sich hier den vollen Inhalt und ersetzt damit
     * den Anriss. Ausgegeben wird deshalb nur die Zeile (Vorlage 'info_table_part'),
     * nicht die ganze Tabelle.
     *
     * Zur Zugriffsprüfung wird der Datensatz regulär über Record::load() geholt - der
     * Feldname allein darf nicht genügen, um an einen Inhalt zu kommen. Er wird
     * zusätzlich hart auf [a-z0-9_-] gefiltert.
     *
     * Request: id, name
     */
    public function fieldDetails() {
        $id = (int)($_REQUEST['id'] ?? 0);
        $name = preg_replace('/[^a-z0-9_]/', '', (string)($_REQUEST['name'] ?? ''));
        
        if (empty($id)) die('error');
        if (empty($name)) die('error');

        // Zugriffsprüfung über record->load()
        if (!$data = $this->record->load($id)) die('error');
        $areaId = (int)$data['area_id'];
        $schemaVirtual = $this->record->virtualSchema($areaId, false);
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        if ($this->backend->access()->areaWrite($areaId, $ownerId)) {
            $this->backend->fileRenameIds[$id] = true;
        }

        // Zeile rendern
        $this->backend->infoAdd($this->record, $name, $data[$name], ['expandable'], $schemaVirtual, id: $id);

        // ohne Tabellengerüst ausgeben
        echo $this->backend->infoTable('info_table_part');
    }

    /**
     * Ajax (POST): ursprünglichen Dateinamen einer Datei ändern - der Stift hinter
     * "Original filename" auf der Info-Seite (Backend::fileNameCell(),
     * cApp.ui.fileRename()). Die Endung bleibt, siehe Record::renameFile().
     *
     * Antwort: der Inhalt der Tabellenzelle - neuer Name mit Stift, im Fehlerfall
     * davor die Meldung und der bisherige Name.
     *
     * Request: id (produktiver Datensatz), field_id, lang (Sprache der Datei, ''
     * bei einsprachigen Feldern), name (neuer Name ohne Endung)
     */
    public function fileRename(): void
    {
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $id = (int)($_REQUEST['id'] ?? 0);
        $fieldId = (int)($_REQUEST['field_id'] ?? 0);
        $lang = trim((string)($_REQUEST['lang'] ?? ''));
        $fileLanguageId = ($lang === '' || !ctype_digit($lang)) ? null : (int)$lang;
        if ($id < 1 || $fieldId < 1) die('error');

        // Zugriffsprüfung über record->load(), dann Schreibrecht auf den Bereich
        if (!$data = $this->record->load($id)) {
            $this->errors();
            return;
        }
        $areaId = (int)$data['area_id'];
        $ownerId = isset($data['owner_id']) ? (int)$data['owner_id'] : null;
        $access = $this->backend->access();
        if (!$access->areaWrite($areaId, $ownerId)) {
            echo $access->deniedHtml();
            return;
        }
        // Das Feld muss ein Datei-Feld dieses Bereichs sein
        $colDef = null;
        foreach ($this->record->virtualSchema($areaId, false) as $name => $def) {
            if ((int)($def['field_id'] ?? 0) === $fieldId && ($def['type'] ?? '') === 'file') {
                $colDef = $def;
                break;
            }
        }
        if ($colDef === null) die('error');

        $filename = $this->record->renameFile($id, $fieldId, $fileLanguageId, (string)($_REQUEST['name'] ?? ''));
        if ($filename === null) {
            $this->errors();
        }

        // Zelle mit dem aktuellen Stand neu ausgeben
        $this->backend->fileRenameIds[$id] = true;
        echo $this->backend->fileNameCell(
            $this->record->fileRow($id, $fieldId, $fileLanguageId) ?? [], $id, $fieldId, $fileLanguageId
        );
    }

    /**
     * Erzeugt den Frontend-Pfad (ohne führenden Slash) anhand der Datensatz-Daten
     * ($this->data) - Backend::buttonAddPreview() macht daraus je Sprache eine
     * Vorschau-Adresse (public/backend/preview.php: mit Admin-Session, auch für
     * Datensätze, die nicht oder nicht mehr veröffentlicht sind).
     *
     * Immer die record_id: derselbe Pfad gilt für alle Sprachen, Slug und
     * Slug-Präfix können aber je Sprache verschieden sein (die Vorschau leitet
     * nicht auf die kanonische URL um).
     */
    private function createPreviewLink(): string
    {
        return (string)(int)$this->data['record_id'];
    }

    /**
     * Reiter "Änderungen": der Verlauf des Datensatzes aus dem Audit-Log.
     */
    private function _infoAudit(): void
    {
        echo $this->backend->auditHistory('record', $this->id);
    }

    /**
     * Zweiter Faktor eines Profil-Datensatzes (backend/record_second_factor.php,
     * UserSecondFactor): Zustand anzeigen und - mit Schreibrecht auf den Bereich,
     * per POST - zurücksetzen. Für User, die Telefon und Wiederherstellungscodes
     * verloren haben. Das Zurücksetzen steht im Audit-Log (Handelnder = Admin).
     *
     * Request: id, area_id, function ('' | 'reset')
     */
    public function secondFactor(): void
    {
        echo $this->backend->template('container_header');
        $this->backend->setNaviLabel('#record.button_second_factor');
        $this->function = 'info';
        $this->_resolveParams();
        if ($this->function !== 'info' || !$this->data = $this->record->load($this->id)) {
            $this->errors();
            echo $this->backend->template('container_footer');
            return;
        }
        $this->areaId = (int)$this->data['area_id'];
        $access = $this->backend->access();
        if (!$access->areaRead($this->areaId)) {
            echo $access->deniedHtml();
            echo $this->backend->template('container_footer');
            return;
        }
        echo $this->backend->template('headline1', [
            '{text}' => $this->backend->template('icon', ['{name}' => 'phonelink_lock']) . ' ' . $this->backend->escape($this->cms->snippet('record.button_second_factor')),
        ]);
        if ($this->_areaType() !== 'profile') {
            echo $this->backend->template('alert_danger', ['{text}' => '{#record.error_personal_data_no_profile}']);
            echo $this->backend->template('container_footer');
            return;
        }
        $ownerId = isset($this->data['owner_id']) ? (int)$this->data['owner_id'] : null;
        $canWrite = $access->areaWrite($this->areaId, $ownerId);

        if ((string)($_REQUEST['function'] ?? '') === 'reset') {
            if (!$canWrite || !$this->backend->requirePost()) {
                echo $access->deniedHtml();
                echo $this->backend->template('container_footer');
                return;
            }
            $this->userSecondFactor->disable($this->data, true);
            $this->backend->balloon('success', $this->cms->snippet('record.message_second_factor_reset'));
        }

        $state = $this->userSecondFactor->state($this->id);
        $this->db->query("SELECT `details` FROM `area` WHERE `area_id` = ?", 'i', [$this->areaId]);
        $policy = $this->userSecondFactor->policy(['details' => (string)($this->db->fetchNum()[0] ?? '')]);
        $since = $state['enabled_at'] !== '' ? $this->cms->_formatDate($state['enabled_at']) : '';
        // Platzhalter selbst einsetzen (Snippets interpolieren nur ICU-Muster)
        $text = strtr(match ($state['method']) {
            'totp'  => $this->cms->snippet('record.second_factor_state_totp'),
            'email' => $this->cms->snippet('record.second_factor_state_email'),
            default => $this->cms->snippet('record.second_factor_state_none'),
        }, ['{date}' => $since, '{count}' => (string)(int)$state['recovery_left']]);
        echo $this->backend->template('record.second_factor_info', [
            '{state}'  => htmlspecialchars($text),
            '{policy}' => htmlspecialchars(str_replace('{policy}', $this->cms->snippet('area.option_second_factor_' . $policy),
                $this->cms->snippet('record.second_factor_policy'))),
        ]);
        if ($canWrite && $state['method'] !== '') {
            echo $this->backend->template('record.second_factor_reset_form', [
                '{id}' => (int)$this->id,
                '{area_id}' => (int)$this->areaId,
            ]);
        }
        echo $this->backend->template('container_footer');
    }

    /**
     * Menüpunkt "Datenexport personenbezogener Daten" (Modul Privacy): Profil-Bereich,
     * Modulzugriff und Zusatzrecht 'export' (Superuser immer).
     */
    private function _personalDataAllowed(): bool
    {
        $access = $this->backend->access();
        return $this->_areaType() === 'profile' && $access->module('Privacy')
            && ($access->isSuperuser() || $access->moduleRight('Privacy', 'export'));
    }

    /**
     * Typ des aktuellen Bereichs ('record', 'profile', 'ugc' …).
     */
    private function _areaType(): string
    {
        return (string)($this->instanceCache->areaInfo($this->areaId)['type'] ?? '');
    }
}
