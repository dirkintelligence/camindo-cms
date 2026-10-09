<?php
/**
 * Backend-Oberfläche für die Eingabefelder eines Inhaltsbereichs (Tabelle `field`).
 *
 * Einstiegspunkte (die Scripte liegen in backend/):
 *
 *     field_list.php     -> list()              Feldliste eines Bereichs
 *     field_info.php     -> info()              Detailansicht eines Feldes
 *     field_create.php   -> create()            Schritt 1: Feldtyp auswählen
 *     field_edit.php     -> edit()              Schritt 2 und alles weitere
 *     field_convert.php  -> convert()           Typ / Mehrsprachigkeit nachträglich ändern
 *     ajax_*.php         -> updateVisibility()  Sichtbarkeits-Häkchen in der Liste
 *
 * ANLEGEN IN ZWEI SCHRITTEN
 * --------------------
 * Anders als in den übrigen Modulen führt "Neu" nicht direkt ins Formular. create()
 * zeigt erst die Typauswahl, weil der Typ das Formular bestimmt und sich über die
 * Maske nicht mehr ändern lässt. Der gewählte Typ landet als &function=create&data[type]=…
 * in der URL, edit() baut daraus das passende Formular.
 *
 * TYP ÄNDERN NUR AUSDRÜCKLICH
 * --------------------
 * Typ und Mehrsprachigkeit eines bestehenden Feldes ändert allein convert() - über den
 * Button "Typ ändern" der Detailansicht: erst die Auswahl der möglichen Ziele
 * (Field::conversionTargets(), mit Hinweis, wo Inhalte verloren gehen können), dann
 * eine Bestätigung, dann Field::convert(). Der Schalter "mehrsprachig" in der
 * Eingabemaske ist bei bestehenden Feldern deshalb nur noch eine Anzeige.
 *
 * DAS FORMULAR IST FÜR JEDEN TYP EIN ANDERES
 * --------------------
 * _editForm() wählt zuerst eine HTML-Vorlage (area.field_form_*) und blendet dann Block
 * für Block die Eingaben ein, die zu Typ und display_type passen: Präfix/Suffix nur bei
 * Text- und Zahlenfeldern, Zielbereiche nur bei Relationen und Pointern, Mime-Typen nur
 * beim Upload, Konverter nur beim Thumbnail. Was nicht passt, wird nicht gerendert -
 * die zugehörigen Platzhalter räumt form(removePlaceholders: true) am Ende weg.
 *
 * META-FELDER
 * --------------------
 * Trägt ein Feld den Namen einer festen Spalte aus Cms::$schema (status_id, online_at,
 * template_id, …), gilt es als Meta-Feld: Name und Typ stehen fest und werden nur noch
 * angezeigt (formStatic + formHidden). Der Redakteur kann daran nur Beschriftung,
 * Darstellung und Sichtbarkeit einstellen - der Inhalt liegt in `record` selbst und
 * nicht in einer data_*-Tabelle.
 *
 * SICHTBARKEIT ALS PSEUDO-FELD
 * --------------------
 * In der Datenbank sind es drei Bit-Spalten (visibility_input / _search / _list). Im
 * Formular ist es EINE Checkbox-Gruppe namens 'visibility'. _visibilitySerialize() macht
 * aus den drei Spalten die Liste, _visibilityUnserialize() beim Speichern wieder drei
 * Werte daraus. Der Sonderfall 'fulltext' hat nur die Suchsichtbarkeit und umgeht die
 * Umwandlung ganz.
 *
 * @see Camindo\Core\Modules\Area\Field       Dataset/Model der Tabelle `field`
 * @see Camindo\Core\Modules\Area\AreaBackend Backend des zugehörigen Bereichs
 * @see Camindo\Core\Backend                    $displayTypes, $validationFlags, formField()
 */
namespace Camindo\Core\Modules\Area;

use Camindo\Core\Backend;
use Camindo\Core\System;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\InstanceCache;
use Camindo\Core\Modules\Area\Area;
use Camindo\Core\Modules\Area\Field;

abstract class FieldBackend {
    protected int $id = 0;
    protected int $areaId = 0;
    protected ?array $data = [];
    protected ?array $areaData = [];
    protected string $function = '';

    // Die drei Sichtbarkeiten; im Formular eine Checkbox-Gruppe, in der Tabelle drei
    // Bit-Spalten visibility_{input|search|list}. Dient auch als Weißliste in
    // updateVisibility().
    protected array $visibilityFields = ['input', 'search', 'list'];

    // Verknüpfungen je abgewähltem Zielbereich (area_id => Anzahl) - gefüllt von
    // _editSave(), gezeigt von _editConfirmLinksForm()
    protected array $linkCounts = [];

    protected array $fieldSchema = [];  // Schema der Tabelle `field` (die Felddefinition)
    protected array $recordSchema = []; // Schema der Tabelle `record` (die Meta-Felder)

    public function __construct(
        protected Backend $backend,
        protected System $system,
        protected Database $db,
        protected Cms $cms,
        protected Crypt $crypt,
        protected Area $area,
        protected Field $field,
        protected InstanceCache $instanceCache,
        protected AreaBackend $areaBackend,     // Hinweis "Volltext veraltet" (Feldliste)
    )
    {}

    /**
     * Headline mit Feld-Label
     */
    protected function _headline(): void
    {
        // Icon
        $icon = $this->backend->template('area.icon_field');

        // Label
        $label = ($this->function === 'delete')
            ? ($this->data['label'] ?? '')
            : $this->cms->snippet('area.headline_field_info');

        // H1
        echo $this->backend->template('headline1', [
            '{text}' => $icon . ' ' . $label,
        ]);
    }

    /**
     * Bereichsdaten laden und das Bereichs-Label als Kopfzeile zurückgeben.
     *
     * Gibt HTML zurück, statt es auszugeben - der Aufrufer entscheidet, ob und wo es
     * hingehört. Schlägt das Laden fehl, wird zusätzlich $this->function auf 'skip'
     * gesetzt: edit() überspringt dann Formular und Aktionen und zeigt nur den Fehler.
     */
    protected function _loadArea(): string
    {
        if ($this->areaData = $this->area->load($this->areaId)) {
            // Bereichslabel ausgeben
            $areaLabel = $this->cms->textOrSnippet($this->areaData['label']);
            return $this->backend->template('area.prefix_content_area', [
                '{text}' => str_replace('{area_label}', $areaLabel, $this->cms->snippet('area.headline_area_label'))
            ]);
        }
        else {
            // Area not found
            $this->function = 'skip';
            $this->areaData = [];
            return $this->backend->template('alert_danger', [
                '{text}' => '{#area.error_area_not_found}'
            ]);
        }
    }

    protected function _extensionFile(string $extension, string $script): ?string
    {
        $dir = $this->cms->componentPath('extensions', $extension);
        if ($dir === null) return null;
        $file = $dir . '/backend/' . $script;
        return is_file($file) ? $file : null;
    }
}