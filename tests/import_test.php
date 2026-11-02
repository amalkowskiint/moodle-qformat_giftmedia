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

namespace qformat_giftmedia;

use core_question\local\bank\question_edit_contexts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');
require_once($CFG->dirroot . '/question/format/giftmedia/format.php');

/**
 * Tests of the zip handling and of the full import into the question bank.
 *
 * @package    qformat_giftmedia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qformat_giftmedia
 */
final class import_test extends \advanced_testcase {
    /**
     * Path of the example archive shipped with the tests.
     *
     * @return string
     */
    protected function get_example_zip(): string {
        global $CFG;
        return $CFG->dirroot . '/question/format/giftmedia/tests/fixtures/giftmedia_example.zip';
    }

    /**
     * Build a zip archive from an array of archive path => file content.
     *
     * @param array $contents
     * @return string path of the created zip file
     */
    protected function create_zip(array $contents): string {
        $dir = make_request_directory();
        $files = [];
        foreach ($contents as $archivepath => $content) {
            $source = $dir . '/' . md5($archivepath);
            file_put_contents($source, $content);
            $files[$archivepath] = $source;
        }
        $zipfile = $dir . '/test.zip';
        $packer = get_file_packer('application/zip');
        $this->assertTrue((bool) $packer->archive_to_pathname($files, $zipfile));
        return $zipfile;
    }

    /**
     * Run a full import (as question/bank/importquestions/import.php does) into a new course.
     *
     * @param string $zipfile path of the archive to import
     * @return array [\context_course $context, \qformat_giftmedia $format]
     */
    protected function import_zip(string $zipfile): array {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $category = question_make_default_categories([$context]);

        $format = new \qformat_giftmedia();
        $format->setCategory($category);
        $format->setContexts((new question_edit_contexts($context))->all());
        $format->setCourse($course);
        $format->setFilename($zipfile);
        $format->setRealfilename(basename($zipfile));
        $format->setMatchgrades('error');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        $format->set_display_progress(false);

        ob_start();
        $this->assertTrue($format->importpreprocess());
        $this->assertTrue($format->importprocess());
        $this->assertTrue($format->importpostprocess());
        ob_end_clean();

        return [$context, $format];
    }

    /**
     * Names of the files stored in a question file area.
     *
     * @param \context $context
     * @param string $filearea
     * @param int $itemid
     * @return string[]
     */
    protected function get_filenames(\context $context, string $filearea, int $itemid): array {
        $files = get_file_storage()->get_area_files($context->id, 'question', $filearea, $itemid, 'filename', false);
        return array_values(array_map(function ($f) {
            return $f->get_filename();
        }, $files));
    }

    /**
     * Assert that every @@PLUGINFILE@@ reference in a text points to an existing file.
     *
     * @param string $text
     * @param string[] $filenames files available for this text
     */
    protected function assert_references_exist(string $text, array $filenames): void {
        preg_match_all('|"@@PLUGINFILE@@/([^"]*)"|', $text, $matches);
        $this->assertNotEmpty($matches[1], 'No @@PLUGINFILE@@ reference found in: ' . $text);
        foreach ($matches[1] as $ref) {
            $this->assertContains(rawurldecode($ref), $filenames);
        }
    }

    /**
     * Names of the files left in the current user's draft areas.
     *
     * @return string[]
     */
    protected function get_user_draft_filenames(): array {
        global $USER;
        $usercontextid = \context_user::instance($USER->id)->id;
        $files = get_file_storage()->get_area_files($usercontextid, 'user', 'draft', false, 'filename', false);
        return array_values(array_map(function ($f) {
            return $f->get_filename();
        }, $files));
    }

    /**
     * The example archive is imported with its MP3 in the question text and its images in the answers.
     */
    public function test_import_example_archive_saves_media(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$context, $format] = $this->import_zip($this->get_example_zip());

        $question = $DB->get_record('question', ['name' => 'MC question with images and an MP3 file'], '*', MUST_EXIST);
        $this->assertEquals('multichoice', $question->qtype);

        $questionfiles = $this->get_filenames($context, 'questiontext', $question->id);
        $this->assertEquals(['sounds__piano.mp3'], $questionfiles);
        $this->assert_references_exist($question->questiontext, $questionfiles);

        $answers = $DB->get_records('question_answers', ['question' => $question->id], 'id');
        $this->assertCount(4, $answers);
        $allanswerfiles = [];
        foreach ($answers as $answer) {
            $answerfiles = $this->get_filenames($context, 'answer', $answer->id);
            $this->assertCount(1, $answerfiles);
            $this->assert_references_exist($answer->answer, $answerfiles);
            $allanswerfiles = array_merge($allanswerfiles, $answerfiles);
        }
        $this->assertEqualsCanonicalizing([
            'images__120px-Perzina_pianos.JPG',
            'images__93px-Zuckermann_ZBox_harpsichord.JPG',
            'images__99px-SMV_organ.jpg',
            'images__120px-MIM_Clavichord_CN344.jpg',
        ], $allanswerfiles);

