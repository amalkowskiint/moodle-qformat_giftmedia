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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');
require_once($CFG->dirroot . '/question/format/giftmedia/format.php');

/**
 * Tests that media references can only point inside the extracted archive.
 *
 * @package    qformat_giftmedia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qformat_giftmedia::text_field
 */
final class media_path_test extends \advanced_testcase {
    /** @var string directory acting as the parent of the extraction dir. */
    protected $basedir;

    /** @var \qformat_giftmedia importer under test. */
    protected $importer;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->basedir = make_request_directory();
        $tempdir = $this->basedir . '/import';
        mkdir($tempdir . '/img', 0777, true);
        file_put_contents($tempdir . '/img/a.png', 'PNGDATA');
        file_put_contents($tempdir . '/img/a+b(1).png', 'PNGDATA2');
        file_put_contents($this->basedir . '/secret.php', '<?php $CFG->dbpass = "s3cr3t";');

        $this->importer = new \qformat_giftmedia();
        $this->importer->tempdir = $tempdir;
    }

    /**
     * Return the names of files stored in the draft area of the given item.
     *
     * @param array $data result of text_field()
     * @return string[]
     */
    protected function get_draft_filenames(array $data): array {
        global $USER;
        if (empty($data['itemid'])) {
            return [];
        }
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $data['itemid'],
            'filename',
            false
        );
        return array_values(array_map(function ($f) {
            return $f->get_filename();
        }, $files));
    }

    /**
     * A media file inside the archive is stored and the reference rewritten.
     */
    public function test_valid_media_is_imported(): void {
        $data = $this->importer->text_field('<img src="@@PLUGINFILE@@/img/a.png">');
        $this->assertEquals('<img src="@@PLUGINFILE@@/img__a.png">', $data['text']);
        $this->assertEquals(['img__a.png'], $this->get_draft_filenames($data));
    }

    /**
     * Regex metacharacters in a path must not break the reference rewriting.
     */
    public function test_regex_metacharacters_in_path(): void {
        $data = $this->importer->text_field('<img src="@@PLUGINFILE@@/img/a+b(1).png">');
        $files = $this->get_draft_filenames($data);
        $this->assertCount(1, $files);
        $this->assertEquals('<img src="@@PLUGINFILE@@/' . $files[0] . '">', $data['text']);
    }

    /**
     * Malicious or invalid references.
     *
     * @return array
     */
    public static function malicious_path_provider(): array {
        return [
            'parent dir' => ['../secret.php'],
            'nested parent dir' => ['img/../../secret.php'],
            'backslash parent dir' => ['img\\..\\..\\secret.php'],
            'deep traversal' => ['../../../../../../../../config.php'],
            'stream wrapper' => ['php://filter/resource=../secret.php'],
            'directory' => ['img'],
            'missing file' => ['img/nothere.png'],
        ];
    }

    /**
     * References outside the extracted archive are ignored and nothing is stored.
     *
     * @dataProvider malicious_path_provider
     * @param string $path
     */
    public function test_malicious_path_is_rejected(string $path): void {
        $text = '<img src="@@PLUGINFILE@@/' . $path . '">';
        $data = $this->importer->text_field($text);
        $this->assertSame([], $this->get_draft_filenames($data));
        $this->assertEquals($text, $data['text']);
    }

    /**
     * Absolute paths are ignored and nothing is stored.
     */
    public function test_absolute_path_is_rejected(): void {
        $path = $this->basedir . '/secret.php';
        $text = '<img src="@@PLUGINFILE@@/' . $path . '">';
        $data = $this->importer->text_field($text);
        $this->assertSame([], $this->get_draft_filenames($data));
    }
}
