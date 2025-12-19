<?php
// FILE: moodle/blocks/ai_assistant/classes/output/page_renderer.php
// PURPOSE: This is a SECOND renderer, specifically for our standalone pages.
// Its unique name ('page_renderer') avoids conflicts with the block's main renderer.

defined('MOODLE_INTERNAL') || die();

// It can extend the base renderer. Its only job is to register the template path.
class block_ai_assistant_page_renderer extends core_renderer {

}