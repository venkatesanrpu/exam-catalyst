<?php
// FILE: moodle/blocks/ai_assistant/block_ai_assistant.php
// UPDATE: Reads the new 'mainsubjectkey' from the block's configuration and passes it
// to the template. Also updates the history link to be context-aware.

defined('MOODLE_INTERNAL') || die();

class block_ai_assistant extends block_base {
    public function init() {
        $this->title = get_string('pluginname', 'block_ai_assistant');
    }

    public function get_content() {
        global $PAGE, $USER, $DB, $COURSE;

        if ($this->content !== null) { return $this->content; }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Check for both required configuration settings now.
        if (empty($this->config->agent_key) || empty($this->config->mainsubjectkey)) {
            $this->content->text = get_string('not_configured', 'block_ai_assistant');
            return $this->content;
        }

        // --- Enhanced Context Detection (No changes here) ---
        $pagesubject = '';
        $pagetopic = '';
        if ($PAGE->cm) {
            $pagetopic = $PAGE->cm->name;
            $modinfo = get_fast_modinfo($COURSE);
            $sectioninfo = $modinfo->get_section_info($PAGE->cm->sectionnum);
            if ($sectioninfo && !empty($sectioninfo->name)) {
                $pagesubject = $sectioninfo->name;
            }
        } else {
            $pagesubject = $COURSE->fullname;
            $pagetopic = get_string('general_inquiry', 'block_ai_assistant');
        }

        // --- Prepare data for the template ---
        $data = [
            'agentkey'          => $this->config->agent_key,
            'mainsubjectkey'    => $this->config->mainsubjectkey, // <-- NEW: Pass the subject key
            'sesskey'           => $USER->sesskey,
            'courseid'          => $COURSE->id,
            'pagesubject'       => $pagesubject,
            'pagetopic'         => $pagetopic,
            'historyajaxurl'    => (new moodle_url('/blocks/ai_assistant/ajax.php'))->out(false),
            'askagentajaxurl'   => (new moodle_url('/blocks/ai_assistant/ask_agent_ajax.php'))->out(false),
            'mcqajaxurl'        => (new moodle_url('/blocks/ai_assistant/mcq_ajax.php'))->out(false),
            'websearchajaxurl'  => (new moodle_url('/blocks/ai_assistant/websearch_ajax.php'))->out(false),
            'youtubesummarizeajaxurl' => (new moodle_url('/blocks/ai_assistant/youtube_summarize_ajax.php'))->out(false),
            'syllabusajaxurl' => (new moodle_url('/blocks/ai_assistant/get_syllabus_ajax.php'))->out(false)
        ];

        // --- Render Template and Footer ---
        $this->content->text = $PAGE->get_renderer('block_ai_assistant')->render_from_template('block_ai_assistant/main', $data);

        // --- NEW: Update the history URL to be context-aware ---
        $historyurl = new moodle_url('/blocks/ai_assistant/history.php', [
            'courseid' => $COURSE->id,
            'mainsubject' => $this->config->mainsubjectkey
        ]);
        $this->content->footer = html_writer::link($historyurl, get_string('view_history', 'block_ai_assistant'));

        // --- Load JavaScript (No changes here) ---
        $PAGE->requires->js_call_amd('block_ai_assistant/main', 'init');
        $PAGE->requires->css('/blocks/ai_assistant/styles.css');

        return $this->content;
    }

    public function instance_allow_config() { return true; }
    public function hide_header() { return true; }
}