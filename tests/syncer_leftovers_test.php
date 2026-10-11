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

use advanced_testcase;
use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/coursesync/tests/fixtures/testable_syncer.php');

/**
 * Tests for what a copy that failed part way leaves behind, and for a failure
 * not cutting a run short.
 *
 * A handler adds the course module, with the activity's identity, before it
 * adds the activity itself. One that failed in between used to leave that
 * course module behind: no instance, in no section, but carrying the
 * identity, so every later run took the activity as already here and never
 * brought it. And the developer message about the failure, said at once,
 * could end the request on a site showing developer messages, before the run
 * was written to the history.
 *
 * @package    block_coursesync
 * @copyright  2026 Course Sync project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(syncer::class)]
#[CoversClass(sync_result::class)]
final class syncer_leftovers_test extends advanced_testcase {
    /** @var int The block instance these tests sync into. */
    protected int $instanceid = 0;

    /** @var \stdClass The destination course. */
    protected \stdClass $course;

    /**
     * Put a configured, mapped block instance in a fresh course.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $page = new \moodle_page();
        $page->set_context(\context_course::instance($this->course->id));
        $page->set_course($this->course);
        $page->set_pagelayout('course');
        $page->set_pagetype('course-view-' . $this->course->format);
        $page->set_url('/course/view.php', ['id' => $this->course->id]);
        $page->blocks->add_region('side-pre');
        $page->blocks->load_blocks();
        $page->blocks->add_block('coursesync', 'side-pre', 0, false, 'course-view-*');

        $instance = $DB->get_record('block_instances', [
            'blockname' => 'coursesync',
            'parentcontextid' => \context_course::instance($this->course->id)->id,
        ], '*', MUST_EXIST);

        $this->instanceid = (int) $instance->id;

        connection::set_url($this->instanceid, $this->course->id, 'https://source.example.edu');
        connection::set_token($this->instanceid, 'abcdef0123456789abcdef0123456789');
        connection::set_remote_course(
            $this->instanceid,
            'REMOTE1',
            course_result::success(42, 'REMOTE1', 'Remote Source Course', true, 1)
        );
    }

    /**
     * A course module as a failed copy left it: no instance, no section, the identity.
     *
     * @param string $idnumber
     * @return int the course module id
     */
    protected function leftover(string $idnumber): int {
        global $DB;

        return (int) $DB->insert_record('course_modules', (object) [
            'course' => $this->course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST),
            'instance' => 0,
            'section' => 0,
            'idnumber' => $idnumber,
            'added' => time(),
        ]);
    }

    /**
     * A client that answers a detection call and then any payload calls.
     *
     * @param array $payloads one response per activity that gets fetched
     * @return http_client
     */
    protected function mock_source(array $payloads): http_client {
        return new http_client(['mock' => new MockHandler(array_merge(
            [new Response(200, [], json_encode([[
                'cmid' => 11,
                'modname' => 'page',
                'name' => 'Week one',
                'idnumber' => '',
                'timemodified' => time(),
            ]]))],
            $payloads
        ))]);
    }

    /**
     * The page as the source site sends it.
     *
     * @param int $display the page's display setting
     * @return Response
     */
    protected function payload(int $display = 5): Response {
        return new Response(200, [], json_encode([
            'cmid' => 11,
            'modname' => 'page',
            'name' => 'Week one',
            'idnumber' => '',
            'sectionnum' => 1,
            'visible' => true,
            'intro' => '<p>Intro.</p>',
            'introformat' => FORMAT_HTML,
            'timemodified' => time(),
            'settings' => [
                ['name' => 'content', 'value' => '<p>Body.</p>'],
                ['name' => 'contentformat', 'value' => (string) FORMAT_HTML],
                ['name' => 'display', 'value' => (string) $display],
            ],
            'children' => [],
            'files' => [],
        ]));
    }

    /**
     * A course module with no instance is not taken as the activity being here.
     */
    public function test_a_leftover_does_not_count_as_the_activity(): void {
        $this->leftover(syncer::build_idnumber(11));

        $this->assertSame(0, syncer::find_existing($this->course->id, 11));
    }

    /**
     * The next run takes the leftover out and copies the activity.
     */
    public function test_the_next_run_clears_a_leftover_and_copies_the_activity(): void {
        global $DB;

        $leftover = $this->leftover(syncer::build_idnumber(11));

        $result = syncer::run($this->instanceid, $this->course->id, true, $this->mock_source([$this->payload()]), [11]);

        $this->assertSame(1, $result->count('created'));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $leftover]));

        $cmid = syncer::find_existing($this->course->id, 11);
        $this->assertNotSame(0, $cmid);
        $this->assertSame('Week one', get_fast_modinfo($this->course->id)->get_cm($cmid)->name);
    }

    /**
     * After a failed copy, what the handler made is taken out, and an activity
     * someone else added meanwhile is left alone.
     */
    public function test_what_a_failed_copy_made_is_taken_out(): void {
        global $DB;

        $before = (int) $DB->get_field_sql(
            'SELECT MAX(id) FROM {course_modules} WHERE course = ?',
            [$this->course->id]
        );

        $empty = $this->leftover(syncer::build_idnumber(11));
        $unnamed = $this->leftover('');
        $finished = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('course_modules', 'idnumber', syncer::build_idnumber(11), ['id' => $finished->cmid]);
        $someoneelses = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $result = new sync_result();
        testable_syncer::remove_unfinished_for_test(
            (int) $this->course->id,
            $before,
            syncer::build_idnumber(11),
            'page',
            $result
        );

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $empty]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $unnamed]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $finished->cmid, 'deletioninprogress' => 0]));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $someoneelses->cmid]));
        $this->assertSame([], $result->diagnostics);
    }

    /**
     * A copy that fails after its course module was added leaves nothing
     * behind, is written to the history, and is reported for developers.
     *
     * The display setting is too big for its column, so the page itself cannot
     * be saved - after the handler has added the course module.
     */
    public function test_a_copy_failing_part_way_leaves_nothing_behind(): void {
        global $DB;

        $before = $DB->count_records('course_modules', ['course' => $this->course->id]);

        $result = syncer::run(
            $this->instanceid,
            $this->course->id,
            true,
            $this->mock_source([$this->payload(99999999)]),
            [11]
        );
        $this->assertDebuggingCalled();

        $this->assertSame(1, $result->count('failed'));
        $this->assertSame(0, $result->count('created'));
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $this->course->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['idnumber' => syncer::build_idnumber(11)]));
        $this->assertNotSame([], $result->diagnostics);
        $this->assertTrue($DB->record_exists('block_coursesync_run', ['id' => $result->runid]));
        $this->assertFalse($result->lastsyncupdated);
    }
}
