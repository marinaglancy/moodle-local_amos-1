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
 * Moodle language manipulation library
 *
 * Provides classes and functions to handle various low-level operations on Moodle
 * strings in various formats.
 *
 * @package   local_amos
 * @copyright 2010 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Provides iteration features for mlang classes
 *
 * This class just forwards all iteration related calls to the aggregated iterator
 */
class mlang_iterator implements iterator {

    /** @var iterator instance */
    protected $iterator;

    public function __construct(array $items) {
        $this->iterator = new ArrayIterator($items);
    }

    public function current(){
        return $this->iterator->current();
    }

    public function key(){
        return $this->iterator->key();
    }

    public function next(){
        return $this->iterator->next();
    }

    public function rewind(){
        return $this->iterator->rewind();
    }

    public function valid(){
        return $this->iterator->valid();
    }
}

/**
 * Represents a collection of strings for a given component
 */
class mlang_component {

    /** @var the name of the component, what {@link string_manager::get_string()} uses as the second param */
    public $name;

    /** @var string language code we are part of */
    public $lang;

    /** @var mlang_version we are part of */
    public $version;

    /** @var holds instances of mlang_string in an associative array */
    protected $strings = array();

    /**
     * @param string $name the name of the component, eg. 'role', 'glossary', 'datafield_text' etc.
     * @param string $lang
     * @param mlang_version $version
     */
    public function __construct($name, $lang, mlang_version $version) {
        $this->name     = $name;
        $this->lang     = $lang;
        $this->version  = $version;
    }

    /**
     * Given a path to a file, returns the name of the component
     *
     * @param mixed $filepath
     * @return string
     */
    public static function name_from_filename($filepath) {
        $pathparts = pathinfo($filepath);
        return $pathparts['filename'];
    }

    /**
     * Factory method returning an instance with strings loaded from a file in Moodle PHP format
     *
     * @param string $filepath full path to the file to load
     * @param string $lang lang pack we are part of
     * @param mlang_version $version the branch to put this string on
     * @param string $name use this as a component name instead of guessing from the file name
     * @param int $timemodified use this as a timestamp of string modification instead of the filemtime()
     * @throw Exception
     * @return mlang_component
     */
    public static function from_phpfile($filepath, $lang, mlang_version $version, $timemodified=null, $name=null) {
        if (empty($name)) {
            $name = self::name_from_filename($filepath);
        }
        $component = new mlang_component($name, $lang, $version);
        unset($string);
        if (is_readable($filepath)) {
            if (empty($timemodified)) {
                $timemodified = filemtime($filepath);
            }
            include($filepath);
        } else {
            throw new Exception('Strings definition file ' . $filepath . ' not readable');
        }
        if (!empty($string) && is_array($string)) {
            foreach ($string as $id => $value) {
                if ($version->code <= mlang_version::MOODLE_19) {
                    $value = mlang_string::fix_syntax($value, 1);
                } else {
                    // TODO XXX change this once we start tracking new format
                    $value = mlang_string::fix_syntax($value, 2, 1);
                }
                $component->add_string(new mlang_string($id, $value, $timemodified), true);
            }
        } else {
            debugging('No strings defined in ' . $filepath, DEBUG_DEVELOPER);
        }
        return $component;
    }

