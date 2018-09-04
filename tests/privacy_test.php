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
 * Tests for the implementation of the Privacy Provider API for mod_certificate.
 *
 * @package mod_certificate
 * @copyright 2018 Huong Nguyen <huongnv13@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use mod_certificate\privacy\provider;

/**
 * Tests for the implementation of the Privacy Provider API for mod_certificate.
 *
 * @package mod_certificate
 * @copyright 2018 Huong Nguyen <huongnv13@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_certificate_privacy_provider_testcase extends \core_privacy\tests\provider_testcase {

    protected $currentuser = '';
    protected $course = '';
    protected $cm = '';
    protected $certificate = '';
    protected $issue = '';

    /**
     * Prepares things before this test case is initialised
     *
     * @return void
     */
    public static function setUpBeforeClass() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/certificate/locallib.php');
    }

    /**
     * Test setUp.
     */
    public function setUp() {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_certificate');
        $user = $this->getDataGenerator()->create_user();
        $certificate = $generator->create_instance(array('course' => $course->id));
        $cm = get_coursemodule_from_id('certificate', $certificate->cmid);
        $issue = certificate_get_issue($course, $user, $certificate, $cm);
        $context = context_module::instance($certificate->cmid);
        $this->course = $course;
        $this->currentuser = $user;
        $this->certificate = $certificate;
        $this->cm = $cm;
        $this->issue = $issue;
        $fs = get_file_storage();
        // Add a fake certificate.
        $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_certificate',
                'filearea' => 'issue',
                'itemid' => $issue->id,
                'filepath' => '/',
                'filename' => 'fakecertificate.pdf',
        ], 'image contents (not really)');
    }

    /**
     * Test get context for userid.
     */
    public function test_get_contexts_for_userid() {
        $contextlist = provider::get_contexts_for_userid($this->currentuser->id);
        $context = context_module::instance($this->certificate->cmid);

        $this->assertEquals(1, count($contextlist->get_contextids()));
        $this->assertEquals($context, $contextlist->current());
    }

    /**
     * Test that data is exported correctly for this plugin.
     */
    public function test_export_user_data() {
        $user = $this->currentuser;
        $context = context_module::instance($this->certificate->cmid);
        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        $appctx = new approved_contextlist($user, 'mod_certificate', $contextids);
        provider::export_user_data($appctx);
        $contextdata = writer::with_context($context);
        $issue = $this->issue;
        $area[] = $issue->id;
        $exporteddata = $contextdata->get_data([$issue->id]);

        $this->assertEquals(transform::user($issue->userid), $exporteddata->userid);
        $this->assertEquals($issue->certificateid, $exporteddata->certificateid);
        $this->assertEquals($issue->code, $exporteddata->code);
        $this->assertEquals(transform::datetime($issue->timecreated), $exporteddata->timecreated);
        $this->assertArrayHasKey('fakecertificate.pdf', $contextdata->get_files($area));
    }

    /**
     * Test delete data for user.
     */
    public function test_delete_data_for_user() {
        $user = $this->currentuser;
        $context = context_module::instance($this->certificate->cmid);
        $fs = get_file_storage();
        $issue = $this->issue;
        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        $appctx = new approved_contextlist($user, 'mod_certificate', $contextids);

        provider::delete_data_for_user($appctx);
        writer::reset();
        provider::export_user_data($appctx);
        $contextdata = writer::with_context($context);

        $this->assertFalse($contextdata->has_any_data());
        $this->assertEmpty($fs->get_area_files($context->id,
                'mod_certificate', 'issue', $issue->id));
    }

    /**
     *  Test delete data for all users in context.
     */
    public function test_delete_data_for_all_users_in_context() {
        global $DB;

        $user = $this->currentuser;
        $context = context_module::instance($this->certificate->cmid);
        $fs = get_file_storage();
        $issue = $this->issue;
        $contextids = provider::get_contexts_for_userid($user->id)->get_contextids();
        $appctx = new approved_contextlist($user, 'mod_certificate', $contextids);
        provider::export_user_data($appctx);
        $this->assertTrue(writer::with_context($context)->has_any_data());
        writer::reset();
        provider::delete_data_for_all_users_in_context($context);
        $area[] = $issue->id;
        $module = $DB->get_record('certificate_issues', ['id' => $issue->certificateid]);

        $this->assertEmpty(writer::with_context($context)->get_data($area));
        $this->assertEmpty($fs->get_area_files($context->id,
                'mod_certificate', 'issue', $issue->id));
        $this->assertEmpty($module);
    }
}
