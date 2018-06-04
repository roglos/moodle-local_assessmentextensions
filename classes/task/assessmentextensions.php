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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_assessmentextensions - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assessmentextensions\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessmentextensions extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentextensions');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;
        $submissiontime = date('H:i:s', strtotime('6pm'));

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$this->get_config('dbtype')) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $this->get_config('dbtype') . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$this->get_config('remotetable')) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $this->get_config('remotetable') . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$this->get_config('remotegradestable')) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $this->get_config('remotetable') . '<br>';
        }
        echo 'Starting connection...<br>';
        // Report connection error if occurs.
        if (!$extdb = $this->db_init()) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Get external table for grades and extensions - individual users->assessments.
        $tablegrades = $this->get_config('remotegradestable');
        $extensions = array();
        // Read grades and extensions data from external table.
        /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     student_code                                     *
         *     assessment_idcode                                *
         *     student_ext_duedate                               *
         *     student_ext_duetime                              *
         *     student_fbdue_date                               *
         *     student_fbdue_time                               *
         ********************************************************/
        $sql = $this->db_get_sql($tablegrades, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $this->db_decode($fields);
                    $extensions[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }
        // Create reference array of students - if has a linked assessement AND an extension date/time.
        $student = array();
        foreach ($extensions as $e) {
            $key = $e['student_code'].$e['assessment_idcode'];
            if ($e['assessment_idcode'] && ($e['student_ext_duedate'] || $e['student_ext_duetime'])) {
                $student[$key]['stucode'] = $e['student_code'];
                $student[$key]['lc'] = $e['assessment_idcode'];
                $student[$key]['extdate'] = $e['student_ext_duedate'];
                $student[$key]['exttime'] = $e['student_ext_duetime'];
                $student[$key]['fbdate'] = $e['student_fbdue_date'];
                $student[$key]['fbtime'] = $e['student_fbdue_time'];
            }
        }

        // Set extensions.
        // Echo statements output to cron or when task run immediately for debugging.
        foreach ($student as $k => $v) {
            if (!empty($student[$k]['extdate']) || !empty($student[$k]['exttime'])) {
                // Create array for writing values.
                $userflags = new stdClass();
                // Set user.
                $username = 's'.$student[$k]['stucode'];
                echo '<br><p>username '.$username.' </p>';
                // Set username (student number).
                $userflags->userid = $DB->get_field('user', 'id', array('username' => $username));
                echo '<p>userflag->userid'.$userflags->userid.' #:</p>';
                // Set assignment id.
                $userflags->assignment = $DB->get_field('course_modules', 'instance', array('idnumber' => $student[$k]['lc']));
                // Set extension date.
                $extdate = $student[$k]['extdate'];
                $exttime = $submissiontime;
                // Convert extension date and time to Unix time stamp.
                $exttimestamp = strtotime($extdate.' '.$exttime);
                $userflags->extensionduedate = $exttimestamp;
                // Error trap to make sure assignment and user are set - or ignore.
                if (!empty($userflags->assignment) && !empty($userflags->userid)) {
                    // Check if record exists already.
                    if ($DB->record_exists('assign_user_flags',
                        array('userid' => $userflags->userid, 'assignment' => $userflags->assignment))) {
                        // Set id as unique key.
                        $userflags->id = $DB->get_field('assign_user_flags', 'id',
                            array('userid' => $userflags->userid, 'assignment' => $userflags->assignment));
                        // Check existing extension date if set.
                        $extdue = $DB->get_field('assign_user_flags', 'extensionduedate',
                            array('userid' => $userflags->userid, 'assignment' => $userflags->assignment));
                        // If the extension date is different then update the one on Moodle to be the same as the SITS date.
                        if ($extdue != $userflags->extensionduedate) {
                            $DB->update_record('assign_user_flags', $userflags, false);
                            echo $username.' updated<br>';
                        }
                    } else { // If no record exists.
                        // Set other default values - 0 if new record.
                        $userflags->locked = 0;
                        $userflags->mailed = 0;
                        $userflags->workflowstate = 0;
                        $userflags->allocatedmarker = 0;
                        // Create new record with extensions.
                        $DB->insert_record('assign_user_flags', $userflags, false);
                        echo $username.' created<br';
                    }
                }
            }
        }

        // Reset change flags.
        $sql = "UPDATE " . $tablegrades . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
        $extdb->Execute($sql);
        $sql = "UPDATE " . $tableassm . " SET assessment_changebydw = 0 WHERE assessment_changebydw = 1;";
        $extdb->Execute($sql);

        // Free memory.
        $extdb->Close();
    }

    /* Db functions cloned from enrol/db plugin.
     * ========================================= */

    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    public function db_init() {
        global $CFG;

        require_once($CFG->libdir.'/adodb/adodb.inc.php');

        // Connect to the external database (forcing new connection).
        $extdb = ADONewConnection($this->get_config('dbtype'));
        if ($this->get_config('debugdb')) {
            $extdb->debug = true;
            ob_start(); // Start output buffer to allow later use of the page headers.
        }

        // The dbtype my contain the new connection URL, so make sure we are not connected yet.
        if (!$extdb->IsConnected()) {
            $result = $extdb->Connect($this->get_config('dbhost'),
                $this->get_config('dbuser'),
                $this->get_config('dbpass'),
                $this->get_config('dbname'), true);
            if (!$result) {
                return null;
            }
        }

        $extdb->SetFetchMode(ADODB_FETCH_ASSOC);
        if ($this->get_config('dbsetupsql')) {
            $extdb->Execute($this->get_config('dbsetupsql'));
        }
        return $extdb;
    }

    public function db_addslashes($text) {
        // Use custom made function for now - it is better to not rely on adodb or php defaults.
        if ($this->get_config('dbsybasequoting')) {
            $text = str_replace('\\', '\\\\', $text);
            $text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
        } else {
            $text = str_replace("'", "''", $text);
        }
        return $text;
    }

    public function db_encode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_encode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, 'utf-8', $dbenc);
        }
    }

    public function db_decode($text) {
        $dbenc = $this->get_config('dbencoding');
        if (empty($dbenc) or $dbenc == 'utf-8') {
            return $text;
        }
        if (is_array($text)) {
            foreach ($text as $k => $value) {
                $text[$k] = $this->db_decode($value);
            }
            return $text;
        } else {
            return core_text::convert($text, $dbenc, 'utf-8');
        }
    }

    public function db_get_sql($table, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key = '$value'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql = "SELECT $distinct $fields
                  FROM $table
                 $where
                  $sort";
        return $sql;
    }

    public function db_get_sql_like($table2, array $conditions, array $fields, $distinct = false, $sort = "") {
        $fields = $fields ? implode(',', $fields) : "*";
        $where = array();
        if ($conditions) {
            foreach ($conditions as $key => $value) {
                $value = $this->db_encode($this->db_addslashes($value));

                $where[] = "$key LIKE '%$value%'";
            }
        }
        $where = $where ? "WHERE ".implode(" AND ", $where) : "";
        $sort = $sort ? "ORDER BY $sort" : "";
        $distinct = $distinct ? "DISTINCT" : "";
        $sql2 = "SELECT $distinct $fields
                  FROM $table2
                 $where
                  $sort";
        return $sql2;
    }


    /**
     * Returns plugin config value
     * @param  string $name
     * @param  string $default value if config does not exist yet
     * @return string value or default
     */
    public function get_config($name, $default = null) {
        $this->load_config();
        return isset($this->config->$name) ? $this->config->$name : $default;
    }

    /**
     * Sets plugin config value
     * @param  string $name name of config
     * @param  string $value string config value, null means delete
     * @return string value
     */
    public function set_config($name, $value) {
        $settingspluginname = 'assessmentsettings';
        $this->load_config();
        if ($value === null) {
            unset($this->config->$name);
        } else {
            $this->config->$name = $value;
        }
        set_config($name, $value, "local_$settingspluginname");
    }

    /**
     * Makes sure config is loaded and cached.
     * @return void
     */
    public function load_config() {
        if (!isset($this->config)) {
            $settingspluginname = 'assessmentsettings';
            $this->config = get_config("local_$settingspluginname");
        }
    }
}
