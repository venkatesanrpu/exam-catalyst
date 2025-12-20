// FILE: blocks/ai_assistant/amd/src/mcq_widget.js
// PURPOSE: Flashcard-style MCQ practice modal with LaTeX, checking, keyboard navigation.

define([], function() {
    'use strict';

    const init = function(config) {
        if (window.aiAssistantMCQWidgetInitialized) {
            // eslint-disable-next-line no-console
            console.log('⚠️ MCQ Widget already initialized');
            return;
        }

        const agentConfigKey = config.agentkey;
        const mainSubjectKey = config.mainsubjectkey;
        const sesskey = config.sesskey;
        const courseid = config.courseid;
        const mcqWidgetAjaxUrl = config.mcqwidgetajaxurl;
        const defaultTargetExam = config.targetexam;

        if (!sesskey) {
            // eslint-disable-next-line no-console
            console.error('❌ MCQ Widget: Session key not available');
            return;
        }
        if (!agentConfigKey) {
            // eslint-disable-next-line no-console
            console.error('❌ MCQ Widget: Agent config key not available');
            return;
        }

        const modal = document.getElementById('ai-assistant-mcq-modal');
        const closeButton = document.getElementById('ai-assistant-mcq-close');
        const titleElement = document.getElementById('ai-assistant-mcq-title');
        const cardElement = document.getElementById('ai-assistant-mcq-card');
        const questionView = document.getElementById('mcq-question-view');
        const answerView = document.getElementById('mcq-answer-view');
        const toggleButton = document.getElementById('mcq-toggle-button');
        const prevButton = document.getElementById('mcq-prev-button');
        const nextButton = document.getElementById('mcq-next-button');
        const loadingElement = document.getElementById('mcq-loading');

        if (!modal || !closeButton || !titleElement || !cardElement ||
            !questionView || !answerView || !toggleButton ||
            !prevButton || !nextButton || !loadingElement) {
            // eslint-disable-next-line no-console
            console.error('❌ MCQ Widget: Required DOM elements not found');
            return;
        }

        const ensureFeedbackEl = () => {
            const optionsEl = questionView.querySelector('.mcq-options');
            if (!optionsEl) {
                return null;
            }
            let feedbackEl = questionView.querySelector('.mcq-feedback');
            if (!feedbackEl) {
                feedbackEl = document.createElement('div');
                feedbackEl.className = 'mcq-feedback';
                feedbackEl.setAttribute('aria-live', 'polite');
                optionsEl.insertAdjacentElement('afterend', feedbackEl);
            }
            return feedbackEl;
        };

        const ensureCheckButton = () => {
            let btn = document.getElementById('mcq-check-button');
            if (btn) {
                return btn;
            }
            btn = document.createElement('button');
            btn.id = 'mcq-check-button';
            btn.type = 'button';
            btn.className = 'mcq-check-button';
            btn.textContent = 'Check Answer';
            btn.disabled = true;

            const toggle = document.getElementById('mcq-toggle-button');
            if (toggle && toggle.parentNode) {
                toggle.parentNode.insertBefore(btn, toggle);
            } else {
                cardElement.appendChild(btn);
            }
            return btn;
        };

        const injectStylesOnce = () => {
            if (document.getElementById('ai-assistant-mcq-test-styles')) {
                return;
            }
            const style = document.createElement('style');
            style.id = 'ai-assistant-mcq-test-styles';
            style.textContent = `
                .mcq-option {
                    padding: 10px 12px;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    margin: 10px 0;
                }
                .mcq-option-label {
                    display: flex;
                    gap: 10px;
                    align-items: flex-start;
                    cursor: pointer;
                }
                .mcq-option-input {
                    margin-top: 4px;
                }
                .mcq-option.is-correct {
                    border-color: #2f855a;
                    background: #f0fff4;
                }
                .mcq-option.is-wrong {
                    border-color: #c53030;
                    background: #fff5f5;
                }
                .mcq-feedback {
                    margin-top: 10px;
                    font-weight: 600;
                }
                .mcq-feedback.is-correct {
                    color: #2f855a;
                }
                .mcq-feedback.is-wrong {
                    color: #c53030;
                }
            `;
            document.head.appendChild(style);
        };

        const feedbackEl = ensureFeedbackEl();
        const checkButton = ensureCheckButton();
        injectStylesOnce();

        let mcqData = null;
        let currentCardIndex = 0;
        let showingAnswer = false;
        let historyId = null;
        let isLoadingMcq = false;
        let lastClickTime = 0;

        let selectedAnswers = Object.create(null);
        let checkedAnswers = Object.create(null);

        const escapeHtml = (text) => {
            const div = document.createElement('div');
            div.textContent = String(text ?? '');
            return div.innerHTML;
        };

        const renderLatex = (text) => {
            if (!window.katex) {
                return text;
            }
            let rendered = text;

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

        const clearFeedbackAndStyling = () => {
            const fb = ensureFeedbackEl();
            if (fb) {
                fb.textContent = '';
                fb.classList.remove('is-correct', 'is-wrong');
            }
            const optionsEl = questionView.querySelector('.mcq-options');
            if (optionsEl) {
                optionsEl
                    .querySelectorAll('.mcq-option')
                    .forEach(el => el.classList.remove('is-correct', 'is-wrong'));
            }
        };

        const applyCheckStyling = (index) => {
            const fb = ensureFeedbackEl();
            const optionsEl = questionView.querySelector('.mcq-options');
            if (!fb || !optionsEl) {
                return;
            }

            optionsEl
                .querySelectorAll('.mcq-option')
                .forEach(el => el.classList.remove('is-correct', 'is-wrong'));
            fb.classList.remove('is-correct', 'is-wrong');

            const checked = checkedAnswers[index];
            if (!checked) {
                return;
            }

            const selectedLetter = checked.selected;
            const selectedInput = optionsEl.querySelector(`input[value="${selectedLetter}"]`);
            const selectedWrapper = selectedInput
                ? selectedInput.closest('.mcq-option')
                : null;

            if (checked.isCorrect) {
                fb.textContent = 'Correct.';
                fb.classList.add('is-correct');
                if (selectedWrapper) {
                    selectedWrapper.classList.add('is-correct');
                }
            } else {
                fb.textContent = 'Incorrect.';
                fb.classList.add('is-wrong');
                if (selectedWrapper) {
                    selectedWrapper.classList.add('is-wrong');
                }
            }
        };

        const checkCurrentAnswer = () => {
            if (!mcqData?.questions?.[currentCardIndex] || showingAnswer) {
                return;
            }
            const selected = selectedAnswers[currentCardIndex];
            const fb = ensureFeedbackEl();

            if (!selected) {
                if (fb) {
                    fb.textContent = 'Select an option to check.';
                    fb.classList.remove('is-correct');
                    fb.classList.add('is-wrong');
                }
                return;
            }

            const card = mcqData.questions[currentCardIndex];
            const isCorrect = (selected === card.correct);
            checkedAnswers[currentCardIndex] = {selected, isCorrect};
            applyCheckStyling(currentCardIndex);
        };

        if (checkButton) {
            checkButton.addEventListener('click', checkCurrentAnswer);
        }

        const renderCard = (index) => {
            if (!mcqData || !mcqData.questions || index >= mcqData.questions.length) {
                // eslint-disable-next-line no-console
                console.error('❌ Invalid card index:', index);
                return;
            }

            const card = mcqData.questions[index];
            const totalCards = mcqData.questions.length;

            titleElement.textContent = `Question ${index + 1}/${totalCards}`;

            const questionTextEl = questionView.querySelector('.mcq-question-text');
            const optionsEl = questionView.querySelector('.mcq-options');
            if (!questionTextEl || !optionsEl) {
                // eslint-disable-next-line no-console
                console.error('❌ MCQ Widget: question text/options container not found');
                return;
            }

            const renderedQuestion = renderLatex(card.question);
            questionTextEl.innerHTML = `<p><strong>${renderedQuestion}</strong></p>`;

            clearFeedbackAndStyling();

            optionsEl.innerHTML = '';
            const groupName = `mcq-option-${index}`;

            card.options.forEach((option, i) => {
                const optionLetter = String.fromCharCode(65 + i);
                const renderedOption = renderLatex(option);
                const optionId = `${groupName}-${optionLetter}`;

                const optionWrapper = document.createElement('div');
                optionWrapper.className = 'mcq-option';
                optionWrapper.innerHTML = `
                    <label class="mcq-option-label" for="${optionId}">
                        <input type="radio"
                               id="${optionId}"
                               name="${groupName}"
                               value="${optionLetter}"
                               class="mcq-option-input" />
                        <span class="mcq-option-text">
                            <strong>${optionLetter})</strong> ${renderedOption}
                        </span>
                    </label>
                `;
                optionsEl.appendChild(optionWrapper);

                const input = optionWrapper.querySelector('input');

                if (selectedAnswers[index] === optionLetter) {
                    input.checked = true;
                }

                input.addEventListener('change', () => {
                    selectedAnswers[index] = optionLetter;
                    delete checkedAnswers[index];
                    clearFeedbackAndStyling();
                    if (checkButton) {
                        checkButton.disabled = false;
                    }
                });
            });

            const correctAnswerEl = answerView.querySelector('.mcq-correct-answer');
            const explanationEl = answerView.querySelector('.mcq-explanation');
            if (correctAnswerEl && explanationEl) {
                const correctIndex = card.correct.charCodeAt(0) - 65;
                const correctText = card.options[correctIndex] || '';
                const renderedCorrectText = renderLatex(correctText);
                correctAnswerEl.innerHTML = `
                    <div class="correct-badge">✓ Correct Answer: ${escapeHtml(card.correct)}</div>
                    <p><strong>${escapeHtml(card.correct)})</strong> ${renderedCorrectText}</p>
                `;
                const renderedExplanation = renderLatex(card.explanation);
                explanationEl.innerHTML = `
                    <div class="explanation-label">💡 Explanation</div>
                    <div>${renderedExplanation}</div>
                `;
            }

            showingAnswer = false;
            questionView.style.display = 'block';
            answerView.style.display = 'none';
            toggleButton.textContent = 'Show Answer';

            prevButton.disabled = (index === 0);
            nextButton.disabled = (index === totalCards - 1);

            if (checkButton) {
                checkButton.disabled = !selectedAnswers[index];
            }

            applyCheckStyling(index);

            cardElement.style.opacity = '0';
            setTimeout(() => {
                cardElement.style.opacity = '1';
            }, 50);
        };

        const toggleView = () => {
            showingAnswer = !showingAnswer;
            if (showingAnswer) {
                questionView.style.display = 'none';
                answerView.style.display = 'block';
                toggleButton.textContent = 'Show Question';
                if (checkButton) {
                    checkButton.disabled = true;
                }
            } else {
                questionView.style.display = 'block';
                answerView.style.display = 'none';
                toggleButton.textContent = 'Show Answer';
                if (checkButton) {
                    checkButton.disabled = !selectedAnswers[currentCardIndex];
                }
                applyCheckStyling(currentCardIndex);
            }
        };

        const navigateCard = (direction) => {
            if (!mcqData?.questions?.length) {
                return;
            }
            const newIndex = currentCardIndex + direction;
            if (newIndex >= 0 && newIndex < mcqData.questions.length) {
                currentCardIndex = newIndex;
                renderCard(currentCardIndex);
            }
        };

        const fetchMCQData = async (params) => {
            loadingElement.style.display = 'flex';
            cardElement.style.display = 'none';

            try {
                const url = new URL(mcqWidgetAjaxUrl, window.location.origin);
                url.searchParams.append('sesskey', sesskey);
                url.searchParams.append('agent_config_key', agentConfigKey);
                url.searchParams.append('agent_text', params.agent_text);
                url.searchParams.append('level', params.level);
                url.searchParams.append('mainsubject', mainSubjectKey);
                url.searchParams.append('courseid', courseid);

                if (params.target) {
                    url.searchParams.append('target', params.target);
                }
                if (params.subject) {
                    url.searchParams.append('subject', params.subject);
                }
                if (params.topic) {
                    url.searchParams.append('topic', params.topic);
                }
                if (params.lesson) {
                    url.searchParams.append('lesson', params.lesson);
                }
                if (params.tags) {
                    url.searchParams.append('tags', params.tags);
                }
                if (params.number) {
                    url.searchParams.append('number', params.number);
                }

                const response = await fetch(url);

                if (!response.ok) {
                    const errorText = await response.text();
                    let errorMsg = `HTTP ${response.status}: ${response.statusText}`;
                    try {
                        const errorJson = JSON.parse(errorText);
                        if (errorJson.message) {
                            errorMsg = errorJson.message;
                        }
                        if (errorJson.code === 'INVALID_SESSKEY') {
                            errorMsg = 'Session expired. Please refresh the page and try again.';
                        }
                    } catch (e) {
                        // Not JSON
                    }
                    throw new Error(errorMsg);
                }

                const result = await response.json();
                if (result.status !== 'success' || !result.data) {
                    throw new Error(result.message || 'Failed to generate MCQs');
                }

                selectedAnswers = Object.create(null);
                checkedAnswers = Object.create(null);
                clearFeedbackAndStyling();
                if (checkButton) {
                    checkButton.disabled = true;
                }

                mcqData = result.data;
                currentCardIndex = 0;
                historyId = mcqData.metadata?.history_id || null;

                loadingElement.style.display = 'none';
                cardElement.style.display = 'block';

                renderCard(0);
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('❌ Failed to fetch MCQ data:', error);
                loadingElement.innerHTML = `
                    <div class="error-message">
                        <p style="color: #e53e3e; font-weight: 600;">❌ Failed to generate MCQs</p>
                        <p style="color: #4a5568;">${escapeHtml(error.message)}</p>
                        <button onclick="window.closeMCQWidget()"
                            style="margin-top: 16px; padding: 10px 24px;
                                   background: #667eea; color: white; border: none;
                                   border-radius: 6px; cursor: pointer; font-size: 1rem;">
                            Close
                        </button>
                    </div>
                `;
            }
        };

        window.openMCQWidget = function(params) {
            if (isLoadingMcq) {
                return;
            }
            isLoadingMcq = true;

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            fetchMCQData(params).finally(() => {
                isLoadingMcq = false;
            });
        };

        window.closeMCQWidget = function() {
            modal.style.display = 'none';
            document.body.style.overflow = '';

            mcqData = null;
            currentCardIndex = 0;
            showingAnswer = false;
            historyId = null;
            isLoadingMcq = false;
            selectedAnswers = Object.create(null);
            checkedAnswers = Object.create(null);
            clearFeedbackAndStyling();
            if (checkButton) {
                checkButton.disabled = true;
            }

            loadingElement.innerHTML = `
                <div class="spinner"></div>
                <p>Generating MCQs...</p>
            `;
            loadingElement.style.display = 'none';
            cardElement.style.display = 'block';
        };

        window.openMCQWidgetWithData = function(mcqDataFromHistory) {
            if (!mcqDataFromHistory || !mcqDataFromHistory.questions ||
                mcqDataFromHistory.questions.length === 0) {
                // eslint-disable-next-line no-console
                console.error('Invalid MCQ data for replay');
                // eslint-disable-next-line no-alert
                alert('Invalid MCQ data. Cannot practice these questions.');
                return;
            }

            mcqData = mcqDataFromHistory;
            currentCardIndex = 0;
            showingAnswer = false;
            historyId = null;
            selectedAnswers = Object.create(null);
            checkedAnswers = Object.create(null);
            clearFeedbackAndStyling();
            if (checkButton) {
                checkButton.disabled = true;
            }

            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            loadingElement.style.display = 'none';
            cardElement.style.display = 'block';
            renderCard(0);
        };

        closeButton.addEventListener('click', window.closeMCQWidget);
        toggleButton.addEventListener('click', toggleView);
        prevButton.addEventListener('click', () => navigateCard(-1));
        nextButton.addEventListener('click', () => navigateCard(1));

        document.addEventListener('keydown', (e) => {
            if (modal.style.display !== 'flex') {
                return;
            }
            switch (e.key) {
                case 'ArrowLeft':
                    if (!prevButton.disabled) {
                        navigateCard(-1);
                    }
                    break;
                case 'ArrowRight':
                    if (!nextButton.disabled) {
                        navigateCard(1);
                    }
                    break;
                case ' ':
                case 'Enter':
                    if (e.target.tagName !== 'BUTTON') {
                        e.preventDefault();
                        toggleView();
                    }
                    break;
                case 'Escape':
                    window.closeMCQWidget();
                    break;
            }
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                window.closeMCQWidget();
            }
        });

        document.addEventListener('click', (e) => {
            const link = e.target.closest('.mcq-flashcard-link');
            if (!link) {
                return;
            }
            e.preventDefault();

            const now = Date.now();
            if (now - lastClickTime < 300) {
                return;
            }
            lastClickTime = now;

            const dataset = link.dataset;
            if (!dataset.function || !dataset.level || !dataset.agentText) {
                // eslint-disable-next-line no-console
                console.error('❌ MCQ flashcard link missing required data attributes');
                return;
            }

            const targetExam =
                dataset.target || defaultTargetExam || 'CSIR Chemical Sciences Exam';

            window.openMCQWidget({
                function: dataset.function,
                level: dataset.level,
                agent_text: dataset.agentText,
                target: targetExam,
                subject: dataset.subject || '',
                topic: dataset.topic || '',
                lesson: dataset.lesson || '',
                tags: dataset.tags || '',
                number: dataset.number || ''
            });
        });

        cardElement.style.transition = 'opacity 0.3s ease-in-out';

        window.aiAssistantMCQWidgetInitialized = true;
    };

    return {init: init};
});
