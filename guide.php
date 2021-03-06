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
 * Exports an Excel spreadsheet of the component grades in a Marking Guide-graded assignment.
 *
 * @package    report_advancedgrades
 * @copyright  2014 Paul Nicholls
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/lib/excellib.class.php');
require_once($CFG->dirroot.'/report/advancedgrades/locallib.php');

$id          = required_param('id', PARAM_INT); /* Course ID */
$modid       = required_param('modid', PARAM_INT); /* CM ID */

$params['id'] = $id;
$params['modid'] = $id;

$PAGE->set_url('/report/advancedgrades/index.php', $params);

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);
require_login($course);

$modinfo = get_fast_modinfo($course->id);
$cm = $modinfo->get_cm($modid);
$modcontext = context_module::instance($cm->id);
require_capability('mod/assign:grade', $modcontext);

// Trigger event for logging.
$event = \report_advancedgrades\event\report_viewed::create(array(
    'context' => $modcontext,
    'other' => array(
        'gradingmethod' => 'guide'
    )
));
$event->add_record_snapshot('course_modules', $cm);
$event->trigger();

$filename = $course->shortname . ' - ' . $cm->name . '.xls';

$data = $DB->get_records_sql("SELECT    ggf.id AS ggfid, crs.shortname AS course, asg.name AS assignment, gd.name AS guide,
                                        ggc.shortname, ggf.score, ggf.remark, ggf.criterionid, rubm.username AS grader,
                                        stu.id AS userid, stu.idnumber AS idnumber, stu.firstname, stu.lastname,
                                        stu.username student, gin.timemodified AS modified
                                FROM {course} crs
                                JOIN {course_modules} cm ON crs.id = cm.course
                                JOIN {assign} asg ON asg.id = cm.instance
                                JOIN {context} c ON cm.id = c.instanceid
                                JOIN {grading_areas} ga ON c.id=ga.contextid
                                JOIN {grading_definitions} gd ON ga.id = gd.areaid
                                JOIN {gradingform_guide_criteria} ggc ON (ggc.definitionid = gd.id)
                                JOIN {grading_instances} gin ON gin.definitionid = gd.id
                                JOIN {assign_grades} ag ON ag.id = gin.itemid
                                JOIN {user} stu ON stu.id = ag.userid
                                JOIN {user} rubm ON rubm.id = gin.raterid
                                JOIN {gradingform_guide_fillings} ggf ON (ggf.instanceid = gin.id)
                                AND (ggf.criterionid = ggc.id)
                                WHERE cm.id = ? AND gin.status = 1
                                ORDER BY lastname ASC, firstname ASC, userid ASC, ggc.sortorder ASC,
                                ggc.shortname ASC", array($cm->id));

$students = report_advancedgrades_get_students($course->id);

$first = reset($data);
if ($first === false) {
    $url = $CFG->wwwroot.'/mod/assign/view.php?id='.$cm->id;
    redirect($url, get_string('noguidegrades', 'report_advancedgrades'), 5);
    exit;
}

$workbook = new MoodleExcelWorkbook("-");
$workbook->send($filename);
$sheet = $workbook->add_worksheet($cm->name);

$pos=report_advancedgrades_add_header($workbook, $sheet, $course->fullname, $cm->name, 'guide', $first->guide);
$datacol=$pos;
$format = $workbook->add_format(array('size' => 12, 'bold' => 1));
$format2 = $workbook->add_format(array('bold' => 1));
foreach ($data as $line) {
    if ($line->userid !== $first->userid) {
        break;
    }
    $sheet->write_string(4, $pos, $line->shortname, $format);
    $sheet->merge_cells(4, $pos, 4, $pos + 1, $format);
    $sheet->write_string(5, $pos, get_string('score','report_componentgrades'),$format2);
    $sheet->set_column($pos, $pos++, 6); // Set column width to 6.
    $sheet->write_string(5, $pos,get_string('feedback','report_componentgrades'),$format2);
    $sheet->set_column($pos, $pos++, 10); // Set column widths to 10.
}

$gradinginfopos = $pos;
report_advancedgrades_finish_colheaders($workbook, $sheet, $pos);

$students = report_advancedgrades_process_data($students, $data);
report_advancedgrades_add_data($sheet, $students, $gradinginfopos, 'guide',$datacol);

$workbook->close();

exit;
