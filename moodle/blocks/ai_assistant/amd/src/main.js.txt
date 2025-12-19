// FILE: moodle/blocks/ai_assistant/amd/src/main.js
// UPDATE: Now collects all `data-*` attributes and passes them to the event.
define(['jquery', 'core/log'], function($, log) {
    log.setLevel('debug');

    return {
        init: function() {
            log.debug('AI Assistant: Page link listener initialized.');

            $(document).on('click', 'a[data-function]', function(e) {
                e.preventDefault();
                const link = $(this);
                const func = link.data('function');

                if (!func) {
                    log.warn('AI Assistant: No data-function found on clicked element.');
                    return;
                }

                // Collect all data attributes from the link.
                const detail = {};
                $.each(this.dataset, function(key, value) {
                    detail[key] = value;
                });

                log.debug(`AI Assistant: Dispatching event for function "${func}"`, detail);

                try {
                    const event = new CustomEvent('ai-assistant-send', { detail: detail });
                    document.dispatchEvent(event);
                } catch (err) {
                    log.error('AI Assistant: Failed to dispatch event.', err);
                }
            });
        }
    };
});
