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
 * Fix orphaned calendar events that were broken by MDL-67494.
 *
 * This script will look for all the calendar events which userids
 * where broken by a wrong upgrade step, affecting to Moodle 3.9.5
 * and up.
 *
 * It performs checks to both:
 *    a) Detect if the site was affected (ran the wrong upgrade step).
 *    b) Look for orphaned calendar events, categorising them as:
 *       - standard: site / category / course / group / user events
 *       - subscription: events created via subscriptions.
 *       - action: normal action events, created to show common important dates.
 *       - override: user and group override events, particular, that some activities support.
 *       - custom: other events, not being any of the above, common or particular.
 * By specifying it (--fix) try to recover as many broken events (missing userid) as
 * possible. Standard, subscription, action, override events in core are fully supported but
 * override or custom events should be fixed by each plugin as far as there isn't any standard
 * API (plugin-wise) to launch a rebuild of the calendar events.
 *
 * @package core
 * @copyright 2021 onwards Simey Lameze <simey@moodle.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . "/clilib.php");
require_once($CFG->dirroot . '/calendar/lib.php');
include_once($CFG->dirroot. '/course/lib.php');
include_once($CFG->dirroot. '/mod/assign/lib.php');
include_once($CFG->dirroot. '/mod/assign/locallib.php');
include_once($CFG->dirroot. '/mod/lesson/lib.php');
include_once($CFG->dirroot. '/mod/lesson/locallib.php');
include_once($CFG->dirroot. '/mod/quiz/lib.php');
include_once($CFG->dirroot. '/mod/quiz/locallib.php');

// Supported options.
$long = ['fix'  => false, 'help' => false];
$short = ['f' => 'fix', 'h' => 'help'];

// CLI options.
[$options, $unrecognized] = cli_get_params($long, $short);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOT
Fix orphaned calendar events.

  This script detects calendar events that have had their
  userid lost. By default it will perform various checks
  and report them, showing the site status in an easy way.

  Also, optionally (--fix), it wil try to recover as many
  lost userids as possible from different sources. Note that
  this script aims to process well-know events in core,
  leaving custom events in 3rd part plugins mostly unmodified
  because there isn't any consistent way to regenerate them.

  For more details:  https://tracker.moodle.org/browse/MDL-71156

Options:
  -h, --help    Print out this help.
  -f, --fix     Fix the orphaned calendar events in the DB.
                If not specified only check and report problems to STDERR.

Usage:
  - Only report:    \$ sudo -u www-data /usr/bin/php admin/cli/fix_orphaned_calendar_events.php
  - Report and fix: \$ sudo -u www-data /usr/bin/php admin/cli/fix_orphaned_calendar_events.php -f
EOT;

    cli_writeln($help);
    die;
}

// Check various usual pre-requisites.
if (empty($CFG->version)) {
    cli_error('Database is not yet installed.');
}

$admin = get_admin();
if (!$admin) {
    cli_error('Error: No admin account was found.');
}

if (moodle_needs_upgrading()) {
    cli_error('Moodle upgrade pending, script execution suspended.');
}

// Do everything as admin by default.
\core\session\manager::set_user($admin);

// Report current site status.
cli_heading('Checking the site status');
$needsfix = upgrade_calendar_site_status();

// Report current calendar events status.
cli_heading('Checking the calendar events status');
$info = upgrade_calendar_events_status();
$hasbadevents = $info['total']->bad > 0 || $info['total']->bad != $info['other']->bad;
$needsfix = $needsfix || $hasbadevents;

// If, selected, fix as many calendar events as possible.
if ($options['fix']) {

    // If the report has told us that the fix was not needed... ask for confirmation!
    if (!$needsfix) {
        cli_writeln("This site DOES NOT NEED to run the calendar events fix.");
        $input = cli_input('Are you completely sure that you want to run the fix? (y/N)', 'N', ['y', 'Y', 'n', 'N']);
        if (strtolower($input) != 'y') {
            exit(0);
        }
        cli_writeln("");
    }
    cli_heading('Fixing as many as possible calendar events');
    upgrade_calendar_events_fix($info);
    // Report current (after fix) calendar events status.
    cli_heading('Checking the calendar events status (after fix)');
    upgrade_calendar_events_status();
} else if ($needsfix) {
    // Fix option was not provided but problem events have been found. Notify the user and provide info how to fix these events.
    cli_writeln("This site NEEDS to run the calendar events fix!");
    cli_writeln("To fix the calendar events, re-run this script with the --fix option.");
}

