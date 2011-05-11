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
 * CLI continuous integration tests
 *
 * This script executes all the (simpletest) tests available in all the
 * Moodle code base, reporting results in different formats. Useful for
 * quick runs or integration into any ci tool able to process Junit
 * XML result files
 *
 * TODO: Some day, add support for clover files and code coverage (not for now)
 * TODO: Some day, decide to move to PHPUnit
 *
 * @package    core
 * @subpackage cli
 * @copyright  2011 Eloy Lafuente (http://stronk7.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions
require_once($CFG->libdir.'/cronlib.php');

require_once($CFG->libdir.'/adminlib.php');
require_once($CFG->libdir.'/simpletestcoveragelib.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/report/unittest/ex_simple_test.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/report/unittest/ex_reporter.php');

// now get cli options
list($options, $unrecognized) = cli_get_params(array(
                                                   'help'   => false,
                                                   'format' => 'txt',
                                                   'path'   => ''),
                                               array(
                                                   'h' => 'help',
                                                   'f' => 'format',
                                                   'p' => 'path'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
"Execute available simpletest unit tests, generating information in different formats

Options:
-h, --help            Print out this help
-f, --format          Select the output format (txt, junit), defaults to txt
-p, --path            Restrict tests to the ones available under path, defaults to all

Example:
\$sudo -u www-data /usr/bin/php admin/cli/citests.php
";

    echo $help;
    die;
}

$path = $options['path'];

// Always run the unit tests in developer debug mode.
$CFG->debug = DEBUG_DEVELOPER;
error_reporting($CFG->debug);
raise_memory_limit(MEMORY_EXTRA);

// Global for own storage / informing codebase
global $UNITTEST;
$UNITTEST = new stdClass();

// This limit is the time allowed per individual test function. Please do not
// increase this value. If you get a PHP time limit when running unit tests,
// find the unit test which is running slowly, and either make it faster,
// split it into multiple tests, or call set_time_limit within that test.
define('TIME_ALLOWED_PER_UNIT_TEST', 60);

// Create the group of tests.
$test = new autogroup_test_coverage(false, true, false, 'Moodle Unit Tests');

// OU specific. We use the _nonproject folder for stuff we want to
// keep in CVS, but which is not really relevant. It does no harm
// to leave this here.
$test->addIgnoreFolder($CFG->dirroot . '/_nonproject');

// Pick a format (simpletest reporter)
$reporter = new CITextReporter();
if ($options['format'] == 'html') {
    $reporter = new ExHtmlReporter(false);
} else if ($options['format'] == 'junit') {
    $reporter = new ExHtmlReporter(false);
}

// Work out what to test.
if (substr($path, 0, 1) == '/') {
    $path = substr($path, 1);
}
$path = $CFG->dirroot . '/' . $path;
if (substr($path, -1) == '/') {
    $path = substr($path, 0, -1);
}
if (is_file($path)) {
    $test->addTestFile($path);
} else if (is_dir($path)){
    $test->findTestFiles($path);
} else {
    echo "Incorrect path specified: $path";
    die;
}

// And run them
$test->run($reporter);

class CITextReporter extends TextReporter {

}
