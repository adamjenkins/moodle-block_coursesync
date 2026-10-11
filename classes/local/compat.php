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

namespace block_coursesync\local;

/**
 * Core calls that differ between the Moodle versions Course Sync supports.
 *
 * Moodle 5.2 moved course module editing into the course format actions and
 * deprecated the global functions, which 5.1 still needs. Each method here
 * uses the new call where it exists and the old one otherwise.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compat {
    /**
     * Delete a course module and its activity.
     *
     * @param int $cmid
     * @param bool $async whether a site that supports it may delete in the background
     * @return void
     */
    public static function delete_module(int $cmid, bool $async = false): void {
        global $CFG, $DB;

        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            // Moodle 5.2 and later, which deprecated course_delete_module().
            $courseid = $DB->get_field('course_modules', 'course', ['id' => $cmid]);

            if ($courseid === false) {
                return;
            }

            \core_courseformat\formatactions::cm((int) $courseid)->delete($cmid, $async);

            return;
        }

        require_once($CFG->dirroot . '/course/lib.php');
        \course_delete_module($cmid, $async);
    }
}
