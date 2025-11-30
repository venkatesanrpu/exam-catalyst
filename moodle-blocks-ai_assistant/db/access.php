<?php
// FILE: moodle/blocks/ai_assistant/db/access.php
// PURPOSE: Defines the capabilities for the AI Assistant block.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Capability to add the block to a course page.
    'block/ai_assistant:addinstance' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks'
    ],

    // Capability to add the block to the user's own "My Moodle" page.
    'block/ai_assistant:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/my:manageblocks'
    ],
];
