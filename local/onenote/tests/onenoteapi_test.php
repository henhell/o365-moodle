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
 * Unit tests for the onenote_api class.
 *
 * In order to run these tests, you need to do the following:
 * 1) Create a file phpu_config_data.json and place it in the same folder as this file.
 * 2) The file should contain config data for running these unit tests: 
 * {
 *   "client_id": "valid client id for the Microsoft application you want to use for testing",
 *   "client_secret": "valid client secret for the Microsoft application you want to use for testing",
 *   "refresh_tokens": [
 *       "valid refresh token for the first Microsoft Account user you want to use for testing",
 *       "valid refresh token for the second Microsoft Account user you want to use for testing"
 *    ]
 *  }
 *  3) Run the unit tests using the standard process for running PHP Unit tests for Moodle.
 */
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/local/onenote/onenote_api.php');
require_once($CFG->dirroot . '/mod/assign/tests/base_test.php');

/**
 * Class microsoft_onenote_testcase
 */
class microsoft_onenote_testcase extends advanced_testcase
{
    private $onenoteapi;

    protected $user;

    protected $user1;

    protected $course1;

    protected $course2;

    protected $cm;

    protected $cm1;

    protected $context;

    protected $context1;

    protected $assign;

    protected $assign1;
    
    protected $config;

    public function setup() {
        global $CFG;
        
        $this->resetAfterTest(true);
        
        // Read settings from config.json.
        $configdata = file_get_contents($CFG->dirroot . '/local/onenote/tests/phpu_config_data.json');
        if (!$configdata) {
            echo 'Please provide PHPUnit testing configs in a config.json file';
            return false;
        }
        
        $this->config = json_decode($configdata, false);
        
        $this->user = $this->getDataGenerator()->create_user();
        $this->user1 = $this->getDataGenerator()->create_user();
        $this->course1 = $this->getDataGenerator()->create_course();
        $this->course2 = $this->getDataGenerator()->create_course();
        
        // Setting user and enrolling to the courses created with teacher role.
        $this->setUser($this->user->id);
        $c1ctx = context_course::instance($this->course1->id);
        $c2ctx = context_course::instance($this->course2->id);
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course1->id, 4);
        $this->getDataGenerator()->enrol_user($this->user->id, $this->course2->id, 4);
        $this->assertCount(2, enrol_get_my_courses());
        $courses = enrol_get_my_courses();
        
        // Student enrollment.
        $this->setUser($this->user1->id);
        $this->getDataGenerator()->enrol_user($this->user1->id, $this->course1->id, 5);
        $this->getDataGenerator()->enrol_user($this->user1->id, $this->course2->id, 5);
        