    /**
     * Get a snapshot of all strings in the given component
     *
     * @param string $name
     * @param string $lang
     * @param mlang_version $version
     * @param int $timestamp time of the snapshot, empty for the most recent
     * @param bool $deleted shall deleted strings be included?
     * @param bool $fullinfo shall full information about the string (commit messages, source etc) be returned?
     * @return mlang_component component with the strings from the snapshot
     */
    public static function from_snapshot($name, $lang, mlang_version $version, $timestamp=null, $deleted=false,
                                         $fullinfo=false, array $stringids=null) {
        global $DB;

        $params = array(
            'inner_branch' => $version->code, 'inner_lang' => $lang, 'inner_component' => $name,
            'outer_branch' => $version->code, 'outer_lang' => $lang, 'outer_component' => $name,
            );
        if (!empty($stringids)) {
            list($inner_strsql, $inner_strparams) = $DB->get_in_or_equal($stringids, SQL_PARAMS_NAMED, 'innerstringid000000');
            list($outer_strsql, $outer_strparams) = $DB->get_in_or_equal($stringids, SQL_PARAMS_NAMED, 'outerstringid000000');
            $params = array_merge($params, $inner_strparams, $outer_strparams);
        }
        if ($fullinfo) {
            $sql = "SELECT r.*";
        } else {
            $sql = "SELECT r.stringid, r.text, r.timemodified, r.deleted";
        }
        $sql .= " FROM {amos_repository} r
                  JOIN (SELECT branch, lang, component, stringid, MAX(timemodified) AS timemodified
                          FROM {amos_repository}
                         WHERE branch=:inner_branch
                           AND lang=:inner_lang
                           AND component=:inner_component";
        if (!empty($stringids)) {
            $sql .= "      AND stringid $inner_strsql";
        }
        if (!empty($timestamp)) {
            $sql .= "      AND timemodified <= :timemodified";
            $params = array_merge($params, array('timemodified' => $timestamp));
        }
        $sql .="         GROUP BY branch,lang,component,stringid) j
                    ON (r.branch = j.branch
                       AND r.lang = j.lang
                       AND r.component = j.component
                       AND r.stringid = j.stringid
                       AND r.timemodified = j.timemodified)
                 WHERE r.branch=:outer_branch
                       AND r.lang=:outer_lang
                       AND r.component=:outer_component";
        if (empty($deleted)) {
            $sql .= "  AND r.deleted = 0";
        }
        if (!empty($stringids)) {
            $sql .= "  AND r.stringid $outer_strsql";
        }
        $sql .= " ORDER BY r.stringid, r.id";
        $rs = $DB->get_recordset_sql($sql, $params);
        $component = new mlang_component($name, $lang, $version);
        foreach ($rs as $r) {
            if ($fullinfo) {
                $extra = new stdclass();
                foreach ($r as $property => $value) {
                    if (!in_array($property, array('stringid', 'text', 'timemodified', 'deleted'))) {
                        $extra->{$property} = $value;
                    }
                }
            } else {
                $extra = null;
            }
            // we force here so in case of two string with the same timemodified, the higher id wins
            $component->add_string(new mlang_string($r->stringid, $r->text, $r->timemodified, $r->deleted, $extra), true);
        }
        $rs->close();

        return $component;
    }

    /**
     * @param string $name the name of the component, eg. 'role', 'glossary', 'datafield_text' etc.
     * @param string $lang
     * @param mlang_version $version
     */

    public static function calculate_identifier($name, $lang, mlang_version $version) {
        return md5($name . '#' . $lang . '@' . $version->code);
    }

    /**
     * Returns the current identifier of the component
     *
     * Every component is identified by its branch, lang and name. This method returns md5 hash of
     * a concatenation of these three values.
     *
     * @return string
     */
    public function get_identifier() {
        return self::calculate_identifier($this->name, $this->lang, $this->version);
    }

    /**
     * Returns the iterator over strings
     */
    public function get_iterator() {
        return new mlang_iterator($this->strings);
    }

    /**
     * Returns the string object or null if not known
     *
     * @param string $id string identifier
     * @return mlang_string|null
     */
    public function get_string($id) {
        if (!isset($this->strings[$id])) {
            return null;
        }
        return $this->strings[$id];
    }

    /**
     * Checks if the component contains some strings or a given string
     *
     * @param string $id If null, checks if any string is defined. Otherwise checks for a given string
     * @return bool
     */
    public function has_string($id=null) {
        if (is_null($id)) {
            return (!empty($this->strings));
        } else {
            return (isset($this->strings[$id]));
        }
    }

    /**
     * Returns array of all string identifiers in the component
     *
     * @return array of string identifiers
     */
    public function get_string_keys() {
        return array_keys($this->strings);
    }

    /**
     * Adds new string into the collection
     *
     * @param mlang_string $string to add
     * @param bool $force if true, existing string will be replaced
     * @throw coding_exception when trying to add existing component without $force
     * @return void
     */
    public function add_string(mlang_string $string, $force=false) {
        if (!$force && isset($this->strings[$string->id])) {
            throw new coding_exception('You are trying to add a string that already exists. If this is intentional, use the force parameter');
        }
        $this->strings[$string->id] = $string;
        $string->component = $this;
    }

    /**
     * Removes a string from the component container
     *
     * @param string $id string identifier
     */
    public function unlink_string($id) {
        if (isset($this->strings[$id])) {
            $this->strings[$id]->component = null;
            unset($this->strings[$id]);
        }
    }

    /**
     * Unlinks all strings
     */
    public function clear() {
        foreach ($this->strings as $string) {
            $this->unlink_string($string->id);
        }
    }

    /**
     * Exports the string into a file in Moodle PHP format ($string array)
     *
     * @param string $filepath full path of the file to write into
     * @param string $phpdoc optional custom PHPdoc block for the file
     * @return false if unable to open a file
     */
    public function export_phpfile($filepath, $phpdoc = null) {
        if (! $f = fopen($filepath, 'w')) {
            return false;
        }
        fwrite($f, <<<EOF
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


EOF
        );
        if (empty($phpdoc)) {
            $branch = $this->version->branch;
            $lang   = $this->lang;
            $name   = $this->name;
            fwrite($f, <<<EOF
/**
 * Strings for component '$name', language '$lang', branch '$branch'
 *
 * @package   $this->name
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


EOF
            );
        } else {
            fwrite($f, $phpdoc);
        }
        foreach ($this->get_iterator() as $string) {
            fwrite($f, '$string[\'' . $string->id . '\'] = ');
            fwrite($f, var_export($string->text, true));
            fwrite($f, ";\n");
        }
        fclose($f);
    }

    /**
     * Returns the relative path to the file where the component should be exported to
     *
     * This respects the component language and version. For Moodle 1.6 - 1.9, string files
     * are put into common lang/xx_utf8 directory. Since Moodle 2.0, every plugin type
     * holds its own strings itself.
     *
     * For example, for Workshop module this returns 'lang/xx_utf8/workshop.php' in 1.x and
     * 'mod/workshop/lang/xx/workshop.php' in 2.x
     *
     * @return string relative path to the file
     */
    public function get_phpfile_location() {
        global $CFG;

        if ($this->version->code <= mlang_version::MOODLE_19) {
            // Moodle 1.x
            return 'lang/' . $this->lang . '_utf8/' . $this->name . '.php';

        } else {
            // Moodle 2.x
            list($type, $plugin) = normalize_component($this->name);
            if ($type === 'core') {
                return 'lang/' . $this->lang . '/' . $this->name . '.php';
            } else {
                $abspath = get_plugin_directory($type, $plugin);
                if (substr($abspath, 0, strlen($CFG->dirroot)) !== $CFG->dirroot) {
                    throw new coding_exception('Plugin directory outside dirroot');
                }
                $relpath = substr($abspath, strlen($CFG->dirroot) + 1);
                return $relpath . '/lang/' . $this->lang . '/' . $this->name . '.php';
            }
        }
    }

    /**
     * Prunes the strings, keeping just those defined in given $mask as well
     *
     * This may be used to get rid of strings that are not defined in another component.
     * Typically can be used to clean the translation from strings that are not defined in
     * the master English pack.
     * Beware - if the string is defined in $mask as deleted, it will be kept in this regardless
     * its state.
     *
     * @param mlang_component $mask master component to compare strings with
     * @return int number of removed strings
     */
    public function intersect(mlang_component $mask) {
        $removed = 0;
        $masked = array_flip($mask->get_string_keys());
        foreach (array_keys($this->strings) as $key) {
            if (! isset($masked[$key])) {
                $this->unlink_string($key);
                $removed++;
            }
        }
        return $removed;
    }
}

/**
 * Represents a single string
 */
class mlang_string {

    /** @var string identifier */
    public $id = null;

    /** @var string */
    public $text = '';

    /** @var int the time stamp when this string was saved */
    public $timemodified = null;

    /** @var bool is deleted */
    public $deleted = false;

    /** @var extra information about the string */
    public $extra = null;

    /** @var mlang_component we are part of */
    public $component;

    /**
     * @param string $id string identifier
     * @param string $text string text
     * @param int $timemodified
     * @param bool $deleted
     * @param stdclass $extra
     */
    public function __construct($id, $text='', $timemodified=null, $deleted=0, stdclass $extra=null) {
        if (is_null($timemodified)) {
            $timemodified = time();
        }
        $this->id           = $id;
        $this->text         = $text;
        $this->timemodified = $timemodified;
        $this->deleted      = $deleted;
        $this->extra        = $extra;
    }

    /**
     * Returns true if the two given strings should be considered as different, false otherwise.
     *
     * Deleted strings are considered equal, regardless the actual text
     *
     * @param mlang_string $a
     * @param mlang_string $b
     * @return bool
     */
    public static function differ(mlang_string $a, mlang_string $b) {
        if ($a->deleted and $b->deleted) {
            return false;
        }
        if (is_null($a->text) or is_null($b->text)) {
            if (is_null($a->text) and is_null($b->text)) {
                return false;
            } else {
                return true;
            }
        }
        if (trim($a->text) === trim($b->text)) {
            return false;
        }
        return true;
    }

    /**
     * Given a string text, returns it being formatted properly for storing in AMOS repository
     *
     * We need to know for what branch the string should be prepared due to internal changes in
     * format required by get_string()
     * - for get_string() in Moodle 1.6 - 1.9 use $format == 1
     * - for get_string() in Moodle 2.0 and higher use $format == 2
     *
     * Typical usages of this methods:
     *  $t = mlang_string::fix_syntax($t);          // sanity new translations of 2.x strings
     *  $t = mlang_string::fix_syntax($t, 1);       // sanity legacy 1.x strings
     *  $t = mlang_string::fix_syntax($t, 2, 1);    // convert format of 1.x strings into 2.x
     *
     * Backward converting 2.x format into 1.x is not supported
     *
     * @param string $text string text to be fixed
     * @param int $format target get_string() format version
     * @param int $from which format version does the text come from, defaults to the same as $format
     * @return string
     */
    public static function fix_syntax($text, $format=2, $from=null) {
        if (is_null($from)) {
            $from = $format;
        }

        if (($format === 2) && ($from === 2)) {
            // sanity translations of 2.x strings
            $clean = trim($text);
            $clean = str_replace("\r", '', $clean); // bad newline character caused by Windows
            $clean = str_replace("\\", '', $clean); // delete all slashes
            $clean = preg_replace("/\n{3,}/", "\n\n\n", $clean); // collapse runs of blank lines

        } elseif (($format === 2) && ($from === 1)) {
            // convert 1.x string into 2.x format
            $clean = trim($text);
            $clean = str_replace("\r", '', $clean); // bad newline character caused by Windows
            $clean = preg_replace("/\n{3,}/", "\n\n\n", $clean); // collapse runs of blank lines
            $clean = preg_replace('/%+/', '%', $clean); // collapse % characters
            $clean = str_replace('\$', '@@@___XXX_ESCAPED_DOLLAR__@@@', $clean); // remember for later
            $clean = str_replace("\\", '', $clean); // delete all slashes
            $clean = preg_replace('/(^|[^{])\$a\b(\->[a-zA-Z0-9_]+)?/', '\\1{$a\\2}', $clean); // wrap placeholders
            $clean = str_replace('@@@___XXX_ESCAPED_DOLLAR__@@@', '$', $clean);
            $clean = str_replace('&#36;', '$', $clean);

        } elseif (($format === 1) && ($from === 1)) {
            // sanity legacy 1.x strings
            $clean = trim($text);
            $clean = str_replace("\r", '', $clean); // bad newline character caused by Windows
            $clean = preg_replace("/\n{3,}/", "\n\n", $clean); // collapse runs of blank lines
            $clean = str_replace('\$', '@@@___XXX_ESCAPED_DOLLAR__@@@', $clean);
            $clean = str_replace("\\", '', $clean); // delete all slashes
            $clean = str_replace('$', '\$', $clean); // escape all embedded variables
            // unescape placeholders: only $a and $a->something are allowed. All other $variables are left escaped
            $clean = preg_replace('/\\\\\$a\b(\->[a-zA-Z0-9_]+)?/', '$a\\1', $clean); // unescape placeholders
            $clean = str_replace('@@@___XXX_ESCAPED_DOLLAR__@@@', '\$', $clean);
            $clean = str_replace('"', "\\\"", $clean); // add slashes for "
            $clean = preg_replace('/%+/', '%', $clean); // collapse % characters
            $clean = str_replace('%', '%%', $clean); // duplicate %

        } else {
            throw new coding_exception('Unknown get_string() format version');
        }
        return $clean;
    }
}

/**
 * Staging area is a collection of components to be committed into the strings repository
 *
 * After obtaining a new instance and adding some components into it, you should either call commit()
 * or clear(). Otherwise, the copies of staged strings remain in PHP memory and they are not
 * garbage collected because of the circular reference component-string.
 */
class mlang_stage {

    /** @var array of mlang_component */
    protected $components = array();

    /**
     * Adds a copy of the given component into the staging area
     *
     * @param mlang_component $component
     * @param bool $force replace the previously staged string if there is such if there is already
     * @return void
     */
    public function add(mlang_component $component, $force=false) {
        $cid = $component->get_identifier();
        if (!isset($this->components[$cid])) {
            $this->components[$cid] = new mlang_component($component->name, $component->lang, $component->version);
        }
        foreach ($component->get_iterator() as $string) {
            $this->components[$cid]->add_string(clone($string), $force);
        }
    }

    /**
     * Removes staged components
     */
    public function clear() {
        foreach ($this->components as $component) {
            $component->clear();
        }
        $this->components = array();
    }

    /**
     * Check the staged strings against the repository cap and keep modified strings only
     *
     * @param int|null $basetimestamp the timestamp to rebase against, null for the most recent
     * @param bool $deletemissing if true, then all strings that are in repository but not in stage will be marked as to be deleted
     * @param int $deletetimestamp if $deletemissing is tru, what timestamp to use when removing strings (defaults to current)
     */
    public function rebase($basetimestamp=null, $deletemissing=false, $deletetimestamp=null) {
        if(!is_bool($deletemissing)) {
            throw new coding_exception('Incorrect type of the parameter $deletemissing');
        }
        foreach ($this->components as $cx => $component) {
            $cap = mlang_component::from_snapshot($component->name, $component->lang, $component->version, $basetimestamp, true);
            if ($deletemissing) {
                if (empty($deletetimestamp)) {
                    $deletetimestamp = time();
                }
                foreach ($cap->get_iterator() as $existing) {
                    $stagedstring = $component->get_string($existing->id);
                    if (is_null($stagedstring)) {
                        $tobedeleted = clone($existing);
                        $tobedeleted->deleted = true;
                        $tobedeleted->timemodified = $deletetimestamp;
                        $component->add_string($tobedeleted);
                    }
                }
            }
            foreach ($component->get_iterator() as $stagedstring) {
                $capstring = $cap->get_string($stagedstring->id);
                if (is_null($capstring)) {
                    // the staged string does not exist in the repository yet - will be committed
                    continue;
                }
                if ($stagedstring->deleted && empty($capstring->deleted)) {
                    // the staged string object is the removal record - will be committed
                    continue;
                }
                if (!mlang_string::differ($stagedstring, $capstring)) {
                    // the staged string is the same as the most recent one in the repository
                    $component->unlink_string($stagedstring->id);
                    continue;
                }
                if ($stagedstring->timemodified < $capstring->timemodified) {
                    // the staged string is older than the cap, do not keep it
                    $component->unlink_string($stagedstring->id);
                    continue;
                }
            }
            // unstage the whole component if it is empty
            if (!$component->has_string()) {
                unset($this->components[$cx]);
            }
            $cap->clear();
        }
    }

    /**
     * Commit the strings in the staging area, by default rebasing first
     *
     * @param string $message commit message
     * @param array $meta optional meta information
     * @param bool $skiprebase if true, do not rebase before committing
     */
    public function commit($message='', array $meta=null, $skiprebase=false) {
        if (empty($skiprebase)) {
            $this->rebase();
        }
        foreach ($this->components as $cx => $component) {
            foreach ($component->get_iterator() as $string) {
                $record = new stdclass();
                if (!empty($meta)) {
                    foreach ($meta as $field => $value) {
                        $record->{$field} = $value;
                    }
                }
                $record->branch     = $component->version->code;
                $record->lang       = $component->lang;
                $record->component  = $component->name;
                $record->stringid   = $string->id;
                $record->text       = $string->text;
                $record->timemodified = $string->timemodified;
                $record->deleted    = $string->deleted;
                $record->commitmsg  = trim($message);

                $this->commit_low_add($record);
            }
        }
        $this->clear();
    }

    /**
     * Low level commit
     *
     * @param stdclass $record repository record to be inserted into strings database
     * @return new record identifier
     */
    protected function commit_low_add(stdclass $record) {
        global $DB;
        return $DB->insert_record('amos_repository', $record);
    }

    /**
     * Returns the iterator over components
     */
    public function get_iterator() {
        return new mlang_iterator($this->components);
    }

    /**
     * Returns the staged component or null if not known
     *
     * @param string $name the name of the component, eg. 'role', 'glossary', 'datafield_text' etc.
     * @param string $lang
     * @param mlang_version $version
     * @return mlang_component|null
     */
    public function get_component($name, $lang, mlang_version $version) {
        $cid = mlang_component::calculate_identifier($name, $lang, $version);
        if (!isset($this->components[$cid])) {
            return null;
        }
        return $this->components[$cid];
    }

    /**
     * Checks if the stage contains some component or a given component
     *
     * @param string $name the name of the component, eg. 'role', 'glossary', 'datafield_text' etc.
     * @param string $lang
     * @param mlang_version $version
     * @return bool
     */
    public function has_component($name=null, $lang=null, mlang_version $version=null) {
        if (is_null($name) and is_null($lang) and is_null($version)) {
            return (!empty($this->components));
        } else {
            $cid = mlang_component::calculate_identifier($name, $lang, $version);
            return (isset($this->components[$cid]));
        }
    }


}

/**
 * Starageable staging area
 */
class mlang_persistent_stage extends mlang_stage {

    /**
     * TODO: short description.
     *
     * @param stdclass $user 
     * @return TODO
     */
    public static function instance_for_user(stdclass $user) {
        return new mlang_persistent_stage();
    }
}

/**
 * Provides information about Moodle versions and corresponding branches
 *
 * Do not modify the returned instances, they are not cloned during coponent copying.
 */
class mlang_version {
    /** internal version codes stored in database */
    const MOODLE_16 = 1600;
    const MOODLE_17 = 1700;
    const MOODLE_18 = 1800;
    const MOODLE_19 = 1900;
    const MOODLE_20 = 2000;
    const MOODLE_21 = 2100;
    const MOODLE_22 = 2200;
    const MOODLE_23 = 2300;

    /** @var int internal code of the version */
    public $code;

    /** @var string  human-readable label of the version */
    public $label;

    /** @var string the name of the corresponding CVS/git branch */
    public $branch;

    /** @var bool allow translations of strings on this branch? */
    public $translatable;

    /** @var bool is this a version that translators should focus on? */
    public $current;

    /**
     * Factory method
     *
     * @param int $code
     * @return mlang_version|null
     */
    public static function by_code($code) {
        foreach (self::versions_info() as $ver) {
            if ($ver['code'] == $code) {
                return new mlang_version($ver);
            }
        }
        return null;
    }

    /**
     * Factory method
     *
     * @param string $branch like 'MOODLE_20_STABLE'
     * @return mlang_version|null
     */
    public static function by_branch($branch) {
        foreach (self::versions_info() as $ver) {
            if ($ver['branch'] == $branch) {
                return new mlang_version($ver);
            }
        }
        return null;
    }

    /**
     * Get a list of all known versions and information about them
     *
     * @return array of mlang_version
     */
    public static function list_translatable() {
        $list = array();
        foreach (self::versions_info() as $ver) {
            if ($ver['translatable']) {
                $list[$ver['code']] = new mlang_version($ver);
            }
        }
        return $list;
    }

    /**
     * Used by factory methods to create instances of this class
     */
    protected function __construct(array $info) {
        foreach ($info as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * @return array
     */
    protected static function versions_info() {
        return array(
            array(
                'code'          => self::MOODLE_21,
                'label'         => '2.1',
                'branch'        => 'MOODLE_21_STABLE',
                'dir'           => 'lang21',
                'translatable'  => false,
                'current'       => false,
            ),
            array(
                'code'          => self::MOODLE_20,
                'label'         => '2.0',
                'branch'        => 'MOODLE_20_STABLE',
                'dir'           => 'lang20',
                'translatable'  => true,
                'current'       => true,
            ),
            array(
                'code'          => self::MOODLE_19,
                'label'         => '1.9',
                'branch'        => 'MOODLE_19_STABLE',
                'dir'           => 'lang19',
                'translatable'  => true,
                'current'       => true,
            ),
            array(
                'code'          => self::MOODLE_18,
                'label'         => '1.8',
                'branch'        => 'MOODLE_18_STABLE',
                'dir'           => 'lang18',
                'translatable'  => true,
                'current'       => false,
            ),
            array(
                'code'          => self::MOODLE_17,
                'label'         => '1.7',
                'branch'        => 'MOODLE_17_STABLE',
                'dir'           => 'lang17',
                'translatable'  => true,
                'current'       => false,
            ),
            array(
                'code'          => self::MOODLE_16,
                'label'         => '1.6',
                'branch'        => 'MOODLE_16_STABLE',
                'dir'           => 'lang16',
                'translatable'  => true,
                'current'       => false,
            ),
        );
    }
}

/**
 * Class providing various AMOS-related tools
 */
class mlang_tools {

    /**
     * Returns the tree of all known components
     *
     * @param array $conditions can specify branch, lang and component to filter
     * @return array [branch][language][component] => null
     */
    public static function components_tree(array $conditions=null) {
        global $DB;

        $where = array();
        if (!empty($conditions['branch'])) {
            $where[] = 'branch = :branch';
        }
        if (!empty($conditions['lang'])) {
            $where[] = 'lang = :lang';
        }
        if (!empty($conditions['component'])) {
            $where[] = 'component = :component';
        }
        if (!empty($where)) {
            $where = "WHERE " . implode(" AND ", $where) . "\n";
        }
        $sql = "SELECT branch,lang,component
                  FROM {amos_repository}\n";
        if (!empty($where)) {
            $sql .= $where;
        }
        $sql .= "GROUP BY branch,lang,component
                 ORDER BY branch,lang,component";
        $rs = $DB->get_recordset_sql($sql, $conditions);
        $tree = array();
        foreach ($rs as $record) {
            if (!isset($tree[$record->branch])) {
                $tree[$record->branch] = array();
            }
            if (!isset($tree[$record->branch][$record->lang])) {
                $tree[$record->branch][$record->lang] = array();
            }
            $tree[$record->branch][$record->lang][$record->component] = null;
        }
        $rs->close();

        return $tree;
    }
}
