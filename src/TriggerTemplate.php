<?php
/**
 * Basisklasse der Trigger-Templates (`rel_area_trigger`).
 *
 * Ein Trigger-Template enthält nur Methoden-Definitionen. TriggerTemplates packt
 * den kompilierten Code in eine anonyme Klasse, die von dieser Klasse erbt - die
 * Methoden des Templates werden so zu Methoden eines Objekts:
 *
 *     function onSave(array &$affectedFields, array &$data, array &$previousData): void
 *     {
 *         $data['title'] = trim($data['first_name'] . ' ' . $data['last_name']);
 *         if (!in_array('title', $affectedFields, true)) $affectedFields[] = 'title';
 *     }
 *
 * Im Template stehen damit bereit:
 *
 *     $this->cms        Cms (snippet(), error(), select() ...)
 *     $this->db         Database
 *     $this->record     Record - der Dataset, der das Ereignis auslöst
 *     $this->areaId     Bereich, für den das Template geladen wurde (ist es
 *                       mehreren zugewiesen: der erste im Request)
 *     $this->temporary  true: Zwischenspeichern einer Arbeitskopie (Reiterwechsel,
 *                       stayTemporary) - onSave/onSaved laufen dann ebenfalls
 *     $this->...        die nachladbaren Dienste von Page (email, pagination ...)
 *
 * Hilfsmethoden können frei ergänzt werden (private function ...). Die
 * Ereignis-Methoden sind optional - fehlt eine, passiert bei dem Ereignis nichts.
 * Die Liste der Ereignisse und ihre Signaturen: TriggerTemplates::EVENTS.
 *
 * @see Camindo\Core\TriggerTemplates
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\Record;

abstract class TriggerTemplate
{
    /** Zwischenspeichern einer Arbeitskopie (siehe Klassenkommentar) */
    public bool $temporary = false;

    public function __construct(
        protected Cms $cms,
        protected Database $db,
        protected Record $record,
        protected ContainerInterface $container,
        public readonly int $areaId
    ) {}

    /**
     * Nachladbare Dienste von Page ($this->email, $this->pagination ...) - dieselben
     * Namen, unter denen der kompilierte Template-Code sie anspricht.
     */
    public function __get(string $name)
    {
        return $this->container->get(Page::class)->$name;
    }
}
