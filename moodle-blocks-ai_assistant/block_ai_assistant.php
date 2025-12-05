<?php
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
        $agentkey = get_config('block_ai_assistant', 'agent_config_key') ?: 'CSIRChemicalSciences';
        $mainsubjectkey = get_config('block_ai_assistant', 'main_subject_key') ?: 'chemistry';

        // Define AJAX endpoints
        $historyajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_ajax.php');
        $syllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/syllabus_ajax.php');
        $askagentajaxurl = new moodle_url('/blocks/ai_assistant/ajax/ask_agent_ajax.php');
        $mcqajaxurl = new moodle_url('/blocks/ai_assistant/ajax/mcq_ajax.php');
        $websearchajaxurl = new moodle_url('/blocks/ai_assistant/ajax/websearch_ajax.php');
        $youtubesummarizeajaxurl = new moodle_url('/blocks/ai_assistant/ajax/youtube_summarize_ajax.php');
		$historywidgetajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_widget_ajax.php');
		$getsyllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/get_syllabus_ajax.php');
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

    private function extract_subject_from_page($pagerecord) {
        // Implementation depends on your page structure
        return '';
    }

    private function extract_topic_from_page($pagerecord) {
        // Implementation depends on your page structure
        return '';
    }
}
