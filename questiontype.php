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
 * Question type class for the short answer question type.
 *
 * @package    qtype
 * @subpackage canvas
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/type/canvas/question.php');


/**
 * The short answer question type.
 *
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_canvas extends question_type {
    public function extra_question_fields() {
        return array('qtype_canvas', 'threshold', 'radius');
    }

    public function questionid_column_name() {
        return 'questionid';
    }

    public function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        $this->move_files_in_answers($questionid, $oldcontextid, $newcontextid);
    }

    protected function delete_files($questionid, $contextid) {
        parent::delete_files($questionid, $contextid);
        $this->delete_files_in_answers($questionid, $contextid);
    }

    public function save_question_options($question) {
        global $DB;
        $result = new stdClass();

        $context = $question->context;

        $oldanswers = $DB->get_records('question_answers',
                array('question' => $question->id), 'id ASC');

        $answers = array();
        $maxfraction = -1;

        // Insert all the new answers
        foreach ($question->answer as $key => $answerdata) {
            // Check for, and ignore, completely blank answer from the form.
            if (trim($answerdata) == '' && $question->fraction[$key] == 0 &&
                    html_is_blank($question->feedback[$key]['text'])) {
                continue;
            }

            // Update an existing answer if possible.
            $answer = array_shift($oldanswers);
            if (!$answer) {
                $answer = new stdClass();
                $answer->question = $question->id;
                $answer->answer = '';
                $answer->feedback = '';
                $answer->id = $DB->insert_record('question_answers', $answer);
            }

            $answer->answer   = trim($answerdata);
            $answer->fraction = $question->fraction[$key];
            $answer->feedback = $this->import_or_save_files($question->feedback[$key],
                    $context, 'question', 'answerfeedback', $answer->id);
            $answer->feedbackformat = $question->feedback[$key]['format'];
            $DB->update_record('question_answers', $answer);

            $answers[] = $answer->id;
            if ($question->fraction[$key] > $maxfraction) {
                $maxfraction = $question->fraction[$key];
            }
        }

        $question->answers = implode(',', $answers);
        $parentresult = parent::save_question_options($question);
        if ($parentresult !== null) {
            // Parent function returns null if all is OK
            return $parentresult;
        }

        // Delete any left over old answer records.
        $fs = get_file_storage();
        foreach ($oldanswers as $oldanswer) {
            $fs->delete_area_files($context->id, 'question', 'answerfeedback', $oldanswer->id);
            $DB->delete_records('question_answers', array('id' => $oldanswer->id));
        }

        $this->save_hints($question);

        $answer = new stdClass();
        $answer->question = $question->id;
        $answer->answer = $question->qtype_canvas_textarea;
        $answer->feedback = '';
        $answer->id = $DB->insert_record('question_answers', $answer);

        file_save_draft_area_files($question->qtype_canvas_image_file, $question->context->id,
                'qtype_canvas', 'qtype_canvas_image_file', $question->id,
                array('subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 1));




    }

    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $this->initialise_question_answers($question, $questiondata);
    }

    public function get_random_guess_score($questiondata) {
        foreach ($questiondata->options->answers as $aid => $answer) {
            if ('*' == trim($answer->answer)) {
                return $answer->fraction;
            }
        }
        return 0;
    }

    public function get_possible_responses($questiondata) {
        $responses = array();

        $starfound = false;
        foreach ($questiondata->options->answers as $aid => $answer) {
            $responses[$aid] = new question_possible_response($answer->answer,
                    $answer->fraction);
            if ($answer->answer === '*') {
                $starfound = true;
            }
        }

        if (!$starfound) {
            $responses[0] = new question_possible_response(
                    get_string('didnotmatchanyanswer', 'question'), 0);
        }

        $responses[null] = question_possible_response::no_response();

        return array($questiondata->id => $responses);
    }
	// Voma add start
	// http://moodle.org/plugins/pluginversions.php?plugin=qtype_regexp
    function export_to_xml($question, qformat_xml $format, $extra=null) {
    	$extraquestionfields = $this->extra_question_fields();
        if (!is_array($extraquestionfields)) {
            return false;
        }
        //omit table name (question)
        array_shift($extraquestionfields);
        $expout='';

        /*foreach ($extraquestionfields as $field) {
            $exportedvalue = $question->options->$field;
            if (!empty($exportedvalue) && htmlspecialchars($exportedvalue) != $exportedvalue) {
                $exportedvalue = '<![CDATA[' . $exportedvalue . ']]>';
            }
            $expout .= "    <$field>{$exportedvalue}</$field>\n";
        }*/
		// Voma edit start
        foreach ($extraquestionfields as $field) {
            $exportedvalue = $question->options->$field;
            if ($field == "usecase") {
	            $expout .= "    <$field>{$exportedvalue}</$field>\n";
            }
		// Voma edit end
        }

        foreach ($question->options->answers as $answer) {
        	$percent = 100 * $answer->fraction;
            $expout .= "    <answer fraction=\"$percent\">\n";
            $expout .= $format->writetext($answer->answer, 3, false);
            $expout .= "      <feedback format=\"html\">\n";
            $expout .= $format->writetext($answer->feedback, 4, false);
            $expout .= "      </feedback>\n";
            $expout .= "    </answer>\n";
        }
        return $expout;   	
    }
	// Voma add end
}
