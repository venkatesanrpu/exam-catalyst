// FILE: blocks/ai_assistant/amd/src/history_widget.js
// PURPOSE: History modal within the main widget (subject/topic/lesson + General + MCQ formatting).

define([], function() {
    'use strict';

    const init = function(config) {
        if (window.aiAssistantHistoryWidgetInitialized) {
            // eslint-disable-next-line no-console
            console.log('⚠️ History Widget already initialized');
            return;
        }

        const courseid = config.courseid;
        const sesskey = config.sesskey;
        const historyAjaxUrl = config.historywidgetajaxurl;

        const modal = document.getElementById('ai-assistant-history-modal');
        const closeButton = document.getElementById('ai-assistant-history-close');
        const historyBody = document.getElementById('ai-assistant-history-body');
        const subjectSelect = document.getElementById('history-subject');
        const topicSelect = document.getElementById('history-topic');
        const lessonSelect = document.getElementById('history-lesson');
        const loadButton = document.getElementById('history-load-button');
        const pagination = document.getElementById('ai-assistant-history-pagination');

        if (!modal || !closeButton || !historyBody) {
            // eslint-disable-next-line no-console
            console.error('❌ History Widget: Required DOM elements not found');
            return;
        }

        let currentPage = 1;
        let syllabusData = null;

        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        };

        const renderLatex = (text) => {
            if (!window.katex) {
                // eslint-disable-next-line no-console
                console.warn('KaTeX not loaded');
                return {text, placeholders: new Map()};
            }
            const latexPlaceholders = new Map();

            text = text.replace(/\\\\\\[(\\s|\\S)*?\\\\\\]/g, (match, latex) => {
                try {
                    const rendered = katex.renderToString(latex.trim(), {
                        displayMode: true,
                        throwOnError: false
                    });
                    const placeholder = `@@KATEX_DISPLAY_${latexPlaceholders.size}@@`;
                    latexPlaceholders.set(placeholder, rendered);
                    return placeholder;
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX display error:', e);
                    return match;
                }
            });

            text = text.replace(/\\\\\\((\\s|\\S)*?\\\\\\)/g, (match, latex) => {
                try {
                    const rendered = katex.renderToString(latex.trim(), {
                        displayMode: false,
                        throwOnError: false
                    });
                    const placeholder = `@@KATEX_INLINE_${latexPlaceholders.size}@@`;
                    latexPlaceholders.set(placeholder, rendered);
                    return placeholder;
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX inline error:', e);
                    return match;
                }
            });

            return {text, placeholders: latexPlaceholders};
        };

        const renderLatexInHTML = (htmlString) => {
            if (!window.katex) {
                // eslint-disable-next-line no-console
                console.warn('⚠️ KaTeX not loaded for history widget');
                return htmlString;
            }
            let rendered = htmlString;

            rendered = rendered.replace(/\\\\\\[(\\s|\\S)*?\\\\\\]/g, (match, latex) => {
                try {
                    return katex.renderToString(latex.trim(), {
                        displayMode: true,
                        throwOnError: false,
                        output: 'html'
                    });
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX display error:', e);
                    return match;
                }
            });

            rendered = rendered.replace(/\\\\\\((\\s|\\S)*?\\\\\\)/g, (match, latex) => {
                try {
                    return katex.renderToString(latex.trim(), {
                        displayMode: false,
                        throwOnError: false,
                        output: 'html'
                    });
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('KaTeX inline error:', e);
                    return match;
                }
            });

            return rendered;
        };

        const formatMCQIfNeeded = (botresponse, functioncalled) => {
            if (functioncalled !== 'mcq_widget') {
                return null;
            }
            try {
                let mcqData = botresponse;
                if (typeof botresponse === 'string') {
                    mcqData = JSON.parse(botresponse);
                }
                if (!mcqData.questions || !Array.isArray(mcqData.questions)) {
                    return null;
                }

                const level = mcqData.metadata?.level || 'Unknown';
                const questionCount = mcqData.questions.length;

                let html = '<div class="mcq-history-formatted">';
                html += `
                    <div class="mcq-history-header">
                        <strong>📚 ${escapeHtml(level.toUpperCase())} MCQ Practice Set</strong>
                        <span class="mcq-count-badge">${questionCount} Questions</span>
                    </div>
                `;

                mcqData.questions.forEach((q, idx) => {
                    html += '<div class="mcq-history-question">';
                    html += `<div class="mcq-q-number">Question ${idx + 1}</div>`;

                    const renderedQuestion = renderLatexInHTML(q.question);
                    html += `<div class="mcq-q-text">${renderedQuestion}</div>`;

                    html += '<div class="mcq-q-options">';
                    q.options.forEach((opt, optIdx) => {
                        const letter = String.fromCharCode(65 + optIdx);
                        const isCorrect = letter === q.correct;
                        const correctClass = isCorrect ? 'mcq-correct-option' : '';
                        const correctMarker = isCorrect
                            ? '<span class="correct-marker">✓</span>'
                            : '';

                        const renderedOption = renderLatexInHTML(opt);
                        html += `
                            <div class="mcq-q-option ${correctClass}">
                                <strong>${letter})</strong> ${renderedOption} ${correctMarker}
                            </div>
                        `;
                    });
                    html += '</div>';

                    const renderedExplanation = renderLatexInHTML(q.explanation);
                    html += `
                        <div class="mcq-q-explanation">
                            <strong>💡 Explanation:</strong> ${renderedExplanation}
                        </div>
                    `;
                    html += '</div>';
                });

                const mcqDataJson = JSON.stringify(mcqData)
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');

                html += `
                    <button class="mcq-practice-again-btn"
                        onclick="reopenMCQPractice(this)"
                        data-mcq-data='${mcqDataJson}'>
                        🔄 Practice These Questions Again
                    </button>
                `;
                html += '</div>';

                return html;
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('Failed to format MCQ response:', e);
                return null;
            }
        };

        window.reopenMCQPractice = function(button) {
            try {
                const mcqDataJson = button.getAttribute('data-mcq-data');
                const mcqData = JSON.parse(mcqDataJson);

                if (!mcqData.questions || mcqData.questions.length === 0) {
                    // eslint-disable-next-line no-alert
                    alert('No questions found');
                    return;
                }

                window.closeHistoryWidget();

                if (typeof window.openMCQWidgetWithData === 'function') {
                    window.openMCQWidgetWithData(mcqData);
                } else {
                    // eslint-disable-next-line no-console
                    console.warn('MCQ widget not available for replay');
                    // eslint-disable-next-line no-alert
                    alert('MCQ practice widget is not available. Please refresh the page.');
                }
            } catch (e) {
                // eslint-disable-next-line no-console
                console.error('Failed to reopen MCQ practice:', e);
                // eslint-disable-next-line no-alert
                alert('Failed to load MCQ practice');
            }
        };

        const markdown = window.markdownit
            ? window.markdownit({html: true, breaks: true})
            : null;

        const populateSubjects = () => {
            if (!syllabusData || !Array.isArray(syllabusData)) {
                // eslint-disable-next-line no-console
                console.error('❌ Invalid syllabus data');
                subjectSelect.innerHTML = '<option value="">No subjects available</option>';
                return;
            }

            subjectSelect.innerHTML = '<option value="">-- Select Subject --</option>';

            const generalOption = document.createElement('option');
            generalOption.value = 'general';
            generalOption.textContent =
                '📝 General (Unfiltered Conversations)';
            subjectSelect.appendChild(generalOption);

            const separator = document.createElement('option');
            separator.disabled = true;
            separator.textContent = '────────────────────';
            subjectSelect.appendChild(separator);

            syllabusData.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.subject_key;
                option.textContent = subject.subject;
                subjectSelect.appendChild(option);
            });
        };

        const fetchHistory = async (subjectKey, topicKey, lesson) => {
            historyBody.innerHTML = '<div class="loading">⏳ Loading conversations...</div>';

            try {
                const payload = {
                    sesskey: sesskey,
                    courseid: courseid,
                    page: currentPage,
                    perpage: 20
                };

                if (subjectKey === 'general') {
                    payload.subject = '';
                    payload.topic = '';
                    payload.lesson = '';
                    payload.general = true;
                } else {
                    payload.subject = subjectKey;
                    payload.topic = topicKey;
                    payload.lesson = lesson;
                }

                const response = await fetch(historyAjaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (data.status === 'error') {
                    throw new Error(data.message || 'Failed to fetch history');
                }

                if (data.conversations && data.conversations.length > 0) {
                    renderHistoryAccordion(data.conversations);
                } else {
                    historyBody.innerHTML =
                        '<div class="empty-state"><p>📭 No conversations found.</p></div>';
                }
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('❌ Failed to fetch history:', error);
                historyBody.innerHTML =
                    '<div class="error">❌ Failed to load history. Please try again.</div>';
            }
        };

        const renderHistoryAccordion = (conversations) => {
            historyBody.innerHTML = '';

            conversations.forEach((conv, index) => {
                const accordionItem = document.createElement('div');
                accordionItem.className = 'accordion-item';

                const truncatedText =
                    conv.usertext.length > 80
                        ? conv.usertext.substring(0, 80) + '...'
                        : conv.usertext;

                let contextInfo = '';
                if (conv.subject || conv.topic || conv.lesson) {
                    const parts = [];
                    if (conv.subject && conv.subject !== 'general') {
                        parts.push(conv.subject);
                    }
                    if (conv.topic) {
                        parts.push(conv.topic);
                    }
                    if (conv.lesson) {
                        parts.push(conv.lesson);
                    }
                    if (parts.length > 0) {
                        contextInfo =
                            `<div style="font-size: 0.85rem; color: #6c757d; margin-top: 4px;">📂 ${
                                parts.join(' → ')
                            }</div>`;
                    }
                }

                accordionItem.innerHTML = `
                    <div class="accordion-header" data-index="${index}">
                        <div style="flex: 1;">
                            <strong>${conv.formattedtime}:</strong> ${truncatedText}
                            ${contextInfo}
                        </div>
                        <span style="color: #6c757d; font-size: 1.2rem;">▼</span>
                    </div>
                    <div class="accordion-content" style="display: none;">
                        <div class="message user-message">
                            <div class="avatar">U</div>
                            <div class="content">${conv.usertext}</div>
                        </div>
                        <div class="message bot-message">
                            <div class="avatar">A</div>
                            <div class="content"
                                 data-rendered="false"
                                 data-function="${conv.functioncalled || ''}">
                                 ${conv.botresponse}
                            </div>
                        </div>
                    </div>
                `;

                const header = accordionItem.querySelector('.accordion-header');
                const content = accordionItem.querySelector('.accordion-content');
                const botContent = accordionItem.querySelector('.bot-message .content');
                const arrow = header.querySelector('span');

                header.addEventListener('click', () => {
                    const isVisible = content.style.display === 'block';

                    content.style.display = isVisible ? 'none' : 'block';
                    arrow.textContent = isVisible ? '▼' : '▲';

                    if (!isVisible && botContent.dataset.rendered === 'false') {
                        const functionCalled = botContent.dataset.function;
                        const rawResponse = botContent.textContent;

                        const mcqFormatted = formatMCQIfNeeded(rawResponse, functionCalled);
                        if (mcqFormatted) {
                            botContent.innerHTML = mcqFormatted;
                        } else {
                            const {text, placeholders} = renderLatex(rawResponse);
                            let rendered = markdown ? markdown.render(text) : text;

                            placeholders.forEach((html, placeholder) => {
                                const escapedPlaceholder = placeholder
                                    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                                rendered = rendered.replace(
                                    new RegExp(escapedPlaceholder, 'g'),
                                    html
                                );
                            });
                            botContent.innerHTML = rendered;
                        }
                        botContent.dataset.rendered = 'true';
                    }
                });

                historyBody.appendChild(accordionItem);
            });
        };

        subjectSelect.addEventListener('change', () => {
            const selectedSubjectKey = subjectSelect.value;

            topicSelect.innerHTML = '<option value="">-- Select Topic --</option>';
            lessonSelect.innerHTML = '<option value="">-- Select Lesson --</option>';
            topicSelect.disabled = true;
            lessonSelect.disabled = true;
            loadButton.disabled = true;

            if (!selectedSubjectKey) {
                return;
            }

            if (selectedSubjectKey === 'general') {
                topicSelect.innerHTML = '<option value="">Not applicable</option>';
                lessonSelect.innerHTML = '<option value="">Not applicable</option>';
                topicSelect.disabled = true;
                lessonSelect.disabled = true;
                loadButton.disabled = false;
                return;
            }

            loadButton.disabled = false;

            const subjectData = syllabusData
                ? syllabusData.find(s => s.subject_key === selectedSubjectKey)
                : null;

            if (subjectData && Array.isArray(subjectData.topics)) {
                const allTopicsOption = document.createElement('option');
                allTopicsOption.value = '';
                allTopicsOption.textContent = 'All topics';
                topicSelect.appendChild(allTopicsOption);

                subjectData.topics.forEach(topic => {
                    const option = document.createElement('option');
                    option.value = topic.topic_key;
                    option.textContent = topic.topic;
                    topicSelect.appendChild(option);
                });

                topicSelect.disabled = true;
            }
        });

        topicSelect.addEventListener('change', () => {
            const selectedSubjectKey = subjectSelect.value;
            const selectedTopicKey = topicSelect.value;

            lessonSelect.innerHTML = '<option value="">-- Select Lesson --</option>';
            lessonSelect.disabled = true;
            loadButton.disabled = false;

            if (!selectedTopicKey) {
                return;
            }

            const subjectData = syllabusData
                ? syllabusData.find(s => s.subject_key === selectedSubjectKey)
                : null;
            const topicData = subjectData
                ? subjectData.topics.find(t => t.topic_key === selectedTopicKey)
                : null;

            if (topicData && Array.isArray(topicData.lessons)) {
                const allLessonsOption = document.createElement('option');
                allLessonsOption.value = '';
                allLessonsOption.textContent = 'All lessons';
                lessonSelect.appendChild(allLessonsOption);

                topicData.lessons.forEach(lesson => {
                    const option = document.createElement('option');
                    if (typeof lesson === 'string') {
                        option.value = lesson;
                        option.textContent = lesson;
                    } else if (lesson.lesson) {
                        option.value = lesson.lesson;
                        option.textContent = lesson.lesson;
                    }
                    lessonSelect.appendChild(option);
                });

                lessonSelect.disabled = false;
            }
        });

        lessonSelect.addEventListener('change', () => {
            loadButton.disabled = !subjectSelect.value;
        });

        loadButton.addEventListener('click', () => {
            const subjectKey = subjectSelect.value;
            const topicKey = topicSelect.value;
            const lesson = lessonSelect.value;

            if (!subjectKey) {
                // eslint-disable-next-line no-alert
                alert('Please select a subject');
                return;
            }

            if (subjectKey === 'general') {
                fetchHistory('general', '', '');
                return;
            }

            if (!topicKey) {
                fetchHistory(subjectKey, '', '');
                return;
            }

            if (!lesson) {
                fetchHistory(subjectKey, topicKey, '');
                return;
            }

            fetchHistory(subjectKey, topicKey, lesson);
        });

        const loadSyllabus = () => {
            if (window.aiAssistantState && window.aiAssistantState.syllabusData) {
                syllabusData = window.aiAssistantState.syllabusData;
                populateSubjects();
                return;
            }

            subjectSelect.innerHTML = '<option value="">Loading subjects...</option>';
        };

        document.addEventListener('syllabusLoaded', (e) => {
            syllabusData = e.detail.syllabusData;
            populateSubjects();
        });

        window.openHistoryWidget = function() {
            modal.style.display = 'flex';
            if (!syllabusData) {
                loadSyllabus();
            }
        };

        window.closeHistoryWidget = function() {
            modal.style.display = 'none';
            historyBody.innerHTML =
                '<div class="empty-state"><p>📚 Select a category above to view your conversation history</p></div>';
            pagination.style.display = 'none';
        };

        closeButton.addEventListener('click', window.closeHistoryWidget);

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                window.closeHistoryWidget();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display === 'flex') {
                window.closeHistoryWidget();
            }
        });

        window.aiAssistantHistoryWidgetInitialized = true;
    };

    return {init: init};
});