        // Nothing is left behind: no draft files and no temporary directory.
        $this->assertSame([], $this->get_user_draft_filenames());
        $this->assertDirectoryDoesNotExist($format->tempdir);
    }

    /**
     * Media in the general feedback are saved too, and idnumber/tags from comments are imported.
     */
    public function test_import_general_feedback_media_idnumber_and_tags(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('usetags', 1); // Tags are only saved when the tags subsystem is enabled.

        $gift = "// [id:GM-1] [tag:media]\n"
            . "::GF:: [html]Look <img src\\=\"@@PLUGINFILE@@/img/q.png\">"
            . "{=yes ~no ####<img src\\=\"@@PLUGINFILE@@/img/fb.png\">}\n";
        $zipfile = $this->create_zip([
            'questions.txt' => $gift,
            'img/q.png' => 'QPNG',
            'img/fb.png' => 'FBPNG',
        ]);

        [$context] = $this->import_zip($zipfile);

        $question = $DB->get_record('question', ['name' => 'GF'], '*', MUST_EXIST);
        $questionfiles = $this->get_filenames($context, 'questiontext', $question->id);
        $this->assertEquals(['img__q.png'], $questionfiles);
        $this->assert_references_exist($question->questiontext, $questionfiles);

        $feedbackfiles = $this->get_filenames($context, 'generalfeedback', $question->id);
        $this->assertEquals(['img__fb.png'], $feedbackfiles);
        $this->assert_references_exist($question->generalfeedback, $feedbackfiles);

        $entry = get_question_bank_entry($question->id);
        $this->assertEquals('GM-1', $entry->idnumber);
        $tags = \core_tag_tag::get_item_tags_array('core_question', 'question', $question->id);
        $this->assertEquals(['media'], array_values($tags));
    }

    /**
     * A valid archive is extracted and its gift file returned; postprocess removes the temporary directory.
     */
    public function test_readdata_valid_archive(): void {
        $this->resetAfterTest();
        $format = new \qformat_giftmedia();

        $lines = $format->readdata($this->get_example_zip());

        $this->assertIsArray($lines);
        $this->assertStringContainsString('::MC question with images and an MP3 file::', implode('', $lines));
        $this->assertFileExists($format->tempdir . '/images/99px-SMV_organ.jpg');
        $this->assertFileExists($format->tempdir . '/sounds/piano.mp3');

        $format->importpostprocess();
        $this->assertDirectoryDoesNotExist($format->tempdir);
    }

    /**
     * An archive without a .txt file at root level is rejected with a clear message.
     */
    public function test_readdata_archive_without_gift_file(): void {
        $this->resetAfterTest();
        $zipfile = $this->create_zip(['images/a.png' => 'PNG', 'sub/questions.txt' => '::Q:: x{}']);
        $format = new \qformat_giftmedia();

        ob_start();
        $result = $format->readdata($zipfile);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertStringContainsString(get_string('nogiftfile', 'qformat_giftmedia'), $output);
        $this->assertDirectoryDoesNotExist($format->tempdir);
    }

    /**
     * A file which is not a zip archive is rejected and the temporary directory removed.
     */
    public function test_readdata_not_a_zip(): void {
        $this->resetAfterTest();
        $file = make_request_directory() . '/notazip.zip';
        file_put_contents($file, 'this is not a zip archive');
        $format = new \qformat_giftmedia();

        ob_start();
        $result = $format->readdata($file);
        $output = ob_get_clean();

        $this->assertFalse($result);
        $this->assertDebuggingCalled(); // The zip packer reports "Not a zip archive".
        $this->assertStringContainsString(get_string('cannotunzip', 'question'), $output);
        $this->assertDirectoryDoesNotExist($format->tempdir);
    }

    /**
     * Two imports never share the same temporary directory.
     */
    public function test_readdata_uses_unique_tempdir(): void {
        $this->resetAfterTest();
        $first = new \qformat_giftmedia();
        $second = new \qformat_giftmedia();

        $first->readdata($this->get_example_zip());
        $second->readdata($this->get_example_zip());

        $this->assertNotEquals($first->tempdir, $second->tempdir);
        $first->importpostprocess();
        $this->assertDirectoryExists($second->tempdir);
        $second->importpostprocess();
    }
}
