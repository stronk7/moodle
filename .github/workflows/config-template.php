<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = getenv('dbtype');
$CFG->dblibrary = 'native';
$CFG->dbhost    = '127.0.0.1';
$CFG->dbname    = 'test';
$CFG->dbuser    = 'test';
$CFG->dbpass    = 'test';
$CFG->prefix    = 'm_';
$CFG->dboptions = ['dbcollation' => 'utf8mb4_bin'];

$host = 'localhost';
$CFG->wwwroot   = "http://{$host}";
$port = getenv('MOODLE_DOCKER_WEB_PORT');
$CFG->dataroot  = realpath(dirname(__DIR__)) . '/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

// Debug options - possible to be controlled by flag in future..
$CFG->debug = (E_ALL | E_STRICT); // DEBUG_DEVELOPER
$CFG->debugdisplay = 1;
$CFG->debugstringids = 1; // Add strings=1 to url to get string ids.
$CFG->perfdebug = 15;
$CFG->debugpageinfo = 1;
$CFG->allowthemechangeonurl = 1;
$CFG->passwordpolicy = 0;
$CFG->cronclionly = 0;
$CFG->pathtophp = '/usr/local/bin/php';

$CFG->phpunit_dataroot  = realpath(dirname(__DIR__)) . '/phpunitdata';
$CFG->phpunit_prefix = 't_';

define('TEST_EXTERNAL_FILES_HTTP_URL', 'http://exttests:8080');
define('TEST_EXTERNAL_FILES_HTTPS_URL', 'http://exttests:8080');

define('TEST_SESSION_REDIS_HOST', 'redis');
define('TEST_CACHESTORE_REDIS_TESTSERVERS', 'redis');

// TODO: add others (solr, mongodb, memcached, ldap...)

// Too much for now: define('PHPUNIT_LONGTEST', true);

require_once(__DIR__ . '/lib/setup.php');