        $this->assertCount(2, get_enrolled_users($c1ctx));
    }

    public function set_test_config() {
        set_config('clientid', $this->config->client_id, 'local_msaccount');
        set_config('clientsecret', $this->config->client_secret, 'local_msaccount');
        $this->onenoteapi = onenote_api::getInstance();
    }

    public function set_user($index) {
        if ($index == 0) {
            $this->setUser($this->user->id);
        } else {
            $this->setUser($this->user1->id);
        }
        $this->onenoteapi->get_msaccount_api()->store_refresh_token($this->config->refresh_tokens[$index]);
        $this->assertEquals(true, $this->onenoteapi->get_msaccount_api()->refresh_token());
        $this->assertEquals(true, $this->onenoteapi->get_msaccount_api()->is_logged_in());
    }

    public function test_getitemlist() {
        $this->set_test_config();
        $this->set_user(0);
        
        $itemlist = $this->onenoteapi->get_items_list();
        $notesectionnames = array();
        $course1 = $this->course1->fullname;
        $course2 = $this->course2->fullname;
        $expectednames = array(
            'Moodle Notebook',
            $course1,
            $course2
        );
        
        foreach ($itemlist as $item) {
        if ($item['title'] == "Moodle Notebook") {
                array_push($notesectionnames, "Moodle Notebook");
                $itemlist = $this->onenoteapi->get_items_list($item['path']);
            foreach ($itemlist as $item) {
                    array_push($notesectionnames, $item['title']);
                }
            }
        }
        $this->assertTrue(in_array("Moodle Notebook", $notesectionnames), "Moodle Notebook not present");
        $this->assertTrue(in_array($course1, $notesectionnames), "Test course1 is not present");
        $this->assertTrue(in_array($course2, $notesectionnames), "Test course2 is  not present");
        $this->assertTrue(count($expectednames) == count(array_intersect($expectednames, $notesectionnames)),
            "Same elements are not present");
        $this->assertNotEmpty($itemlist, "No value");
    }

    public function test_getpage() {
        $this->set_test_config();
        $this->set_user(0);
        
        $itemlist = $this->onenoteapi->get_items_list();
        
        // Creating a testable assignment.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $params['course'] = $this->course1->id;
        $instance = $generator->create_instance($params);
        $this->cm = get_coursemodule_from_instance('assign', $instance->id);
        $this->context = context_module::instance($this->cm->id);
        $this->assign = new testable_assign($this->context, $this->cm, $this->course1);
        $assigndetails = $this->assign->get_instance();
        $assignid = $assigndetails->id;
        
        // To get the notebooks of student.
        $this->set_user(1);
        
        $itemlist = $this->onenoteapi->get_items_list();
        
        // Student submission to onenote.
        $createsubmission = $this->create_submission_feedback($this->cm, false, false, null, null, null);
        $this->submission = $this->assign->get_user_submission($this->user1->id, true);
        
        // Saving the assignment.
        $data = new stdClass();
        $saveassign = new assign_submission_onenote($this->assign, '');
        $saveassign = $saveassign->save($this->submission, $data);
        
        // Creating feedback for submission.
        $this->set_user(0);
        
        // Saving the grade.
        $this->grade = $this->assign->get_user_grade($this->user1->id, true);
        $gradeassign = new assign_feedback_onenote($this->assign, '');
        $gradeassign = $gradeassign->save($this->grade, $data);
        $gradeid = $this->grade->grade;
        $createfeedback = $this->create_submission_feedback($this->cm, true, true, $this->user1->id,
            $this->submission->id, $gradeid);
        
        if (filter_var($createsubmission, FILTER_VALIDATE_URL)) {
            if (strpos($this->course1->fullname, urldecode($createsubmission))) {
                $this->assertTrue("The value is present");
            }
        }
        
        if (filter_var($createfeedback, FILTER_VALIDATE_URL)) {
            if (strpos($this->course1->fullname, urldecode($createfeedback))) {
                $this->assertTrue("The value is present");
            }
        }
    }

    public function test_downloadpage() {
        $this->set_test_config();
        $this->set_user(0);
        
        $itemlist = $this->onenoteapi->get_items_list();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $params['course'] = $this->course2->id;
        $instance = $generator->create_instance($params);
        $this->cm = get_coursemodule_from_instance('assign', $instance->id);
        $this->context = context_module::instance($this->cm->id);
        $this->assign = new testable_assign($this->context, $this->cm, $this->course2);
        $assigndetails = $this->assign->get_instance();
        $assignid = $assigndetails->id;
        
        // To get the notebooks of student.
        $this->set_user(1);
        $itemlist = $this->onenoteapi->get_items_list();
        
        $createsubmission = $this->create_submission_feedback($this->cm, false, false, null, null, null);
        $this->submission = $this->assign->get_user_submission($this->user1->id, true);
        // Saving the assignment.
        $data = new stdClass();
        $saveassign = new assign_submission_onenote($this->assign, '');
        $saveassign = $saveassign->save($this->submission, $data);
        
        $this->assertNotEmpty($saveassign, "File has not created");
    }

    public function create_submission_feedback($cm, $wantfeedbackpage = false, $isteacher = false, $submissionuserid = null,
                                               $submissionid = null, $gradeid = null) {
        $submissionfeedback = $this->onenoteapi->get_page($cm->id, $wantfeedbackpage, $isteacher,
            $submissionuserid, $submissionid, $gradeid);
        return $submissionfeedback;
    }
}
