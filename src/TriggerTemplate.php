<?php
/**
 * Base class of the trigger templates (`rel_area_trigger`).
 *
 * A trigger template contains only method definitions. TriggerTemplates puts
 * the compiled code into an anonymous class that inherits from this class - the
 * methods of the template thus become methods of an object:
 *
 *     function onSave(array &$affectedFields, array &$data, array &$previousData): void
 *     {
 *         $data['title'] = trim($data['first_name'] . ' ' . $data['last_name']);
 *         if (!in_array('title', $affectedFields, true)) $affectedFields[] = 'title';
 *     }
 *
 * The template can use the following:
 *
 *     $this->cms        Cms (snippet(), error(), select() ...)
 *     $this->db         Database
 *     $this->record     Record - the dataset that triggers the event
 *     $this->areaId     Area for which the template was loaded (if it is
 *                       assigned to several: the first one in the request)
 *     $this->temporary  true: saving a working copy temporarily (tab switch,
 *                       stayTemporary) - onSave/onSaved then run as well
 *     $this->...        the lazily loadable services of Page (email, pagination ...)
 *
 * Helper methods can be added freely (private function ...). The
 * event methods are optional - if one is missing, nothing happens for that event.
 * The list of events and their signatures: TriggerTemplates::EVENTS.
 *
 * @see Camindo\Core\TriggerTemplates
 */
namespace Camindo\Core;

use Camindo\Core\Modules\Record\Record;

abstract class TriggerTemplate
{
    /** Temporarily saving a working copy (see class comment) */
    public bool $temporary = false;

    public function __construct(
        protected Cms $cms,
        protected Database $db,
        protected Record $record,
        protected ContainerInterface $container,
        public readonly int $areaId
    ) {}

    /**
     * Lazily loadable services of Page ($this->email, $this->pagination ...) - the same
     * names under which the compiled template code addresses them.
     */
    public function __get(string $name)
    {
        return $this->container->get(Page::class)->$name;
    }
}
