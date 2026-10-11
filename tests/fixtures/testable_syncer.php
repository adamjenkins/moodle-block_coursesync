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

namespace block_coursesync;

/**
 * Exposes the leftover clean-up to the tests.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_syncer extends syncer {
    /**
     * Call remove_unfinished().
     *
     * @param int $courseid
     * @param int $lastcmid
     * @param string $idnumber
     * @param string $modname
     * @param sync_result $result
     * @return void
     */
    public static function remove_unfinished_for_test(
        int $courseid,
        int $lastcmid,
        string $idnumber,
        string $modname,
        sync_result $result
    ): void {
        self::remove_unfinished($courseid, $lastcmid, $idnumber, $modname, $result);
    }
}
