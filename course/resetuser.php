<?php

require('../config.php');
require_once($CFG->dirroot . '/backup/util/interfaces/executable.class.php');

$courseid = required_param('id', PARAM_INT); // These are required.
$contextid = required_param('contextid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$ueid = required_param('ue', PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context::instance_by_id($contextid, MUST_EXIST);
$user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);
$ue = $DB->get_record('user_enrolments', array('id' => $ueid), '*', MUST_EXIST);

require_login($course);

$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->set_url('/course/resetuser.php', array(
    'id' => $courseid,
    'contextid' => $contextid,
    'userid' => $userid));
$PAGE->navigation->find('participants', navigation_node::TYPE_CONTAINER)->
    add(get_string('resetuser', 'course'))->
    make_active();

$PAGE->set_title("$course->shortname: ".get_string('resetuser', 'course'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resetuser', 'course'));

// Perform all the security checks before allowing the execution to happen. Caller's responsibility always.
$enrol = $DB->get_record('enrol', array('id' => $ue->enrolid), '*', MUST_EXIST);
$plugin = enrol_get_plugin($enrol->enrol);

if (!enrol_is_enabled($enrol->enrol)) {
    throw new moodle_exception('enrolmethoddisabled');
}

$canreset = $plugin->allow_unenrol_user($enrol, $ue) &&
    has_capability("enrol/{$enrol->enrol}:unenrol", $context) &&
    has_capability('moodle/course:resetuser', $context);

if (!$canreset) {
    throw new moodle_exception('cannotresetuser');
}

// Here we go.

// TODO: TB) Is there anything we want to be saved for historic purposes. Here it's the place where it should happen.
$ru = new \core_course\reset_user($courseid, $userid);

if ($ru->execute(true)) {
    // On success...
    echo '<pre>Yay, everything ended perfectly. User reset!! Time to check.</pre>';
} else {
    // On failure...
    echo '<pre>Some problem happened when reseting the user. Sad :-(</pre>';
}

echo $OUTPUT->footer();
