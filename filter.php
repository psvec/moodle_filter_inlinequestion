<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/outputrenderers.php');

require_once($CFG->dirroot.'/question/engine/lib.php');
require_once($CFG->dirroot.'/question/engine/bank.php');

/**
Format: {ILQ:<parameters>}

Parameters are in format <parameter name>=<parameter values>; Last semicolon is not necessary.

Parameters:

- id: ID of question or IDs of questions separated by comma
- marks: The the mark and/or the maximum available mark for this question be visible?
	- 0 - don't show
	- 2 - max only
	- 3 - max and marks (default)
- flags: Should the flag this question UI element be visible?
	- 0 - hidden
	- 1 - visible (default)
-readonly: whether the question should be displayed as a read-only review, or in an active state where you can change the answer.
	- 0 - false (default)
	- 1 - true
*/

class filter_inlinequestions extends moodle_text_filter {
	private $scrollpos = 0;

	public function filter($text, array $options = array()) {
		// we just process strings
		if(!is_string($text)) {
			return $text;
		}

		// check for our tag name
		//if(strpos($text, '{ILQ:') === FALSE && strpos($text, '{ilq')) {
		if(!preg_match('/\{ilq:/i', $text)) {
			return $text;
		}

		// call callback for searched pattern
		$new_text = preg_replace_callback('/{ILQ:.*?}/is', array($this, 'ilq_filter_callback'), $text);

		if($new_text == NULL) {
			// nothing filtered
			return $text;
		}

		return $new_text;
	}

	private function ilq_filter_callback($matches) {
		global $DB;

		// returned string, generated later
		$return_html = '';

		$match = $matches[0];

		// get parsed options from match
		$options = $this->ilq_parse_match($match);
		
		// generate question options
		$question_options = $this->ilq_set_question_options($options);

		// create array from options['id'] if it is not
		if(!is_array($options['id'])) {
			$options['id'] = array($options['id']);
		}

		foreach($options['id'] as $question_id) {
			$usage_id = optional_param('usage_id', false, PARAM_INT);
			$qid = optional_param('question_id', false, PARAM_INT);
			
			// if form was submited and we have all necessary parameters
			if($usage_id && $qid && $qid == $question_id) {
				$transaction = $DB->start_delegated_transaction();

				// load question usage
				$quba = question_engine::load_questions_usage_by_activity($usage_id);
				$quba->process_all_actions(time());

				// save questions usage
				question_engine::save_questions_usage_by_activity($quba);

				$transaction->allow_commit();
			} else {
				// load question, if it not exists, continue
				try {
					$question = question_bank::load_question($question_id);
				} catch(\Exception $e) {
					continue;
				}

				// get context for usage
				$context = $this->ilq_get_context($question);

				// check for permissions
				if(!has_capability('moodle/question:viewall', $context)) {
					continue;
				}

				// create questions usage
				$quba = question_engine::make_questions_usage_by_activity('filter_inlinequestions', $context);
				$quba->set_preferred_behaviour('adaptivenopenalty');

				$quba->add_question($question);

				// prepare for rendering
				$quba->start_all_questions();

				// save questions usage
				question_engine::save_questions_usage_by_activity($quba);
			}

			// render questions
			$return_html .= html_writer::start_tag('form', array('method' => 'post', 'action' => '', 'enctype' => 'multipart/form-data', 'id' => 'responseform'));
			$return_html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
			$return_html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'slots', 'value' => 1));
			$return_html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'scrollpos', 'value' => 'q'.$this->scrollpos, 'id' => 'scrollpos'));
			$return_html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'usage_id', 'value' => $quba->get_id()));
			$return_html .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'question_id', 'value' => $question_id));

			$return_html .= $quba->render_question(1, $question_options);

			$return_html .= html_writer::end_tag('form');
			
			$this->scrollpos++;
		}

		return $return_html;
	}

	private function ilq_parse_match($match) {
		// remove all spaces
		$match = str_replace(' ', '', $match);

		// remove begining string and end string
		$match = preg_replace('/^\{ILQ:/i', '', rtrim($match, '}'));

		// split string by ;
		$parts = explode(';', $match);

		$options = array();
		// every part split by =
		foreach($parts as $part) {
			if(empty($part) || $part == NULL) {
				continue;
			}

			list($idx, $params) = explode('=', $part);

			// if there are more than one parameter, return array, else string
			$params_array = explode(',', $params);
			if(count($params_array) > 1) {
				$options[$idx] = $params_array;
			} else {
				$options[$idx] = $params;
			}	
		}

		return $options;
	}

	private function ilq_set_question_options($options) {
		$qo = new question_display_options();
		
		if(isset($options['marks'])) {
			switch($options['marks']) {
				case 0:
					$qo->marks = question_display_options::HIDDEN;
					break;

				case 2:
					$qo->marks = question_display_options::MAX_ONLY;
					break;

				case 3:
					$qo->marks = question_display_options::MARK_AND_MAX;
					break;
			}
		}

		if(isset($options['flags'])) {
			switch($options['flags']) {
				case 0:
					$qo->flags = question_display_options::HIDDEN;
					break;

				case 1:
					$qo->flags = question_display_options::VISIBLE;
					break;
			}
		}

		if(isset($options['readonly'])) {
			
			switch($options['readonly']) {
				case 0:
					$qo->readonly = false;
					break;

				case 1:
					$qo->readonly = true;
					break;
			}
		}
		
		return $qo;
	}

	private function ilq_get_context($question) {
		global $DB;
		global $PAGE;

		if ($cmid = optional_param('cmid', 0, PARAM_INT)) {
			$cm = get_coursemodule_from_id(false, $cmid);
			require_login($cm->course, false, $cm);
			$context = context_module::instance($cmid);

		} else if ($courseid = optional_param('courseid', 0, PARAM_INT)) {
			require_login($courseid);
			$context = context_course::instance($courseid);

		} else {
			require_login();
			$category = $DB->get_record('question_categories', array('id' => $question->category), '*', MUST_EXIST);
			$context = context::instance_by_id($category->contextid);
			$PAGE->set_context($context);
		}

		return $context;
	}
}
