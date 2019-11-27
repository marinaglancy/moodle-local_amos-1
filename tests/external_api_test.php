<?php
// This file is part of Moodle - https://moodle.org/
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
 * Provides the {@link local_amos_external_api_testcase} class.
 *
 * @package     local_amos
 * @category    test
 * @copyright   2019 David Mudrák <david@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Unit tests for the AMOS external functions.
 *
 * @copyright 2019 David Mudrák <david@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_amos_external_api_testcase extends externallib_advanced_testcase {

    /**
     * Test the permission check for the update_strings_file external function.
     */
    public function test_update_strings_file_without_import_capability() {
        $this->resetAfterTest(true);

        $user = self::getDataGenerator()->create_user();
        self::setUser($user);

        $roleid = $this->assignUserCapability('local/amos:importstrings', SYSCONTEXTID);
        $this->unassignUserCapability('local/amos:importstrings', SYSCONTEXTID, $roleid);

        $this->expectException(required_capability_exception::class);

        \local_amos\external\api::update_strings_file('Test User <test@example.com>', 'Just a test update', []);
    }

    /**
     * Test the behaviour of the update_strings_file external function.
     */
    public function test_update_strings_file() {
        $this->resetAfterTest(true);

        $user = self::getDataGenerator()->create_user();
        self::setUser($user);

        $this->assignUserCapability('local/amos:importstrings', SYSCONTEXTID);

        $raw = \local_amos\external\api::update_strings_file(
            'Johny Developer <developer@example.com>',
            'First version of the tool_foobar',
            [
                [
                    'componentname' => 'tool_foobar',
                    'moodlebranch' => '3.6',
                    'language' => 'en',
                    'stringfilename' => 'tool_foobar.php',
                    'stringfilecontent' => '<?php $string["pluginname"] = "Foo bar 3.6";',
                ],
                [
                    'componentname' => 'tool_foobar',
                    'moodlebranch' => '3.5',
                    'language' => 'en',
                    'stringfilename' => 'tool_foobar.php',
                    'stringfilecontent' => '<?php $string["pluginname"] = "Foo bar 3.5";',
                ],
            ]
        );

        $clean = external_api::clean_returnvalue(\local_amos\external\api::update_strings_file_returns(), $raw);

        $this->assertTrue(is_array($clean));
        $this->assertEquals(2, count($clean));

        $this->assertContains([
            'componentname' => 'tool_foobar',
            'moodlebranch' => '3.6',
            'language' => 'en',
            'status' => 'ok',
            'found' => 1,
            'changes' => 1,
        ], $clean);

        $this->assertContains([
            'componentname' => 'tool_foobar',
            'moodlebranch' => '3.5',
            'language' => 'en',
            'status' => 'ok',
            'found' => 1,
            'changes' => 1,
        ], $clean);

        $component = mlang_component::from_snapshot('tool_foobar', 'en', mlang_version::by_branch('MOODLE_36_STABLE'));
        $this->assertEquals($component->get_string('pluginname')->text, 'Foo bar 3.6');
        $component->clear();

        $component = mlang_component::from_snapshot('tool_foobar', 'en', mlang_version::by_branch('MOODLE_35_STABLE'));
        $this->assertEquals($component->get_string('pluginname')->text, 'Foo bar 3.5');
        $component->clear();

        $component = mlang_component::from_snapshot('tool_foobar', 'en', mlang_version::by_branch('MOODLE_37_STABLE'));
        $this->assertFalse($component->has_string());
        $component->clear();

        $component = mlang_component::from_snapshot('tool_foobar', 'en', mlang_version::by_branch('MOODLE_34_STABLE'));
        $this->assertFalse($component->has_string());
        $component->clear();
    }

    /**
     * Test the behaviour of the plugin_translation_stats external function when unknown component is requested.
     */
    public function test_plugin_translation_stats_unknown_component() {
        $this->resetAfterTest(true);

        // No special capability needed.
        $user = self::getDataGenerator()->create_user();
        self::setUser($user);

        $this->expectException(invalid_parameter_exception::class);

        \local_amos\external\api::plugin_translation_stats('muhehe');
    }

    /**
     * Test the behaviour of the plugin_translation_stats external function.
     */
    public function test_plugin_translation_stats() {
        global $CFG;
        require_once($CFG->dirroot.'/local/amos/mlanglib.php');

        $this->resetAfterTest(true);

        // No special capability needed.
        $user = self::getDataGenerator()->create_user();
        self::setUser($user);

        $stage = new mlang_stage();
        $component = new mlang_component('langconfig', 'en', mlang_version::by_branch('MOODLE_36_STABLE'));
        $component->add_string(new mlang_string('thislanguageint', 'English'));
        $stage->add($component);
        $component->clear();
        $stage->commit('Registering English language', ['source' => 'unittest']);

        $statsman = new local_amos_stats_manager();
        $statsman->update_stats('3600', 'en', 'tool_foo', 9);

        $raw = \local_amos\external\api::plugin_translation_stats('tool_foo');
        $clean = external_api::clean_returnvalue(\local_amos\external\api::plugin_translation_stats_returns(), $raw);

        $this->assertEquals(1, count($clean['langnames']));
        $this->assertEquals(1, count($clean['branches']));
    }
}
