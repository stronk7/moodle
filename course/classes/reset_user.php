<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Executor class in charge of performing a complete user reset from a course.
 *
 * @package    core_course
 * @subpackage course
 * @copyright  2020 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_course;

use context_course;
use context_user;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist_collection;
use core_privacy\manager;
use core_user;

class reset_user implements \executable {

    /** @var int id of the course the user will be reset from */
    protected $courseid;
    /** @var int id of the user to be reset from the course */
    protected $userid;

    /** @var bool true to enable the class to output execution information. Defaults to false. */
    protected $verbose;

    /**
     * Construct the reset_user instance
     *
     * @param int int $courseid id of the course the user will be reset from
     * @param int $userid  id of the user to be reset from the course
     */
    public function __construct(int $courseid, int $userid) {
        $this->courseid = $courseid;
        $this->userid = $userid;
    }

    /**
     * Execute the user reset
     *
     * @param bool $verbose true to enable the class to output execution information. Defaults to false
     *
     * @return bool true if the execution ended ok, false otherwise.
     */
    public function execute(bool $verbose = false) : bool {
        $this->verbose = $verbose;

        // TODO TB) Is there anything we want to be saved or kept. Here it's the place where saving should happen.
        $this->out('!!! TODO TB) Anything we want to be saved or kept undeleted. Here it\'s the place where it should be saved');

        // Complete, unconditional removal of user from course begins here.
        $this->out('Removal of user from course begins:');

        // Get the list of target contexts we are interested on.
        // (that is, course and all children)
        $coursectx = context_course::instance($this->courseid);
        $childctx = $coursectx->get_child_contexts();
        $userctx = context_user::instance($this->userid);
        $targetcontextids = array_keys($childctx + [$coursectx->id => $coursectx]);

        $user = core_user::get_user($this->userid, 'id', MUST_EXIST);

        // Get a collection of contextlists for the user (these are sitewide,
        // no way to filter by context, that would be nice to have).
        $manager = new manager();
        // This generates a lot of textual output, because the text logger is harcoded in \core_privacy\manager
        // TODO: Consider to make the logger optional/configurable and or extend the manager to use a null provider.
        ob_start();
        $ctxlistcollection = $manager->get_contexts_for_userid($this->userid);
        $output = ob_get_clean();
        $this->out($output);

        // Now we have to process the contextlist_collection, filtering out all the contexts we aren't interested on.
        // At the same time, we go converting the lists to approved contextlists.
        $this->out('Filtering out unrelated contexts from the contextlists');
        $approvedctxlistcollection = new contextlist_collection($this->userid); // To store the new approved contextlists.
        foreach ($ctxlistcollection as $key => $ctxlist) {
            $filteredcontextids = array_intersect($ctxlist->get_contextids(), $targetcontextids);
            if ($filteredcontextids) {
                $approvedctxlistcollection->add_contextlist(
                    new approved_contextlist( $user, $ctxlist->get_component(), $filteredcontextids));
            }
        }

        $this->out('Effective removal starts, hope you did not launch this by mistake... too late!');

        // TODO: Surely we need to create a manager observer to be able to get informed somehow about problems.
        // $manager->set_observer(new \tool_dataprivacy\manager_observer());

        // TODO: Consider to make the logger optional/configurable and or extend the manager to use a null provider.
        ob_start();
        $manager->delete_data_for_user($approvedctxlistcollection);
        $output = ob_get_clean();
        $this->out($output);

        $this->out('Removal of user from course finished');

        // TODO TB) Is there anything we want to be kept. Here it's the place where restore should happen.
        $this->out('!!! TODO TB) Anything we want to be kept undeleted. Here it\'s the place where it should be restored');

        // Arrived here, everytihng went ok.
        return true;
    }

    /**
     * Simple output method to show progress information
     *
     * This doesn't make any difference about html/console, it's here just for debugging
     * purposes and surely can be removed on final implementation.
     *
     * @param string $text the contents to be output.
     */
    private function out(string $text) : void {
        if ($this->verbose) {
            echo '<pre>' . s($text) . '</pre>';
        }
    }
}
