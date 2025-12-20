// FILE: blocks/ai_assistant/amd/src/history.js
// DESCRIPTION: History page filters + LaTeX/Markdown rendering.

define([], function() {
    'use strict';

    /**
     * Initialise history page JS.
     *
     * @param {Array} syllabusData     Parsed syllabus JSON.
     * @param {Object} currentFilters  {subject, topic, lesson}.
     */
    const init = function(syllabusData, currentFilters) {
        if (!Array.isArray(syllabusData)) {
            // eslint-disable-next-line no-console
            console.error('Invalid syllabusData passed to history.init');
            return;
        }
        currentFilters = currentFilters || {};

        const subjectDropdown = document.getElementById('filter-subject');
        const topicDropdown = document.getElementById('filter-topic');
        const lessonDropdown = document.getElementById('filter-lesson');

        if (!subjectDropdown || !topicDropdown || !lessonDropdown) {
            // eslint-disable-next-line no-console
            console.warn('History filter dropdowns not found');
        }

        // ---------- Helper: find subject for a given topic ----------
        const findSubjectForTopic = (topicKey) => {
            if (!topicKey) {
                return null;
            }
            for (const subject of syllabusData) {
                if (subject.topics && subject.topics.some(t => t.topic_key === topicKey)) {
                    return subject.subject_key;
                }
            }
            return null;
        };

        // ---------- Populate Subjects ----------
        const populateSubjects = () => {
            if (!syllabusData || !subjectDropdown) {
                return;
            }

            const preselectedSubject =
                findSubjectForTopic(currentFilters.topic) || currentFilters.subject;

            syllabusData.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.subject_key;
                option.textContent = subject.subject;
                if (subject.subject_key === preselectedSubject) {
                    option.selected = true;
                }
                subjectDropdown.appendChild(option);
            });

            if (subjectDropdown.value) {
                subjectDropdown.dispatchEvent(new Event('change'));
            }
        };

        // ---------- Populate Topics ----------
        const populateTopics = (selectedSubjectKey) => {
            if (!topicDropdown || !lessonDropdown) {
                return;
            }

            topicDropdown.innerHTML = '<option value="">' +
                M.util.get_string('all_topics', 'block_ai_assistant') +
                '</option>';
            lessonDropdown.innerHTML = '<option value="">' +
                M.util.get_string('all_lessons', 'block_ai_assistant') +
                '</option>';

            topicDropdown.disabled = true;
            lessonDropdown.disabled = true;

            if (!selectedSubjectKey) {
                return;
            }

            const subjectData = syllabusData.find(s => s.subject_key === selectedSubjectKey);
            if (!subjectData || !subjectData.topics) {
                return;
            }

            subjectData.topics.forEach(topic => {
                const option = document.createElement('option');
                option.value = topic.topic_key;
                option.textContent = topic.topic;
                if (topic.topic_key === currentFilters.topic) {
                    option.selected = true;
                }
                topicDropdown.appendChild(option);
            });

            topicDropdown.disabled = false;

            if (topicDropdown.value) {
                topicDropdown.dispatchEvent(new Event('change'));
            }
        };

        // ---------- Populate Lessons (string or object) ----------
        const populateLessons = (selectedSubjectKey, selectedTopicKey) => {
            if (!lessonDropdown) {
                return;
            }

            lessonDropdown.innerHTML = '<option value="">' +
                M.util.get_string('all_lessons', 'block_ai_assistant') +
                '</option>';
            lessonDropdown.disabled = true;

            if (!selectedSubjectKey || !selectedTopicKey) {
                return;
            }

            const subjectData = syllabusData.find(s => s.subject_key === selectedSubjectKey);
            if (!subjectData) {
                return;
            }

            const topicData = subjectData.topics.find(t => t.topic_key === selectedTopicKey);
            if (!topicData || !topicData.lessons || topicData.lessons.length === 0) {
                return;
            }

            // eslint-disable-next-line no-console
            console.log('Found', topicData.lessons.length, 'lessons for topic', selectedTopicKey);

            topicData.lessons.forEach((lesson, index) => {
                const option = document.createElement('option');

                if (typeof lesson === 'string') {
                    option.value = lesson;
                    option.textContent = lesson;
                    if (lesson === currentFilters.lesson) {
                        option.selected = true;
                    }
                    // eslint-disable-next-line no-console
                    console.log((index + 1) + '. ' + lesson + ' (string)');
                } else {
                    option.value = lesson.lesson_key;
                    option.textContent = lesson.lesson;
                    if (lesson.lesson_key === currentFilters.lesson) {
                        option.selected = true;
                    }
                    // eslint-disable-next-line no-console
                    console.log(
                        (index + 1) + '. ' + lesson.lesson + ' [' + lesson.lesson_key + '] (object)'
                    );
                }

                lessonDropdown.appendChild(option);
            });

            lessonDropdown.disabled = false;
            // eslint-disable-next-line no-console
            console.log('Lesson dropdown populated and enabled');
        };

        // ---------- Cascading dropdown event handlers ----------
        if (subjectDropdown) {
            subjectDropdown.addEventListener('change', () => {
                populateTopics(subjectDropdown.value);
            });
        }

        if (topicDropdown) {
            topicDropdown.addEventListener('change', () => {
                populateLessons(subjectDropdown.value, topicDropdown.value);
            });
        }

        // ---------- Initialise dropdowns ----------
        populateSubjects();

        // ---------- LaTeX + Markdown rendering on history messages ----------
        const md = window.markdownit({
            html: true,
            linkify: true,
            typographer: true
        });

        const renderLatex = (text) => {
            // Display math: \[ ... \]
            text = text.replace(/\\\\\\[(\\s|\\S)*?\\\\\\]/g, function(match, latex) {
                try {
                    return katex.renderToString(latex.trim(), {
                        displayMode: true,
                        throwOnError: false
                    });
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX display error:', e);
                    return match;
                }
            });

            // Inline math: \( ... \)
            text = text.replace(/\\\\\\((\\s|\\S)*?\\\\\\)/g, function(match, latex) {
                try {
                    return katex.renderToString(latex.trim(), {
                        displayMode: false,
                        throwOnError: false
                    });
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX inline error:', e);
                    return match;
                }
            });

            return text;
        };

        document
            .querySelectorAll('.ai-assistant-message-content[data-is-rendered="false"]')
            .forEach(function(element) {
                const rawContent = element.innerHTML.trim();

                const latexRendered = renderLatex(rawContent);
                const finalHtml = md.render(latexRendered);

                element.innerHTML = finalHtml;
                element.setAttribute('data-is-rendered', 'true');
            });
    };

    return {
        init: init
    };
});