/**
 * Detects if the site may need to get the calendar events fixed or no. With optional output.
 *
 * @param bool $output true if the function must output information, false if not.
 * @return bool true if the site needs to run the fixes, false if not.
 */
function upgrade_calendar_site_status(bool $output = true): bool {
    global $DB;

    // List of upgrade steps where the bug happened.
    $badsteps = [
        '3.9.5'   => '2020061504.08',
        '3.10.2'  => '2020110901.09',
        '3.11dev' => '2021022600.02',
        '4.0dev'  => '2021052500.65',
    ];

    // List of upgrade steps that ran the fixer.
    $fixsteps = [
        '3.9.6+'  => '2020061506.05',
        '3.10.3+' => '2020110903.05',
        '3.11dev' => '2021042100.02',
        '4.0dev'  => '2021052500.85',
    ];

    $targetsteps = array_merge(array_values($badsteps), array_values( $fixsteps));
    list($insql, $inparams) = $DB->get_in_or_equal($targetsteps);
    $foundsteps = $DB->get_fieldset_sql("
        SELECT DISTINCT version
          FROM {upgrade_log}
         WHERE plugin = 'core'
           AND version " . $insql . "
      ORDER BY version", $inparams);

    // Analyse the found steps, to decide if the site needs upgrading or no.
    $badfound = false;
    $fixfound = false;
    foreach ($foundsteps as $foundstep) {
        $badfound = $badfound ?: array_search($foundstep, $badsteps, true);
        $fixfound = $fixfound ?: array_search($foundstep, $fixsteps, true);
    }
    $needsfix = $badfound && !$fixfound;

    // Let's output some textual information if required to.
    if ($output) {
        mtrace("");
        if ($badfound) {
            mtrace("This site has executed the problematic upgrade step {$badsteps[$badfound]} present in {$badfound}.");
        } else {
            mtrace("Problematic upgrade steps were NOT found, site should be safe.");
        }
        if ($fixfound) {
            mtrace("This site has executed the fix upgrade step {$fixsteps[$fixfound]} present in {$fixfound}.");
        } else {
            mtrace("Fix upgrade steps were NOT found.");
        }
        mtrace("");
        if ($needsfix) {
            mtrace("This site NEEDS to run the calendar events fix!");
            mtrace('');
            mtrace("You can use this CLI tool or upgrade to a version of Moodle that includes");
            mtrace("the fix and will be executed as part of the normal upgrade procedure.");
            mtrace("The following versions or up are known candidates to upgrade to:");
            foreach ($fixsteps as $key => $value) {
                mtrace("  - {$key}: {$value}");
            }
            mtrace("");
        }
    }
    return $needsfix;
}

/**
 * Detects the calendar events needing to be fixed. With optional output.
 *
 * @param bool $output true if the function must output information, false if not.
 * @return stdClass[] an array of event types (as keys) with total and bad counters, plus sql to retrieve them.
 */
function upgrade_calendar_events_status(bool $output = true): array {
    global $DB;

    // Calculate the list of standard (core) activity plugins.
    $plugins = core_plugin_manager::standard_plugins_list('mod');
    $coremodules = "modulename IN ('" . implode("', '", $plugins) . "')";

    // Some query parts go here.
    $brokenevents = "(userid = 0 AND (eventtype <> 'user' OR priority <> 0))"; // From the original bad upgrade step.
    $standardevents = "(eventtype IN ('site', 'category', 'course', 'group', 'user') AND subscriptionid IS NULL)";
    $subscriptionevents = "(subscriptionid IS NOT NULL)";
    $overrideevents = "({$coremodules} AND priority IS NOT NULL)";
    $actionevents = "({$coremodules} AND instance > 0 and priority IS NULL)";
    $otherevents = "(NOT ({$standardevents} OR {$subscriptionevents} OR {$overrideevents} OR {$actionevents}))";

    // Detailed query template.
    $detailstemplate = "
        SELECT ##group## AS groupname, COUNT(1) AS count
          FROM {event}
         WHERE ##groupconditions##
      GROUP BY ##group##";

    // Count total and potentially broken events.
    $total = $DB->count_records_select('event', '');
    $totalbadsql = $brokenevents;
    $totalbad = $DB->count_records_select('event', $totalbadsql);

    // Standard events.
    $standard = $DB->count_records_select('event', $standardevents);
    $standardbadsql = "{$brokenevents} AND {$standardevents}";
    $standardbad = $DB->count_records_select('event', $standardbadsql);
    $standarddetails = $DB->get_records_sql(
        str_replace(
            ['##group##', '##groupconditions##'],
            ['eventtype', $standardbadsql],
            $detailstemplate
        )
    );
    array_walk($standarddetails, function (&$rec) {
        $rec = $rec->groupname . ': ' . $rec->count;
    });
    $standarddetails = $standarddetails ? '(' . implode(', ', $standarddetails) . ')' : '- all good!';

    // Subscription events.
    $subscription = $DB->count_records_select('event', $subscriptionevents);
    $subscriptionbadsql = "{$brokenevents} AND {$subscriptionevents}";
    $subscriptionbad = $DB->count_records_select('event', $subscriptionbadsql);
    $subscriptiondetails = $DB->get_records_sql(
        str_replace(
            ['##group##', '##groupconditions##'],
            ['eventtype', $subscriptionbadsql],
            $detailstemplate
        )
    );
    array_walk($subscriptiondetails, function (&$rec) {
        $rec = $rec->groupname . ': ' . $rec->count;
    });
    $subscriptiondetails = $subscriptiondetails ? '(' . implode(', ', $subscriptiondetails) . ')' : '- all good!';

    // Override events.
    $override = $DB->count_records_select('event', $overrideevents);
    $overridebadsql = "{$brokenevents} AND {$overrideevents}";
    $overridebad = $DB->count_records_select('event', $overridebadsql);
    $overridedetails = $DB->get_records_sql(
        str_replace(
            ['##group##', '##groupconditions##'],
            ['modulename', $overridebadsql],
            $detailstemplate
        )
    );
    array_walk($overridedetails, function (&$rec) {
        $rec = $rec->groupname . ': ' . $rec->count;
    });
    $overridedetails = $overridedetails ? '(' . implode(', ', $overridedetails) . ')' : '- all good!';

    // Action events.
    $action = $DB->count_records_select('event', $actionevents);
    $actionbadsql = "{$brokenevents} AND {$actionevents}";
    $actionbad = $DB->count_records_select('event', $actionbadsql);
    $actiondetails = $DB->get_records_sql(
        str_replace(
            ['##group##', '##groupconditions##'],
            ['modulename', $actionbadsql],
            $detailstemplate
        )
    );
    array_walk($actiondetails, function (&$rec) {
        $rec = $rec->groupname . ': ' . $rec->count;
    });
    $actiondetails = $actiondetails ? '(' . implode(', ', $actiondetails) . ')' : '- all good!';

    // Other events.
    $other = $DB->count_records_select('event', $otherevents);
    $otherbadsql = "{$brokenevents} AND {$otherevents}";
    $otherbad = $DB->count_records_select('event', $otherbadsql);
    $otherdetails = $DB->get_records_sql(
        str_replace(
            ['##group##', '##groupconditions##'],
            ['COALESCE(component, modulename)', $otherbadsql],
            $detailstemplate
        )
    );
    array_walk($otherdetails, function (&$rec) {
        $rec = ($rec->groupname ?: 'unknown') . ': ' . $rec->count;
    });
    $otherdetails = $otherdetails ? '(' . implode(', ', $otherdetails) . ')' : '- all good!';

    // Let's output some textual information if required to.
    if ($output) {
        mtrace("");
        mtrace("Totals: {$total} / {$totalbad} (total / wrong)");
        mtrace("  - standards events: {$standard} / {$standardbad} {$standarddetails}");
        mtrace("  - subscription events: {$subscription} / {$subscriptionbad} {$subscriptiondetails}");
        mtrace("  - override events: {$override} / {$overridebad} {$overridedetails}");
        mtrace("  - action events: {$action} / {$actionbad} {$actiondetails}");
        mtrace("  - other events: {$other} / {$otherbad} {$otherdetails}");
        mtrace("");
    }

    return [
        'total' => (object)['count' => $total, 'bad' => $totalbad, 'sql' => $totalbadsql],
        'standard' => (object)['count' => $standard, 'bad' => $standardbad, 'sql' => $standardbadsql],
        'subscription' => (object)['count' => $subscription, 'bad' => $subscriptionbad, 'sql' => $subscriptionbadsql],
        'override' => (object)['count' => $override, 'bad' => $overridebad, 'sql' => $overridebadsql],
        'action' => (object)['count' => $action, 'bad' => $actionbad, 'sql' => $actionbadsql],
        'other' => (object)['count' => $other, 'bad' => $otherbad, 'sql' => $otherbadsql],
    ];
}

/**
 * Detects the calendar events needing to be fixed. With optional output.
 *
 * @param stdClass[] an array of event types (as keys) with total and bad counters, plus sql to retrieve them.
 * @param bool $output true if the function must output information, false if not.
 * @param int $maxseconds Number of seconds the function will run as max, with zero meaning no limit.
 * @return bool true if the function has not finished fixing everything, false if it has finished.
 */
function upgrade_calendar_events_fix(array $info, bool $output = true, int $maxseconds = 0): bool {
    global $DB;

    upgrade_calendar_events_mtrace('', $output);

    // Initial preparations.
    $starttime = time();
    $endtime = $maxseconds ? ($starttime + $maxseconds) : 0;

    // No bad events, or all bad events are "other" events, finished.
    if ($info['total']->bad == 0 || $info['total']->bad == $info['other']->bad) {
        return false;
    }

    // Let's fix overriden events first (they are the ones performing worse with the missing userid).
    if ($info['override']->bad != 0) {
        if (upgrade_calendar_override_events_fix($info['override'], $output, $endtime)) {
            return true; // Not finished yet.
        }
    }

    // Let's fix the subscription events (like standard ones, but with the event_subscriptions table).
    if ($info['subscription']->bad != 0) {
        if (upgrade_calendar_subscription_events_fix($info['subscription'], $output, $endtime)) {
            return true; // Not finished yet.
        }
    }

    // Let's fix the standard events (site, category, course, group).
    if ($info['standard']->bad != 0) {
        if (upgrade_calendar_standard_events_fix($info['standard'], $output, $endtime)) {
            return true; // Not finished yet.
        }
    }

    // Let's fix the action events (all them are "general" ones, not user-specific in core).
    if ($info['action']->bad != 0) {
        if (upgrade_calendar_action_events_fix($info['action'], $output, $endtime)) {
            return true; // Not finished yet.
        }
    }

    // Have arrived here, finished!
    return false;
}

/**
 * Wrapper over mtrace() to allow a few more things to be specified.
 *
 * @param string $string string to output.
 * @param bool $output true to perform the output, false to avoid it.
 */
function upgrade_calendar_events_mtrace(string $string, bool $output): void {
    static $cols = 0;

    // No output, do nothing.
    if (!$output) {
        return;
    }

    // Printing dots... let's output them slightly nicer.
    if ($string === '.') {
        $cols++;
        // Up to 60 cols.
        if ($cols < 60) {
            mtrace($string, '');
        } else {
            mtrace($string);
            $cols = 0;
        }
        return;
    }

    // Reset cols, have ended printing dots.
    if ($cols) {
        $cols = 0;
        mtrace('');
    }

    // Normal output.
    mtrace($string);
}

/**
 * Get a valid editing teacher for a given courseid
 *
 * @param int $courseid The course to look for editing teachers.
 * @return int A user id of an editing teacher or, if missing, the admin userid.
 */
function upgrade_calendar_events_get_teacherid(int $courseid): int {

    if ($context = context_course::instance($courseid, IGNORE_MISSING)) {
        if ($havemanage = get_users_by_capability($context, 'moodle/course:manageactivities', 'u.id')) {
            return array_keys($havemanage)[0];
        }
    }
    return get_admin()->id; // Could not find a teacher, default to admin.
}

/**
 * Detects the calendar standard events needing to be fixed. With optional output.
 *
 * @param stdClass $info an object with total and bad counters, plus sql to retrieve them.
 * @param bool $output true if the function must output information, false if not.
 * @param int $endtime cutoff time when the process must stop (0 means no cutoff).
 * @return bool true if the function has not finished fixing everything, false if it has finished.
 */
function upgrade_calendar_standard_events_fix(stdClass $info, bool $output = true, int $endtime = 0): bool {
    global $DB;

    $return = false; // Let's assume the function is going to finish by default.
    $status = "Finished!"; // To decide the message to be presented on return.

    upgrade_calendar_events_mtrace('Processing standard events', $output);

    $rs = $DB->get_recordset_sql("
        SELECT DISTINCT eventtype, courseid
          FROM {event}
         WHERE {$info->sql}");

    foreach ($rs as $record) {
        switch ($record->eventtype) {
            case 'site':
            case 'category':
                // These are created by admin.
                $DB->set_field('event', 'userid', get_admin()->id, ['eventtype' => $record->eventtype]);
                break;
            case 'course':
            case 'group':
                // These are created by course teacher.
                $DB->set_field('event', 'userid', upgrade_calendar_events_get_teacherid($record->courseid),
                    ['eventtype' => $record->eventtype, 'courseid' => $record->courseid]);
                break;
        }

        // Cutoff time, let's exit.
        if ($endtime && $endtime <= time()) {
            $status = 'Remaining standard events pending';
            $return = true; // Not finished yet.
            break;
        }
        upgrade_calendar_events_mtrace('.', $output);
    }
    $rs->close();
    upgrade_calendar_events_mtrace($status, $output);
    upgrade_calendar_events_mtrace('', $output);
    return $return;
}

/**
 * Detects the calendar subscription events needing to be fixed. With optional output.
 *
 * @param stdClass $info an object with total and bad counters, plus sql to retrieve them.
 * @param bool $output true if the function must output information, false if not.
 * @param int $endtime cutoff time when the process must stop (0 means no cutoff).
 * @return bool true if the function has not finished fixing everything, false if it has finished.
 */
function upgrade_calendar_subscription_events_fix(stdClass $info, bool $output = true, int $endtime = 0): bool {
    global $DB;

    $return = false; // Let's assume the function is going to finish by default.
    $status = "Finished!"; // To decide the message to be presented on return.

    upgrade_calendar_events_mtrace('Processing subscription events', $output);

    $rs = $DB->get_recordset_sql("
        SELECT DISTINCT subscriptionid AS id
          FROM {event}
         WHERE {$info->sql}");

    foreach ($rs as $subscription) {
        // Subscriptions can be site or category level, let's put the admin as userid.
        // (note that "user" subscription weren't deleted so there is nothing to recover with them.
        $DB->set_field('event_subscriptions', 'userid', get_admin()->id, ['id' => $subscription->id]);
        $DB->set_field('event', 'userid', get_admin()->id, ['subscriptionid' => $subscription->id]);

        // Cutoff time, let's exit.
        if ($endtime && $endtime <= time()) {
            $status = 'Remaining subscription events pending';
            $return = true; // Not finished yet.
            break;
        }
        upgrade_calendar_events_mtrace('.', $output);
    }
    $rs->close();
    upgrade_calendar_events_mtrace($status, $output);
    upgrade_calendar_events_mtrace('', $output);
    return $return;
}

/**
 * Detects the calendar action events needing to be fixed. With optional output.
 *
 * @param stdClass $info an object with total and bad counters, plus sql to retrieve them.
 * @param bool $output true if the function must output information, false if not.
 * @param int $endtime cutoff time when the process must stop (0 means no cutoff).
 * @return bool true if the function has not finished fixing everything, false if it has finished.
 */
function upgrade_calendar_action_events_fix(stdClass $info, bool $output = true, int $endtime = 0): bool {
    global $DB;

    $return = false; // Let's assume the function is going to finish by default.
    $status = "Finished!"; // To decide the message to be presented on return.

    upgrade_calendar_events_mtrace('Processing action events', $output);

    $rs = $DB->get_recordset_sql("
        SELECT DISTINCT modulename, instance, courseid
          FROM {event}
         WHERE {$info->sql}");

    foreach ($rs as $record) {
        // These are created by course teacher.
        $DB->set_field('event', 'userid', upgrade_calendar_events_get_teacherid($record->courseid),
            ['modulename' => $record->modulename, 'instance' => $record->instance, 'courseid' => $record->courseid]);

        // Cutoff time, let's exit.
        if ($endtime && $endtime <= time()) {
            $status = 'Remaining action events pending';
            $return = true; // Not finished yet.
            break;
        }
        upgrade_calendar_events_mtrace('.', $output);
    }
    $rs->close();
    upgrade_calendar_events_mtrace($status, $output);
    upgrade_calendar_events_mtrace('', $output);
    return $return;
}

/**
 * Detects the calendar override events needing to be fixed. With optional output.
 *
 * @param stdClass $info an object with total and bad counters, plus sql to retrieve them.
 * @param bool $output true if the function must output information, false if not.
 * @param int $endtime cutoff time when the process must stop (0 means no cutoff).
 * @return bool true if the function has not finished fixing everything, false if it has finished.
 */
function upgrade_calendar_override_events_fix(stdClass $info, bool $output = true, int $endtime = 0): bool {
    global $DB;

    $return = false; // Let's assume the function is going to finish by default.
    $status = "Finished!"; // To decide the message to be presented on return.

    upgrade_calendar_events_mtrace('Processing override events', $output);

    $rs = $DB->get_recordset_sql("
        SELECT DISTINCT modulename, instance
          FROM {event}
         WHERE {$info->sql}");

    foreach ($rs as $module) {
        // Remove all the records from the events table for the module.
        $DB->delete_records('event', ['modulename' => $module->modulename, 'instance' => $module->instance]);

        // Get the activity record.
        if (!$activityrecord = $DB->get_record($module->modulename, ['id' => $module->instance])) {
            // Orphaned calendar event (activity doesn't exists), skip.
            continue;
        }

        // Let's rebuild it by calling to each module API.
        switch ($module->modulename) {
            case 'assign';
                if (function_exists('assign_prepare_update_events')) {
                    assign_prepare_update_events($activityrecord);
                }
                break;
            case 'lesson':
                if (function_exists('lesson_update_events')) {
                    lesson_update_events($activityrecord);
                }
                break;
            case 'quiz':
                if (function_exists('quiz_update_events')) {
                    quiz_update_events($activityrecord);
                }
                break;
        }

        // Sometimes, some (group) overrides are created without userid, when that happens, they deserve
        // some user (teacher or admin). This doesn't affect to groups calendar events behaviour,
        // but allows counters to detect already processed group overrides and makes things
        // consistent.
        $DB->set_field_select('event', 'userid', upgrade_calendar_events_get_teacherid($activityrecord->course),
            'modulename = ? AND instance = ? and priority != 0 and userid = 0',
            ['modulename' => $module->modulename, 'instance' => $module->instance]);

        // Cutoff time, let's exit.
        if ($endtime && $endtime <= time()) {
            $status = 'Remaining override events pending';
            $return = true; // Not finished yet.
            break;
        }
        upgrade_calendar_events_mtrace('.', $output);
    }
    $rs->close();
    upgrade_calendar_events_mtrace($status, $output);
    upgrade_calendar_events_mtrace('', $output);
    return $return;
}
