// FILE: blocks/ai_assistant/amd/src/main.js
// DESCRIPTION: AMD module for AI Assistant main chat widget.

define([], function() {
    'use strict';

    /**
     * Initialize the AI Assistant main widget.
     *
     * @param {Object} config
     * @param {string} config.agentkey
     * @param {string} config.mainsubjectkey
     * @param {number} config.blockinstanceid
     * @param {string} config.sesskey
     * @param {number} config.courseid
     * @param {string} config.historyajaxurl
     * @param {string} config.syllabusajaxurl
     * @param {string} config.askagentajaxurl
     * @param {string} config.mcqajaxurl
     * @param {string} config.websearchajaxurl
     * @param {string} config.youtubesummarizeajaxurl
     * @param {string} config.pagesubject
     * @param {string} config.pagetopic
     */
    const init = function(config) {
        // Guard clause - prevent multiple initializations per page.
        if (init._initialized) {
            return;
        }
        init._initialized = true;

        // ==================== CONFIGURATION ====================
        const agentConfigKey = config.agentkey;
        const mainSubjectKey = config.mainsubjectkey;
        const blockInstanceId = config.blockinstanceid;
        const sesskey = config.sesskey;
        const courseid = config.courseid;

        const historyAjaxUrl = config.historyajaxurl;
        const syllabusAjaxUrl = config.syllabusajaxurl;
        const askAgentAjaxUrl = config.askagentajaxurl;
        const mcqAjaxUrl = config.mcqajaxurl;
        const webSearchAjaxUrl = config.websearchajaxurl;
        const youtubeSummarizeAjaxUrl = config.youtubesummarizeajaxurl;

        const pageSubject = config.pagesubject;
        const pageTopic = config.pagetopic;

        // Function URL mapping.
        const functionUrls = {
            ask_agent: askAgentAjaxUrl,
            mcq: mcqAjaxUrl,
            websearch: webSearchAjaxUrl,
            youtube_summarize: youtubeSummarizeAjaxUrl
        };

        // ==================== DOM ELEMENTS ====================
        const triggerButton = document.getElementById('ai-assistant-trigger-button');
        const closeButton = document.getElementById('ai-assistant-close-button');
        const fullscreenButton = document.getElementById('ai-assistant-fullscreen-button');
        const guidedSearchButton = document.getElementById('ai-assistant-guided-search-button');

        const widgetContainer = document.getElementById('ai-assistant-widget-container');
        const chatBody = document.getElementById('ai-assistant-chat-body');
        const chatInput = document.getElementById('ai-assistant-input');
        const sendButton = document.getElementById('ai-assistant-send-button');

        const overlay = document.getElementById('ai-assistant-overlay');
        const modalContainer = document.getElementById('ai-assistant-modal-container');
        const modalForm = document.getElementById('ai-assistant-modal-form');
        const modalSubject = document.getElementById('ai-assistant-modal-subject');
        const modalTopic = document.getElementById('ai-assistant-modal-topic');
        const modalLesson = document.getElementById('ai-assistant-modal-lesson');
        const modalQuestion = document.getElementById('ai-assistant-modal-question');
        const modalCancel = document.getElementById('ai-assistant-modal-cancel');

        // ==================== STATE MANAGEMENT ====================
        const state = {
            isChatVisible: false,
            isFullscreen: false,
            isModalVisible: false,
            messageHistory: [],
            nextMessageContext: {
                subject: '',
                topic: pageTopic,
                lesson: ''
            },
            syllabusData: null
        };

        // ==================== RENDERING UTILITIES ====================
        const markdown = window.markdownit({
            html: true,
            breaks: true
        });

        const renderLatex = (text) => {
            const latexPlaceholders = new Map();

            // Display math: \[ ... \]
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
                    return '<span class="text-danger">[LaTeX Error]</span>';
                }
            });

            // Inline math: \( ... \)
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
                    return '<span class="text-danger">[LaTeX Error]</span>';
                }
            });

            return {text, placeholders: latexPlaceholders};
        };

        const renderMessage = (text, from) => {
            const messageDiv = document.createElement('div');
            messageDiv.className = `ai-assistant-message from-${from}`;

            const avatar = document.createElement('div');
            avatar.className = 'ai-assistant-avatar';
            avatar.textContent = from === 'user' ? 'U' : 'A';

            const contentDiv = document.createElement('div');
            contentDiv.className = 'ai-assistant-message-content';

            const result = renderLatex(text);
            const protectedText = result.text;
            const placeholders = result.placeholders;

            let renderedContent = markdown.render(protectedText);

            placeholders.forEach((rendered, placeholder) => {
                const escapedPlaceholder = placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                renderedContent = renderedContent.replace(new RegExp(escapedPlaceholder, 'g'), rendered);
            });

            contentDiv.innerHTML = renderedContent;

            messageDiv.appendChild(avatar);
            messageDiv.appendChild(contentDiv);

            chatBody.appendChild(messageDiv);
            chatBody.scrollTop = chatBody.scrollHeight;
        };

        const repaintHistory = () => {
            chatBody.innerHTML = '';
            state.messageHistory.forEach(msg => {
                renderMessage(msg.usertext, 'user');
                if (msg.botresponse) {
                    renderMessage(msg.botresponse, 'bot');
                }
            });
        };

        const showTypingIndicator = () => {
            if (document.getElementById('ai-assistant-typing-indicator')) {
                return;
            }
            const indicator = document.createElement('div');
            indicator.id = 'ai-assistant-typing-indicator';
            indicator.className = 'ai-assistant-message from-bot';
            indicator.innerHTML =
                '<div class="ai-assistant-avatar">A</div>' +
                '<div class="ai-assistant-typing-indicator"><span></span><span></span><span></span></div>';
            chatBody.appendChild(indicator);
            chatBody.scrollTop = chatBody.scrollHeight;
        };

        const hideTypingIndicator = () => {
            const indicator = document.getElementById('ai-assistant-typing-indicator');
            if (indicator) {
                indicator.remove();
            }
        };

        // ==================== UI CONTROLS ====================
        const openChatWindow = () => {
            state.isChatVisible = true;
            widgetContainer.classList.add('active');
        };

        const closeChatWindow = () => {
            if (state.isFullscreen) {
                state.isFullscreen = false;
                widgetContainer.classList.remove('fullscreen');
                overlay.classList.remove('active');
            }
            state.isChatVisible = false;
            widgetContainer.classList.remove('active');
        };

        const openModal = () => {
            state.isModalVisible = true;
            overlay.classList.add('active');
            modalContainer.classList.add('active');
        };

        const closeModal = () => {
            state.isModalVisible = false;
            overlay.classList.remove('active');
            modalContainer.classList.remove('active');
        };

        // ==================== CORE MESSAGING FUNCTION ====================
        const sendMessage = (detail) => {
            // Normalize agent_text.
            if (detail.agentText && !detail.agent_text) {
                detail.agent_text = detail.agentText;
            }

            const functionCalled = detail.function;
            const userText = detail.agent_text;

            if (!userText || !userText.trim() || !functionCalled) {
                // eslint-disable-next-line no-console
                console.error('Missing required parameters:', detail);
                return;
            }

            if (!functionUrls[functionCalled]) {
                // eslint-disable-next-line no-console
                console.error('Unknown function:', functionCalled);
                renderMessage(`Error: Unknown function "${functionCalled}"`, 'bot');
                return;
            }

            // Display user message.
            renderMessage(userText, 'user');
            showTypingIndicator();

            const historyEntry = {
                usertext: userText,
                botresponse: '',
                functioncalled: functionCalled,
                subject: detail.subject || '',
                topic: detail.topic || state.nextMessageContext.topic || '',
                lesson: detail.lesson || ''
            };

            state.messageHistory.push(historyEntry);
            localStorage.setItem(
                `ai_assistant_history_${courseid}`,
                JSON.stringify(state.messageHistory)
            );

            const createPayload = {
                action: 'create',
                sesskey: sesskey,
                courseid: courseid,
                history: historyEntry
            };

            fetch(historyAjaxUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(createPayload)
            })
                .then(response => response.json())
                .then(createData => {
                    if (createData.status !== 'success' || !createData.historyid) {
                        throw new Error(createData.message || 'Failed to create history');
                    }

                    const historyId = createData.historyid;

                    const agentParams = new URLSearchParams();
                    agentParams.append('sesskey', sesskey);
                    agentParams.append('agent_config_key', agentConfigKey);
                    agentParams.append('agent_text', userText);

                    if (functionCalled === 'mcq' && detail.level) {
                        agentParams.append('level', detail.level);
                    }
                    if (functionCalled === 'youtube_summarize' && detail.youtube_url) {
                        agentParams.append('youtube_url', detail.youtube_url);
                    }
                    if (functionCalled === 'websearch' && detail.search_query) {
                        agentParams.append('search_query', detail.search_query);
                    }

                    if (detail.target) {
                        agentParams.append('target', detail.target);
                    }
                    if (detail.subject) {
                        agentParams.append('subject', detail.subject);
                    }
                    if (detail.topic) {
                        agentParams.append('topic', detail.topic);
                    }
                    if (detail.lesson) {
                        agentParams.append('lesson', detail.lesson);
                    }
                    if (detail.tags) {
                        const tagsToSend = Array.isArray(detail.tags)
                            ? detail.tags.join(',')
                            : detail.tags;
                        agentParams.append('tags', tagsToSend);
                    }

                    const endpointUrl = functionUrls[functionCalled] + '?' + agentParams.toString();

                    // ==================== SSE STREAMING ====================
                    const eventSource = new EventSource(endpointUrl);

                    let streamedContent = '';
                    let hasReceivedData = false;
                    let streamComplete = false;
                    let metadata = {};
                    let inactivityTimer = null;

                    const finalizeStream = (reason) => {
                        if (streamComplete) {
                            return;
                        }
                        streamComplete = true;
                        eventSource.close();
                        if (inactivityTimer) {
                            clearTimeout(inactivityTimer);
                        }
                        // eslint-disable-next-line no-console
                        console.log('Stream finalized:', reason);

                        let finalContent = streamedContent
                            .replace(/<think>(\\s|\\S)*?<\\/think>/g, '')
                            .trim();

                        const lastMessage = state.messageHistory[state.messageHistory.length - 1];
                        if (lastMessage) {
                            lastMessage.botresponse = finalContent;
                        }
                        localStorage.setItem(
                            `ai_assistant_history_${courseid}`,
                            JSON.stringify(state.messageHistory)
                        );

                        fetch(historyAjaxUrl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                action: 'update',
                                sesskey: sesskey,
                                courseid: courseid,
                                historyid: historyId,
                                botresponse: finalContent,
                                metadata: metadata
                            })
                        }).catch(err => {
                            // eslint-disable-next-line no-console
                            console.error('Failed to update history', err);
                        });
                    };

                    const resetInactivityTimer = () => {
                        if (inactivityTimer) {
                            clearTimeout(inactivityTimer);
                        }
                        inactivityTimer = setTimeout(() => {
                            if (!streamComplete && hasReceivedData) {
                                // eslint-disable-next-line no-console
                                console.log('No data for 120s - auto-completing');
                                finalizeStream('inactivity');
                            }
                        }, 120000);
                    };

                    // Create message container for streaming.
                    hideTypingIndicator();

                    const messageDiv = document.createElement('div');
                    messageDiv.className = 'ai-assistant-message from-bot';
                    messageDiv.id = `streaming-message-${historyId}`;

                    const avatar = document.createElement('div');
                    avatar.className = 'ai-assistant-avatar';
                    avatar.textContent = 'A';

                    const contentDiv = document.createElement('div');
                    contentDiv.className = 'ai-assistant-message-content';
                    contentDiv.innerHTML = '<em class="text-muted">Connecting...</em>';

                    messageDiv.appendChild(avatar);
                    messageDiv.appendChild(contentDiv);
                    chatBody.appendChild(messageDiv);
                    chatBody.scrollTop = chatBody.scrollHeight;

                    eventSource.addEventListener('chunk', (e) => {
                        hasReceivedData = true;
                        resetInactivityTimer();

                        const data = JSON.parse(e.data);
                        streamedContent += data.content;

                        let cleanedContent = streamedContent
                            .replace(/<think>(\\s|\\S)*?<\\/think>/g, '')
                            .trim();

                        const result = renderLatex(cleanedContent);
                        const protectedText = result.text;
                        const placeholders = result.placeholders;

                        let renderedContent = markdown.render(protectedText);
                        placeholders.forEach((rendered, placeholder) => {
                            const escapedPlaceholder =
                                placeholder.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&');
                            renderedContent = renderedContent.replace(
                                new RegExp(escapedPlaceholder, 'g'),
                                rendered
                            );
                        });

                        contentDiv.innerHTML = renderedContent;
                        chatBody.scrollTop = chatBody.scrollHeight;
                    });

                    eventSource.addEventListener('metadata', (e) => {
                        metadata = JSON.parse(e.data);
                    });

                    eventSource.addEventListener('done', () => {
                        finalizeStream('done_event');
                    });

                    eventSource.addEventListener('error', (e) => {
                        // eslint-disable-next-line no-console
                        console.error('EventSource error', e);
                        if (!streamComplete) {
                            if (hasReceivedData) {
                                finalizeStream('error_with_data');
                            } else {
                                eventSource.close();
                                contentDiv.innerHTML =
                                    '<span class="text-danger">❌ Connection lost. Please try again.</span>';
                            }
                        }
                    });

                    eventSource.onerror = (e) => {
                        if (eventSource.readyState === EventSource.CLOSED && !streamComplete) {
                            // eslint-disable-next-line no-console
                            console.error('EventSource closed', e);
                            if (hasReceivedData) {
                                finalizeStream('closed_with_data');
                            } else {
                                contentDiv.innerHTML =
                                    '<span class="text-danger">❌ Connection failed.</span>';
                            }
                        }
                    };

                    resetInactivityTimer();
                })
                .catch(error => {
                    // eslint-disable-next-line no-console
                    console.error('AI Assistant error:', error);
                    hideTypingIndicator();
                    renderMessage(`Sorry, an error occurred: ${error.message}`, 'bot');
                });

            chatInput.value = '';
            chatInput.style.height = 'auto';
        };

        // ==================== EVENT HANDLERS ====================
        const handleTextInput = () => {
            sendMessage({
                function: 'ask_agent',
                agent_text: chatInput.value,
                subject: '',
                topic: pageTopic,
                lesson: ''
            });
        };

        document.addEventListener('click', (e) => {
            // Study Notes / Ask Agent links.
            if (e.target.classList.contains('notes-link')) {
                e.preventDefault();
                const dataset = e.target.dataset;

                if (!dataset.function || !dataset.agentText) {
                    // eslint-disable-next-line no-console
                    console.error('Notes link missing required data');
                    return;
                }

                if (!state.isChatVisible) {
                    openChatWindow();
                }

                setTimeout(() => {
                    sendMessage({
                        function: dataset.function,
                        agent_text: dataset.agentText,
                        target: dataset.target || 'CSIR Chemical Sciences Exam',
                        subject: dataset.subject || '',
                        topic: dataset.topic || '',
                        lesson: dataset.lesson || '',
                        tags: dataset.tags || ''
                    });
                }, state.isChatVisible ? 0 : 300);
            }

            // MCQ links (.mcq-link / .mcq-flashcard-link).
            const mcqLink = e.target.closest('.mcq-link, .mcq-flashcard-link');
            if (mcqLink) {
                e.preventDefault();
                const dataset = mcqLink.dataset;

                if (!dataset.function || !dataset.level || !dataset.agentText) {
                    // eslint-disable-next-line no-console
                    console.error('MCQ link missing required data');
                    return;
                }

                if (typeof window.openMCQWidget === 'function') {
                    window.openMCQWidget({
                        function: dataset.function,
                        level: dataset.level,
                        agent_text: dataset.agentText,
                        target: dataset.target || 'CSIR Chemical Sciences Exam',
                        subject: dataset.subject || '',
                        topic: dataset.topic || '',
                        lesson: dataset.lesson || '',
                        tags: dataset.tags || ''
                    });
                } else {
                    // eslint-disable-next-line no-console
                    console.warn('openMCQWidget not available, using chat fallback');
                    if (!state.isChatVisible) {
                        openChatWindow();
                    }
                    setTimeout(() => {
                        sendMessage({
                            function: dataset.function,
                            level: dataset.level,
                            agent_text: dataset.agentText,
                            target: dataset.target || 'CSIR Chemical Sciences Exam',
                            subject: dataset.subject || '',
                            topic: dataset.topic || '',
                            lesson: dataset.lesson || '',
                            tags: dataset.tags || ''
                        });
                    }, state.isChatVisible ? 0 : 300);
                }
            }

            // YouTube Summarize links.
            if (e.target.classList.contains('youtube-summarize-link')) {
                e.preventDefault();
                const dataset = e.target.dataset;

                if (!dataset.function || !dataset.youtubeUrl) {
                    // eslint-disable-next-line no-console
                    console.error('YouTube link missing required data');
                    return;
                }

                if (!state.isChatVisible) {
                    openChatWindow();
                }

                setTimeout(() => {
                    sendMessage({
                        function: dataset.function,
                        agent_text: dataset.agentText || 'Summarize this video',
                        youtube_url: dataset.youtubeUrl,
                        subject: dataset.subject || '',
                        topic: dataset.topic || ''
                    });
                }, state.isChatVisible ? 0 : 300);
            }
        });

        document.addEventListener('ai-assistant-send', (e) => {
            if (!state.isChatVisible) {
                openChatWindow();
            }
            setTimeout(() => sendMessage(e.detail), state.isChatVisible ? 0 : 300);
        });

        // ==================== SYLLABUS FUNCTIONS ====================
        const loadSyllabus = async () => {
            if (state.syllabusData) {
                // eslint-disable-next-line no-console
                console.log('Syllabus already loaded');
                return;
            }

            // eslint-disable-next-line no-console
            console.log('Loading syllabus from:', syllabusAjaxUrl);

            try {
                const url = `${syllabusAjaxUrl}?blockid=${blockInstanceId}&sesskey=${sesskey}`;
                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();

                if (!data || !Array.isArray(data)) {
                    throw new Error('Invalid syllabus data structure (expected array)');
                }

                state.syllabusData = data;

                if (window.aiAssistantHistoryWidgetInitialized) {
                    const event = new CustomEvent('syllabusLoaded', {
                        detail: {syllabusData: data}
                    });
                    document.dispatchEvent(event);
                }
            } catch (error) {
                // eslint-disable-next-line no-console
                console.error('Failed to load syllabus:', error);
                throw error;
            }
        };

        // ==================== INITIALIZATION ====================
        const doInit = () => {
            if (widgetContainer) {
                widgetContainer.style.display = '';
            }
            if (modalContainer) {
                modalContainer.style.display = '';
            }

            const cachedHistory = localStorage.getItem(
                `ai_assistant_history_${courseid}`
            );
            if (cachedHistory) {
                try {
                    state.messageHistory = JSON.parse(cachedHistory);
                } catch (e) {
                    state.messageHistory = [];
                }
            }
            repaintHistory();

            if (triggerButton) {
                triggerButton.addEventListener('click', () => {
                    state.isChatVisible ? closeChatWindow() : openChatWindow();
                });
            }

            if (closeButton) {
                closeButton.addEventListener('click', closeChatWindow);
            }

            if (fullscreenButton) {
                fullscreenButton.addEventListener('click', () => {
                    state.isFullscreen = !state.isFullscreen;
                    widgetContainer.classList.toggle('fullscreen', state.isFullscreen);
                    overlay.classList.toggle('active', state.isFullscreen);
                });
            }

            const historyButton = document.getElementById('ai-assistant-history-button');
            if (historyButton) {
                historyButton.addEventListener('click', () => {
                    // eslint-disable-next-line no-console
                    console.log('History button clicked');
                    if (typeof window.openHistoryWidget === 'function') {
                        window.openHistoryWidget();

                        if (state.syllabusData) {
                            const event = new CustomEvent('syllabusLoaded', {
                                detail: {syllabusData: state.syllabusData}
                            });
                            document.dispatchEvent(event);
                        } else {
                            loadSyllabus();
                        }
                    } else {
                        // eslint-disable-next-line no-console
                        console.error('openHistoryWidget not available');
                    }
                });
            }

            if (sendButton) {
                sendButton.addEventListener('click', handleTextInput);
            }

            if (chatInput) {
                chatInput.addEventListener('keypress', e => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        handleTextInput();
                    }
                });

                chatInput.addEventListener('input', () => {
                    chatInput.style.height = 'auto';
                    chatInput.style.height = chatInput.scrollHeight + 'px';
                });
            }

            if (guidedSearchButton) {
                guidedSearchButton.addEventListener('click', async () => {
                    await loadSyllabus();

                    if (!state.syllabusData) {
                        // eslint-disable-next-line no-alert
                        alert('Could not load syllabus data.');
                        return;
                    }

                    modalSubject.innerHTML =
                        '<option value="">-- Please select a subject --</option>';
                    state.syllabusData.forEach(sub => {
                        const option = document.createElement('option');
                        option.value = sub.subject_key;
                        option.textContent = sub.subject;
                        modalSubject.appendChild(option);
                    });

                    modalTopic.innerHTML =
                        '<option value="">-- Please select a topic --</option>';
                    modalLesson.innerHTML =
                        '<option value="">-- Please select a lesson --</option>';
                    modalTopic.disabled = true;
                    modalLesson.disabled = true;

                    openModal();
                });
            }

            if (modalSubject) {
                modalSubject.addEventListener('change', () => {
                    const selectedSubjectKey = modalSubject.value;

                    modalTopic.innerHTML =
                        '<option value="">-- Please select a topic --</option>';
                    modalLesson.innerHTML =
                        '<option value="">-- Please select a lesson --</option>';
                    modalTopic.disabled = true;
                    modalLesson.disabled = true;

                    const subjectData = state.syllabusData
                        ? state.syllabusData.find(s => s.subject_key === selectedSubjectKey)
                        : null;

                    if (subjectData && subjectData.topics) {
                        subjectData.topics.forEach(topic => {
                            const option = document.createElement('option');
                            option.value = topic.topic_key;
                            option.textContent = topic.topic;
                            modalTopic.appendChild(option);
                        });
                        modalTopic.disabled = false;
                    }
                });
            }

            if (modalTopic) {
                modalTopic.addEventListener('change', () => {
                    const selectedSubjectKey = modalSubject.value;
                    const selectedTopicKey = modalTopic.value;

                    modalLesson.innerHTML =
                        '<option value="">-- Please select a lesson --</option>';
                    modalLesson.disabled = true;

                    const subjectData = state.syllabusData
                        ? state.syllabusData.find(s => s.subject_key === selectedSubjectKey)
                        : null;
                    const topicData = subjectData
                        ? subjectData.topics.find(t => t.topic_key === selectedTopicKey)
                        : null;

                    if (topicData && topicData.lessons && topicData.lessons.length > 0) {
                        topicData.lessons.forEach(lesson => {
                            const option = document.createElement('option');
                            if (typeof lesson === 'string') {
                                option.value = lesson;
                                option.textContent = lesson;
                            } else {
                                option.value = lesson.lesson_key;
                                option.textContent = lesson.lesson;
                            }
                            modalLesson.appendChild(option);
                        });
                        modalLesson.disabled = false;
                    }
                });
            }

            if (modalForm) {
                modalForm.addEventListener('submit', (e) => {
                    e.preventDefault();

                    const selectedSubjectKey = modalSubject.value;
                    const selectedTopicKey = modalTopic.value;
                    const selectedLessonKey = modalLesson.value;

                    const subjectData = state.syllabusData
                        ? state.syllabusData.find(s => s.subject_key === selectedSubjectKey)
                        : null;
                    const topicData = subjectData
                        ? subjectData.topics.find(t => t.topic_key === selectedTopicKey)
                        : null;

                    let tagsForQuery = [];
                    if (topicData && topicData.lessons) {
                        const lessonData = topicData.lessons.find(l => {
                            if (typeof l === 'string') {
                                return l === selectedLessonKey;
                            } else {
                                return l.lesson_key === selectedLessonKey;
                            }
                        });
                        if (lessonData && typeof lessonData === 'object' && lessonData.tags) {
                            tagsForQuery = lessonData.tags;
                        }
                    }

                    sendMessage({
                        function: 'ask_agent',
                        agent_text: modalQuestion.value,
                        subject: selectedSubjectKey,
                        topic: selectedTopicKey,
                        lesson: selectedLessonKey,
                        tags: tagsForQuery
                    });

                    modalForm.reset();
                    closeModal();

                    if (!state.isChatVisible) {
                        openChatWindow();
                    }
                });
            }

            if (modalCancel) {
                modalCancel.addEventListener('click', closeModal);
            }
        };

        doInit();
    };

    return {
        init: init
    };
});
