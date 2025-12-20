<?php
// FILE: blocks/ai_assistant/block_ai_assistant.php

defined('MOODLE_INTERNAL') || die();

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

        $PAGE->requires->css('/blocks/ai_assistant/styles.css');

        // Agent configuration (existing behaviour).
        $defaultagentkey = 'CSIRChemicalSciences';
        $agentkey = $defaultagentkey;

        if (!empty($this->config) && !empty($this->config->agent_key)) {
            $agentkey = $this->config->agent_key;
        } else {
            $agentkey = get_config('block_ai_assistant', 'agent_key') ?: $agentkey;
        }

        // mainsubjectkey (existing behaviour).
        $defaultsubject = 'chemistry';
        $mainsubjectkey = $defaultsubject;

        if (!empty($this->config) && !empty($this->config->mainsubjectkey)) {
            $mainsubjectkey = $this->config->mainsubjectkey;
        } else {
            $mainsubjectkey = get_config('block_ai_assistant', 'mainsubjectkey') ?: $mainsubjectkey;
        }

        // AJAX endpoints.
        $historyajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_ajax.php');
        $syllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/syllabus_ajax.php');
        $askagentajaxurl = new moodle_url('/blocks/ai_assistant/ajax/ask_agent_ajax.php');
        $mcqajaxurl = new moodle_url('/blocks/ai_assistant/ajax/mcq_ajax.php');
        $websearchajaxurl = new moodle_url('/blocks/ai_assistant/ajax/websearch_ajax.php');
        $youtubesummarizeajaxurl = new moodle_url('/blocks/ai_assistant/ajax/youtube_summarize_ajax.php');
        $historywidgetajaxurl = new moodle_url('/blocks/ai_assistant/ajax/history_widget_ajax.php');
        $getsyllabusajaxurl = new moodle_url('/blocks/ai_assistant/ajax/syllabus_ajax.php'); // deprecated get_syllabus_ajax.php.
        $mcqwidgetajaxurl = new moodle_url('/blocks/ai_assistant/ajax/mcq_widget_ajax.php');

        // Detect page context (kept).
        $pagesubject = '';
        $pagetopic = '';
        $cm = get_coursemodule_from_id('page', optional_param('id', 0, PARAM_INT));
        if ($cm) {
            $pagerecord = $DB->get_record('page', ['id' => $cm->instance]);
            if ($pagerecord) {
                $pagesubject = $this->extract_subject_from_page($pagerecord);
                $pagetopic = $this->extract_topic_from_page($pagerecord);
            }
        }

        $templatecontext = [
            'agentkey' => $agentkey,
            'mainsubjectkey' => $mainsubjectkey,
            'blockinstanceid' => $this->instance->id,
            'sesskey' => sesskey(),
            'courseid' => $COURSE->id,
            'historyajaxurl' => $historyajaxurl->out(false),
            'syllabusajaxurl' => $syllabusajaxurl->out(false),
            'askagentajaxurl' => $askagentajaxurl->out(false),
            'mcqajaxurl' => $mcqajaxurl->out(false),
            'websearchajaxurl' => $websearchajaxurl->out(false),
            'youtubesummarizeajaxurl' => $youtubesummarizeajaxurl->out(false),
            'historywidgetajaxurl' => $historywidgetajaxurl->out(false),
            'getsyllabusajaxurl' => $getsyllabusajaxurl->out(false),
            'mcqwidgetajaxurl' => $mcqwidgetajaxurl->out(false),
            'pagesubject' => $pagesubject,
            'pagetopic' => $pagetopic,
        ];

        $this->content->text = $OUTPUT->render_from_template('block_ai_assistant/main', $templatecontext);
        return $this->content;
    }

/**
 * Persist config + syllabus JSON into DB (one row per block instance),
 * and invalidate the syllabus cache for this block instance.
 *
 * Notes for auditing:
 * - Keeps Moodle block config for agent_key + mainsubjectkey as normal.
 * - Stores the large syllabus JSON in DB table block_ai_assistant_syllabus.
 * - Cache invalidation is by blockinstanceid to avoid collisions across courses.
 */
public function instance_config_save($data, $nolongerused = false) {
    global $DB;

    // 1) Save normal block config values first (agent_key, mainsubjectkey, syllabusjson field etc).
    $result = parent::instance_config_save($data, $nolongerused);

    // 2) If upgrade wasn't run yet, don't fatal.
    if (!$DB->get_manager()->table_exists('block_ai_assistant_syllabus')) {
        return $result;
    }

    $blockinstanceid = (int)$this->instance->id;

    // Form field "config_syllabusjson" becomes "$data->syllabusjson".
    $syllabusjson = trim((string)($data->syllabusjson ?? ''));

    // Do not overwrite existing syllabus with empty text (safe default).
    // If you want "clear syllabus" functionality later, handle it explicitly.
    if ($syllabusjson === '') {
        return $result;
    }

    // 3) Validate JSON before saving (prevents runtime failures in syllabus_ajax.php).
    json_decode($syllabusjson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('invalidjson', 'error', '', null, json_last_error_msg());
    }

    $now = time();

    // IMPORTANT: In your code agent_key/mainsubjectkey live as config fields and are available here
    // as $data->agent_key and $data->mainsubjectkey (config_ prefix stripped).
    $agentkey = (string)($data->agent_key ?? '');
    $mainsubjectkey = (string)($data->mainsubjectkey ?? '');

    // 4) Upsert row by blockinstanceid (enforces "one syllabus per course block").
    $existing = $DB->get_record('block_ai_assistant_syllabus', ['blockinstanceid' => $blockinstanceid], '*', IGNORE_MISSING);

    if ($existing) {
        $existing->agent_key = $agentkey;
        $existing->mainsubjectkey = $mainsubjectkey;
        $existing->syllabus_json = $syllabusjson;
        $existing->timemodified = $now;

        // Keep original timecreated unchanged.
        if (empty($existing->timecreated)) {
            $existing->timecreated = $now;
        }

        $DB->update_record('block_ai_assistant_syllabus', $existing);
    } else {
        $record = (object)[
            'blockinstanceid' => $blockinstanceid,
            'agent_key' => $agentkey,
            'mainsubjectkey' => $mainsubjectkey,
            'syllabus_json' => $syllabusjson,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record('block_ai_assistant_syllabus', $record);
    }

    // 5) Invalidate the cached syllabus JSON for this block instance.
    // Requires: blocks/ai_assistant/db/caches.php with definition 'syllabus'.
    $cache = cache::make('block_ai_assistant', 'syllabus');
    $cache->delete('blockid_' . $blockinstanceid);

    return $result;
}


    // Keep your existing helper methods (placeholders here).
    private function extract_subject_from_page($pagerecord) { return ''; }
    private function extract_topic_from_page($pagerecord) { return ''; }
}
