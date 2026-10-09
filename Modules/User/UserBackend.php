<?php
/**
 * Backend UI of the module User.
 *
 * The module User is intended for the frontend community (visitor login,
 * registration, shopping cart).
 * On the start page, all content grouped by content areas that were created / changed by users is deliberately
 * listed. This is determined by means of the bit field "needs_review" explicitly reserved for it.
 * The regular workflow is mapped via the field status_id.
 *
 * THE MODULE IS A WIDGET, NOT A TILE
 * --------------------
 * There is nothing to manage, only something to see - therefore the module has
 * 'button' => false in its settings.php and a 'widget' instead. On the
 * start page it is where the administrator places it in their settings
 * (Camindo\Core\Widgets); widget() returns exactly the content that list() shows.
 * list() itself remains as a full page (backend/index.php) - the widget
 * is an excerpt of it, not a second presentation.
 *
 * @see Camindo\Core\Widgets                      start page widgets of the modules
 * @see Camindo\Core\Modules\Record\RecordUser   frontend commands CMS:USER_* (login, contributions, shopping cart)
 * @see developer docs, chapter 12 (Community)
 */
namespace Camindo\Core\Modules\User;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Modules\Area\Area;

class UserBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private System $system,
        private Cms $cms,
        private Crypt $crypt,
        private Area $area,
    )
    {}

    /**
     * Output the overview of the content to be proofread.
     *
     * One button per content area with area name, icon and number of open
     * records; if there is nothing to do, a success message appears instead.
     *
     * (The previous comment "Render Folder-Area-Tree" described something else.)
     */
    public function list()
    {
        // Breadcrumb navigation
        $this->backend->setNaviLabel('#headline_unreviewed');

        // Container header
        echo $this->backend->template('container_header');

        $unreviewedIcon = $this->backend->template('user.icon_unreviewed');
        echo $this->backend->template('headline1', [
            '{text}' => $unreviewedIcon. ' {#user.headline_unreviewed}'
        ]);
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        echo $this->_unreviewedList();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Widget for the start page (settings.php 'widget').
     *
     * The same content as list(), without page frame and headline - those come
     * from the widget card.
     */
    public function widget(): string
    {
        return $this->_unreviewedList();
    }

    /**
     * The list of content to be proofread: one button per content area,
     * or the success message if nothing is open.
     *
     * Area name and icon come from `area` and thus from the backend - still
     * escaped: an area name is an input field, not an identifier.
     */
    private function _unreviewedList(): string
    {
        $unreviewedByArea = $this->_unreviewedByArea();
        if (!$unreviewedByArea) {
            return $this->backend->template('alert_success', [
                '{text}' => '{#user.message_no_new_content}'
            ]);
        }

        $items = [];
        foreach ($unreviewedByArea as $item) {
            $items[] = $this->backend->template('user.area_review_button', [
                '{area_id}' => (string)(int)$item['area_id'],
                '{area_label}' => htmlspecialchars($this->cms->textOrSnippet((string)$item['label'])),
                '{area_icon}' => htmlspecialchars((string)$item['icon']),
                '{number}' => (string)(int)$item['number']
            ]);
        }
        return $this->backend->template('ul_unstyled', [
            '{items}' => '<li>' . implode("</li>\n<li>", $items). '</li>'
        ]);
    }

    /**
     * Count open proofreading tasks per content area.
     *
     * LEFT JOIN on `area`, so that records with an area that has meanwhile been deleted do not
     * fall through the cracks - label and icon then stay empty there.
     *
     * @return ?array  rows with area_id, label, icon, number - empty if nothing is open
     */
    private function _unreviewedByArea(): ?array
    {
        $query = "SELECT `r`.`area_id`, `a`.`label`, `a`.`icon`, COUNT(`r`.`record_id`) `number`"
            ." FROM `record` `r` LEFT JOIN `area` `a`"
            ." ON `r`.`area_id` = `a`.`area_id`"
            ." WHERE `r`.`needs_review`=1"
            ." GROUP BY `a`.`area_id`"
            ." ORDER BY `a`.`position` ASC";
        $this->db->query($query);
        return $this->db->fetchAll();
    }
}