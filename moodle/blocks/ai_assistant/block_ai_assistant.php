<?php
// ...existing code...
// FILE: blocks/ai_assistant/block_ai_assistant.php


class block_ai_assistant extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_ai_assistant');
    }

    public function get_content() {
        global $COURSE, $DB, $OUTPUT, $PAGE;

        
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';
		
		// ✅ LOAD CUSTOM CSS
		$PAGE->requires->css('/blocks/ai_assistant/styles.css');
		

        // Get agent configuration
		$defaultagentkey = 'CSIRChemicalSciences';
		$agentkey = $defaultagentkey;
		
		if (!empty($this->config) && !empty($this->config->agent_key)) {
			$agentkey = $this->config->agent_key;
		} else {
			$agentkey = get_config('block_ai_assistant', 'agent_key') ?: $agentkey;
		}

		$defaultsubject = 'chemistry';
		$mainsubjectkey = $defaultsubject;

		if (!empty($this->config) && !empty($this->config->mainsubjectkey)) {
			$mainsubjectkey = $this->config->mainsubjectkey;
		} else {
			$mainsubjectkey = get_config('block_ai_assistant', 'mainsubjectkey') ?: $mainsubjectkey;
		}	
        // Define AJAX endpoints
        $historyajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_ajax.php');
        $syllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/syllabus_ajax.php');
        $askagentajaxurl = new moodle_url('/blocks/ai_assistant/ajax/ask_agent_ajax.php');
        $mcqajaxurl = new moodle_url('/blocks/ai_assistant/ajax/mcq_ajax.php');
        $websearchajaxurl = new moodle_url('/blocks/ai_assistant/ajax/websearch_ajax.php');
        $youtubesummarizeajaxurl = new moodle_url('/blocks/ai_assistant/ajax/youtube_summarize_ajax.php');
		$historywidgetajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_widget_ajax.php');
		$getsyllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/syllabus_ajax.php'); //get_syllabus_ajax.php is deprecated
		$getmcqwidgetajaxurl = new moodle_url('/blocks/ai_assistant/ajax/mcq_widget_ajax.php');


        // Detect page context
        $pagesubject = '';
        $pagetopic = '';
        
        $context = context_course::instance($COURSE->id);
        $cm = get_coursemodule_from_id('page', optional_param('id', 0, PARAM_INT));
        
        if ($cm) {
            $pagerecord = $DB->get_record('page', ['id' => $cm->instance]);
            if ($pagerecord) {
                $pagesubject = $this->extract_subject_from_page($pagerecord);
                $pagetopic = $this->extract_topic_from_page($pagerecord);
            }
        }

        // Template context
        $templatecontext = [
            'agentkey' => $agentkey,
            'mainsubjectkey' => $mainsubjectkey,
			'blockinstanceid' => $this->instance->id, // NEW: used by JS to load syllabus for this block instance.
            'sesskey' => sesskey(),
            'courseid' => $COURSE->id,
            'historyajaxurl' => $historyajaxurl->out(false),
            'syllabusajaxurl' => $syllabusajaxurl->out(false),
            'askagentajaxurl' => $askagentajaxurl->out(false),
            'mcqajaxurl' => $mcqajaxurl->out(false),
            'websearchajaxurl' => $websearchajaxurl->out(false),           // ✅ FIXED
            'youtubesummarizeajaxurl' => $youtubesummarizeajaxurl->out(false), // ✅ FIXED
			'historywidgetajaxurl' => $historywidgetajaxurl->out(false), // ✅ FIX FOR HISTORY WIDGET AJAX URL
			'getsyllabusajaxurl' => $getsyllabusajaxurl->out(false),
			'mcqwidgetajaxurl' => $getmcqwidgetajaxurl->out(false), // ✅ FIX FOR MCQ WIDGET AJAX URL
            'pagesubject' => $pagesubject,
            'pagetopic' => $pagetopic
        ];

        $this->content->text = $OUTPUT->render_from_template('block_ai_assistant/main', $templatecontext);
        return $this->content;
    }

    /**
     * Save instance config + syllabus file (File API).
     * Enforces: only one syllabus JSON per block instance (replace on upload).
     */
    public function instance_config_save($data, $nolongerused = false) {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php'); // file_save_draft_area_files()

        // Save standard config fields (agent_key, mainsubjectkey, etc.).
        $result = parent::instance_config_save($data, $nolongerused);

        // Block context is where files should live for per-instance storage.
        $context = context_block::instance($this->instance->id);

        $fileoptions = [
            'maxfiles' => 1,
            'subdirs' => 0,
            'accepted_types' => ['.json'],
        ];

        $fs = get_file_storage();

        // Enforce ONE file always: delete the existing area before saving the new draft.
        // (If user didn't upload a new file, the existing file is still present in the draft area
        // because edit_form.php loads it via file_prepare_draft_area().)
        $fs->delete_area_files($context->id, 'block_ai_assistant', 'syllabus', 0);

        // The "config_syllabusfile" field becomes "$data->syllabusfile" here (config_ prefix removed).
        $draftitemid = $data->syllabusfile ?? 0;

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'block_ai_assistant',
            'syllabus',
            0,
            $fileoptions
        );

        // Optional: rename the stored file to "<mainsubjectkey>.json" for consistent auditing/archiving.
        // This keeps your "mainsubjectkey" meaningful even though runtime loading uses blockid.
        if (!empty($data->mainsubjectkey)) {
            $desiredname = clean_param($data->mainsubjectkey, PARAM_ALPHANUMEXT) . '.json';

            $files = $fs->get_area_files(
                $context->id,
                'block_ai_assistant',
                'syllabus',
                0,
                'timemodified DESC',
                false // Exclude directories.
            );

            if (!empty($files)) {
                $file = reset($files);
                if ($file && $file->get_filename() !== $desiredname) {
                    // Rename within the same filepath.
                    $file->rename('/', $desiredname);
                }
            }
        }

        return $result;
    }
}