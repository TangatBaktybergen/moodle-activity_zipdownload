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
 * View page for ZIP Download activity.
 *
 * @package    mod_zipdownload
 * @copyright  2025 Ivan Volosyak and Tangat Baktybergen <Ivan.Volosyak@@@hochschule-rhein-waal.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);
$rawplatform = optional_param('platform', '', PARAM_ALPHA);
$validplatforms = ['lab', 'win', 'mac'];

$cm = get_coursemodule_from_id('zipdownload', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);

$zipdownload = $DB->get_record('zipdownload', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/zipdownload/view.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'zipdownload'));
$PAGE->set_heading($course->fullname);

$platform = in_array($rawplatform, $validplatforms) ? $rawplatform : $zipdownload->defaultplatform;

if ($rawplatform === '') {
    echo $OUTPUT->header();

    // Add Back button.
    echo html_writer::tag('button', 'Back', [
        'type' => 'button',
        'onclick' => 'window.history.back();',
        'class' => 'btn btn-secondary',
        'style' => 'margin-bottom:15px;',
    ]);

    echo $OUTPUT->heading(get_string('selectplatform', 'mod_zipdownload'));

    $url = new moodle_url('/mod/zipdownload/view.php');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);

    foreach ($validplatforms as $option) {
        $checked = ($option === $zipdownload->defaultplatform) ? ['checked' => 'checked'] : [];
        echo html_writer::start_tag('div');
        echo html_writer::empty_tag('input', array_merge([
            'type' => 'radio',
            'name' => 'platform',
            'value' => $option,
            'id' => $option,
            'style' => 'margin-right:8px',
        ], $checked));
        echo html_writer::tag('label', ucfirst($option), ['for' => $option]);
        echo html_writer::end_tag('div');
    }

    echo html_writer::empty_tag('br');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('download', 'mod_zipdownload'),
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
    exit;
}

$fs = get_file_storage();
$packer = get_file_packer('application/zip');
$files = $fs->get_area_files($context->id, 'mod_zipdownload', 'templatezip', 0, 'itemid, filepath, filename', false);


$templatezip = reset($files);
$tempzip = $templatezip->copy_content_to_temp();
$tempdir = make_temp_directory('mod_zipdownload_' . $USER->id . '_' . time());
$unzippath = $tempdir . '/unzipped';
mkdir($unzippath, 0777, true);
$packer->extract_to_pathname($tempzip, $unzippath);

$studentname = fullname($USER);

// Always extract ID from username (before @) or fallback to username/id as per latest professor advice.
if (!empty($USER->username) && strpos($USER->username, '@') !== false) {
    $studentid = substr($USER->username, 0, strpos($USER->username, '@'));
} else if (!empty($USER->username)) { // Changed ELSEIF to ELSE IF.
    $studentid = $USER->username;
} else {
    $studentid = $USER->id;
}

// Variables all lower case.
$editableextensions = ['.c'];
$platformports = [
    'lab' => '/dev/ttyUSB_MySmartUSB',
    'win' => 'COM3',
    'mac' => '/dev/tty.SLAB_USBtoUART',
];

$allfiles = get_directory_list($unzippath, '', true, true);
foreach ($allfiles as $relpath) {
    $fullpath = $unzippath . '/' . $relpath;
    foreach ($editableextensions as $ext) {
        if (substr($relpath, -strlen($ext)) === $ext) {
            $content = file_get_contents($fullpath);
            $content = str_replace('"00000"', '"' . $studentid . '"', $content);
            $content = str_replace('@author TODO', '@author ' . $studentname, $content);
            file_put_contents($fullpath, $content);
            break;
        }
    }
}
// Update all Makefiles with correct PORT line.
foreach ($allfiles as $relpath) {
    if (strtolower(basename($relpath)) === 'makefile') {
        $makefilepath = $unzippath . '/' . $relpath;
        $lines = file($makefilepath);
        $modified = false;

        foreach ($lines as &$line) {
            if (preg_match('/^\s*PORT\s*=/', $line)) {
                $line = 'PORT=' . $platformports[$platform] . PHP_EOL;
                $modified = true;
            }
        }

        if ($modified) {
            file_put_contents($makefilepath, implode('', $lines));
        }
    }
}

$filearray = [];
foreach ($allfiles as $relpath) {
    $filearray[$relpath] = $unzippath . '/' . $relpath;
}

$finalname = 'Templates-' . $studentid . '-' . ucfirst($platform) . '.zip';
$finalzip = $tempdir . '/' . $finalname;
$packer->archive_to_pathname($filearray, $finalzip);
send_temp_file($finalzip, $finalname);
exit;
