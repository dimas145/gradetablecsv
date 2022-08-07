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
 * @package   gradeexport_gradetablecsv
 * @copyright 2022, Dimas 13518069@std.stei.itb.ac.id
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/export/lib.php');
require_once($CFG->libdir . '/csvlib.class.php');

class grade_export_gradetablecsv extends grade_export {

    public $plugin = 'gradetablecsv';

    public $separator; // default separator

    /**
     * Constructor should set up all the private variables ready to be pulled
     * @param object $course
     * @param int $groupid id of selected group, 0 means all
     * @param stdClass $formdata The validated data from the grade export form.
     */
    public function __construct($course, $groupid, $formdata) {
        parent::__construct($course, $groupid, $formdata);
        $this->separator = $formdata->separator;

        // Overrides.
        $this->usercustomfields = true;
    }

    public function get_export_params() {
        $params = parent::get_export_params();
        $params['separator'] = $this->separator;
        return $params;
    }

    public function print_grades() {
        global $CFG;
        $config = get_config('local_integrate_autograding_system');
        $curl = new curl();

        $export_tracking = $this->track_exports();

        $strgrades = get_string('grades');
        $profilefields = grade_helper::get_user_profile_fields($this->course->id, $this->usercustomfields);

        $shortname = format_string($this->course->shortname, true, array('context' => context_course::instance($this->course->id)));
        $downloadfilename = clean_filename("$shortname $strgrades");
        $csvexport = new csv_export_writer($this->separator);
        $csvexport->set_filename($downloadfilename);

        // Print names of all the fields
        $exporttitle = array();
        foreach ($profilefields as $field) {
            $exporttitle[] = $field->fullname;
        }

        if (!$this->onlyactive) {
            $exporttitle[] = get_string("suspended");
        }

        // Autograder feedback columns
        $exporttitle[] = get_string('assignment', 'gradeexport_gradetablecsv');
        $exporttitle[] = get_string('total', 'gradeexport_gradetablecsv');
        $exporttitle[] = get_string('autograder', 'gradeexport_gradetablecsv');
        $exporttitle[] = get_string('grade', 'gradeexport_gradetablecsv');
        $exporttitle[] = get_string('feedback', 'gradeexport_gradetablecsv');

        // Last downloaded column header.
        $exporttitle[] = get_string('timeexported', 'gradeexport_gradetablecsv');
        $csvexport->add_data($exporttitle);

        // Print all the lines of data.
        $geub = new grade_export_update_buffer();
        $gui = new graded_users_iterator($this->course, $this->columns, $this->groupid);
        $gui->require_active_enrolment($this->onlyactive);
        $gui->allow_user_custom_fields($this->usercustomfields);
        $gui->init();
        while ($userdata = $gui->next_user()) {
            $exportdata = array();
            $user = $userdata->user;

            foreach ($profilefields as $field) {
                $fieldvalue = grade_helper::get_user_field_value($user, $field);
                $exportdata[] = $fieldvalue;
            }
            if (!$this->onlyactive) {
                $issuspended = ($user->suspendedenrolment) ? get_string('yes') : '';
                $exportdata[] = $issuspended;
            }

            $index = 0;
            $keys = array_keys($this->displaytype);
            foreach ($userdata->grades as $grade) {
                if ($export_tracking) {
                    $status = $geub->track($grade);
                }

                $exportdata[] = $grade->grade_item->itemname;                                   // assignment name
                $exportdata[] = $this->format_grade($grade, $this->displaytype[$keys[$index]]); // assignment grade

                $userid = $user->id;
                $courseid = $this->course->id;
                $assignmentid = $grade->grade_item->iteminstance;
                $url = get_string(
                    'urltemplate',
                    'local_integrate_autograding_system',
                    [
                        'url' => $config->bridge_service_url,
                        'endpoint' => "/submission/detail?userId=$userid&courseId=$courseid&assignmentId=$assignmentid"
                    ]
                );

                $curl->setHeader(array('Content-type: application/json'));
                $curl->setHeader(array('Accept: application/json', 'Expect:'));
                $response = json_decode($curl->get($url));

                $base = $exportdata;    // copy redundant info
                if ($response->success) {
                    foreach ($response->result as $autograder) {
                        foreach ($autograder->feedbacks as $feedback) {
                            $detail = $base;
                            $detail[] = $autograder->graderName;
                            $detail[] = $autograder->grade;
                            $detail[] = $feedback->feedback;

                            // Time exported.
                            $detail[] = time();
                            $csvexport->add_data($detail);
                        }
                    }
                } else {
                    $base[] = '-';  // empty autograder
                    $base[] = '-';  // empty grade
                    $base[] = '-';  // empty feedback

                    // Time exported.
                    $detail[] = time();
                    $csvexport->add_data($detail);
                }
                $index++;
            }
        }
        $gui->close();
        $geub->close();
        $csvexport->download_file();
        exit;
    }
}
