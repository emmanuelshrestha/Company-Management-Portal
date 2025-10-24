// messages.js - Chat functionality (cleaned & modernized)
/* 
  Key improvements:
  - Single, consistent polling timer (this.pollingTimeout) with proper start/stop.
  - Avoid duplicated functions (addMessageToChat was duplicated).
  - Safer event wiring: use container.onclick assignment (prevents duplicate listeners).
  - CSRF token usage: reads window.appData.csrfToken (set by server-side page).
  - createChatArea no longer contains PHP echo; it uses client-side CSRF token.
  - Better error handling for fetch + JSON parsing.
  - Defensive checks for DOM elements.
  - Added infinite scroll for loading older messages.
  - Throttled scroll handler for performance.
  - Improved addMessageToChat with grouping logic.
  - Used requestIdleCallback/setTimeout for heavy rendering.
  - Separate polling for conversations (every 30s or on change) to reduce jank/INP.
  - Conditional renderConversations to skip if data unchanged.
  - Wrapped some DOM ops in rAF to prevent thrashing.
  - Debounced input for textarea resize.
  - Fetch timeouts to prevent hangs.
*/

class MessageManager {
    constructor() {
        this.currentConversationId = null;
        this.pollingTimeout = null;
        this.conversationsPollingTimeout = null;
        this.lastMessageId = 0;
        this.firstMessageId = 0;
        this.hasMoreMessages = false;
        this.isLoading = false;
        this.isLoadingOlder = false;
        this.conversationsData = [];
        this.conversationsHash = ''; // For change detection
    }

    // Utility: Throttle function
    throttle(fn, delay) {
        let timeout = null;
        return (...args) => {
            if (!timeout) {
                fn.apply(this, args);
                timeout = setTimeout(() => { timeout = null; }, delay);
            }
        };
    }

    // Utility: Debounce function (similar to throttle but waits until end of burst)
    debounce(fn, delay) {
        let timeout = null;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    // Called when DOM is ready
    async init() {
        console.log('MessageManager initialized');
        this.initializeEventListeners();
        await this.loadConversations();

        // Start polling after initial load
        this.startPolling();

        // Open conversation if friend_id present in URL
        setTimeout(() => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('friend_id')) {
                const friendId = urlParams.get('friend_id');
                console.log('🔄 Opening conversation from URL:', friendId);
                this.openConversationFromUrl(friendId);
            }
        }, 500);
    }

    initializeEventListeners() {
        // Message form submission
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form && form.id === 'messageForm') {
                e.preventDefault();
                this.handleSendMessage(e);
            }
        });

        // Input auto-resize (debounced to handle rapid typing)
        const debouncedResize = this.debounce(this.autoResizeTextarea.bind(this), 50);
        document.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'messageInput') {
                debouncedResize(e);
            }
        });

        // Enter-to-send
        document.addEventListener('keydown', (e) => {
            if (e.target && e.target.id === 'messageInput') {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const fakeEvent = { target: document.getElementById('messageForm'), preventDefault: () => {} };
                    this.handleSendMessage(fakeEvent);
                }
            }
        });

        // Browser back button
        window.addEventListener('popstate', () => {
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.has('friend_id')) {
                this.stopPolling();
                this.currentConversationId = null;

                if (window.innerWidth <= 768) {
                    const sidebar = document.querySelector('.conversations-sidebar');
                    const chatArea = document.querySelector('.chat-area');
                    if (sidebar) sidebar.style.display = 'flex';
                    if (chatArea) chatArea.style.display = 'none';
                }

                this.loadConversations();
            }
        });
    }

    autoResizeTextarea(e) {
        const textarea = e.target;
        // Batch write after read to avoid thrashing
        requestAnimationFrame(() => {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        });
    }

    async loadConversations(force = false) {
        console.log('loadConversations called');

        const container = document.getElementById('conversationsList');
        if (!container) return;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const resp = await fetch('get_conversations.php', { credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);

            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text || '{}');
            } catch (jsonErr) {
                console.error('Invalid JSON from get_conversations.php:', jsonErr, text);
                return;
            }

            if (data.error) {
                console.error('API Error:', data.error);
                return;
            }

            let newData = data.conversations || [];
            
            // SORT by last_message_time (most recent first)
            newData.sort((a, b) => {
                const timeA = a.last_message_time ? new Date(a.last_message_time).getTime() : 0;
                const timeB = b.last_message_time ? new Date(b.last_message_time).getTime() : 0;
                return timeB - timeA;
            });

            // Simple hash for change detection (JSON stringify sorted data)
            const newHash = JSON.stringify(newData.map(c => ({id: c.conversation_id, time: c.last_message_time, unread: c.unread})));
            if (!force && newHash === this.conversationsHash) {
                console.log('Conversations data unchanged, skipping render');
                return;
            }

            this.conversationsData = newData;
            this.conversationsHash = newHash;
            
            this.renderConversations(this.conversationsData);
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Conversations fetch timed out');
            } else {
                console.error('Load conversations error:', error);
            }
        }
    }

    renderConversations(conversations) {
        const container = document.getElementById('conversationsList');
        if (!container) return;

        if (!Array.isArray(conversations) || conversations.length === 0) {
            container.innerHTML = `
                <div style="padding: 40px 20px; text-align: center; color: #718096;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">💬</div>
                    <h3>No conversations yet</h3>
                    <p>Start a conversation with your friends!</p>
                </div>
            `;
            container.onclick = null;
            return;
        }

        // Use fragment to batch DOM insert
        const fragment = document.createDocumentFragment();
        conversations.forEach(conv => {
            const div = document.createElement('div');
            const activeClass = String(this.currentConversationId) === String(conv.conversation_id) ? 'active' : '';
            const avatarStyle = conv.profile_picture ? `background-image: url(../../uploads/profile_pictures/${this.escapeHtml(conv.profile_picture)});` : '';
            const avatarContent = conv.profile_picture ? '' : (conv.friend_name ? this.escapeHtml(conv.friend_name.charAt(0).toUpperCase()) : '');
            const lastMsg = conv.last_message ? String(conv.last_message) : '';
            const previewText = lastMsg
                ? ((conv.is_sent_by_me ? 'You: ' : '') + this.escapeHtml(lastMsg.length > 30 ? lastMsg.substring(0, 30) + '...' : lastMsg))
                : 'Start a conversation';
            const timeHtml = conv.last_message_time
                ? `<div class="conversation-time">${this.formatTime(conv.last_message_time)}</div>`
                : (lastMsg ? `<div class="conversation-time">Just now</div>` : '');
            const unreadDot = conv.unread ? '<div class="conversation-unread-dot" aria-hidden="true"></div>' : '';

            div.className = `conversation-item ${activeClass}`;
            div.dataset.friendId = this.escapeHtml(conv.friend_id);
            div.dataset.conversationId = this.escapeHtml(conv.conversation_id || '');
            div.innerHTML = `
                <div class="conversation-avatar" style="${avatarStyle}">${avatarContent}</div>
                <div class="conversation-info">
                    <div class="conversation-name">${this.escapeHtml(conv.friend_name)}</div>
                    <div class="conversation-preview">${previewText}</div>
                    ${timeHtml}
                </div>
                ${unreadDot}
            `;
            fragment.appendChild(div);
        });

        requestAnimationFrame(() => {
            container.innerHTML = '';
            container.appendChild(fragment);

            // Assign click handler
            container.onclick = (event) => {
                const conversationItem = event.target.closest('.conversation-item');
                if (conversationItem) {
                    const friendId = conversationItem.getAttribute('data-friend-id');
                    const conversationId = conversationItem.getAttribute('data-conversation-id') || null;
                    console.log('🎯 Conversation clicked:', friendId, conversationId);
                    this.openConversation(friendId, conversationId, conversationItem);
                }
            };
        });
    }

    async loadMessages(conversationId) {
        console.log('🚀 loadMessages called for conversation:', conversationId);

        const chatArea = document.querySelector('.chat-area');
        const messagesArea = document.getElementById('messagesArea');
        
        if (chatArea) chatArea.style.display = 'flex';
        
        if (!messagesArea) return;

        if (this.isLoading) return;
        this.isLoading = true;

        const debugInfo = document.getElementById('debugInfo');
        if (debugInfo) debugInfo.style.display = 'none'; // Hide by default

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const url = `get_messages.php?conversation_id=${encodeURIComponent(conversationId)}&limit=100`;
            const resp = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);

            const text = await resp.text();
            let data = JSON.parse(text || '{}');

            if (data.error) {
                console.error('Error loading messages:', data.error);
                return;
            }

            let messages = Array.isArray(data.messages) ? data.messages : [];
            messages.reverse(); // DESC to ASC

            this.hasMoreMessages = data.has_more || messages.length === 100;

            if (messages.length > 0) {
                this.firstMessageId = messages[0].id;
                this.lastMessageId = Math.max(this.lastMessageId, ...messages.map(m => Number(m.id || 0)));
            }

            this.renderMessages(messages);
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Messages fetch timed out');
            } else {
                console.error('Failed to load messages:', error);
            }
        } finally {
            this.isLoading = false;
        }
    }

    renderMessages(messages) {
        const container = document.getElementById('messagesArea');
        if (!container) return;

        const performRender = () => {
            if (!Array.isArray(messages) || messages.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; color: #718096; margin-top: 50px;">
                        <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">💬</div>
                        <h3 style="font-weight: 500; margin-bottom: 10px;">No messages yet</h3>
                        <p style="opacity: 0.8;">Start the conversation by sending a message!</p>
                    </div>
                `;
                return;
            }

            const groupedMessages = this.groupMessages(messages);

            // Use fragment for batch insert
            const fragment = document.createDocumentFragment();
            groupedMessages.forEach(group => {
                const div = document.createElement('div');
                if (group.type === 'date') {
                    div.className = 'date-separator';
                    div.innerHTML = `<span>${group.date}</span>`;
                } else if (group.type === 'message-group') {
                    div.className = 'message-group';
                    div.innerHTML = group.messages.map(msg => `
                        <div class="message ${msg.is_me ? 'sent' : 'received'}" data-created-at="${this.escapeHtml(msg.created_at)}">
                            <div class="message-text">${this.escapeHtml(msg.message)}</div>
                            <div class="message-time">
                                ${this.formatTime(msg.created_at)}
                                ${msg.is_me ? '<span class="message-status delivered">✓✓</span>' : ''}
                            </div>
                        </div>
                    `).join('');
                }
                fragment.appendChild(div);
            });

            requestAnimationFrame(() => {
                container.innerHTML = '';
                container.appendChild(fragment);
                this.scrollToBottom();
                this.addScrollToBottomButton();
            });
        };

        if ('requestIdleCallback' in window) {
            requestIdleCallback(performRender);
        } else {
            setTimeout(performRender, 0);
        }
    }

    groupMessages(messages) {
        const groups = [];
        let currentGroup = null;
        let lastDate = null;

        messages.forEach((message, index) => {
            const messageDate = new Date(message.created_at).toDateString();
            const prevMessage = messages[index - 1];

            // Date separator when date changes
            if (messageDate !== lastDate) {
                groups.push({
                    type: 'date',
                    date: this.formatDate(message.created_at)
                });
                lastDate = messageDate;
            }

            const shouldStartNewGroup = !prevMessage ||
                prevMessage.is_me !== message.is_me ||
                (new Date(message.created_at) - new Date(prevMessage.created_at)) > 300000; // 5 minutes

            if (shouldStartNewGroup) {
                if (currentGroup) groups.push(currentGroup);
                currentGroup = {
                    type: 'message-group',
                    messages: [message]
                };
            } else {
                currentGroup.messages.push(message);
            }
        });

        if (currentGroup && currentGroup.messages.length > 0) groups.push(currentGroup);

        return groups;
    }

    formatDate(timestamp) {
        const date = new Date(timestamp);
        const today = new Date();
        const yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        
        if (date.toDateString() === today.toDateString()) {
            return 'Today';
        } else if (date.toDateString() === yesterday.toDateString()) {
            return 'Yesterday';
        } else {
            return date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
        }
    }

    addScrollToBottomButton() {
        const container = document.getElementById('messagesArea');
        if (!container) return;
        
        // Remove existing button to avoid duplicates
        const existingButton = container.parentNode.querySelector('.scroll-to-bottom');
        if (existingButton) existingButton.remove();
        
        const button = document.createElement('button');
        button.className = 'scroll-to-bottom';
        button.innerHTML = '↓';
        button.title = 'Scroll to bottom';
        button.onclick = () => this.scrollToBottom();
        
        container.parentNode.appendChild(button);
    }

    addMessageToChat(messageData) {
        const container = document.getElementById('messagesArea');
        if (!container) return;

        if (container.querySelector('h3')) {
            container.innerHTML = '';
        }

        requestAnimationFrame(() => {
            // Check if need new date separator
            const newDate = this.formatDate(messageData.created_at);
            const lastDateEl = container.querySelector('.date-separator:last-of-type span');
            const lastDate = lastDateEl ? lastDateEl.textContent : null;

            if (newDate !== lastDate) {
                const dateDiv = document.createElement('div');
                dateDiv.className = 'date-separator';
                dateDiv.innerHTML = `<span>${newDate}</span>`;
                container.appendChild(dateDiv);
            }

            // Check if can merge with last group
            let appendTarget = container;
            const lastGroup = container.lastElementChild;
            if (lastGroup && lastGroup.classList.contains('message-group')) {
                const lastMsg = lastGroup.lastElementChild;
                if (lastMsg && lastMsg.classList.contains('message')) {
                    const lastIsMe = lastMsg.classList.contains('sent');
                    const lastTime = new Date(lastMsg.dataset.createdAt).getTime();
                    const newTime = new Date(messageData.created_at).getTime();
                    if (lastIsMe === messageData.is_me && (newTime - lastTime) < 300000) {
                        appendTarget = lastGroup;
                    }
                }
            }

            const messageElement = document.createElement('div');
            messageElement.className = `message ${messageData.is_me ? 'sent' : 'received'}`;
            messageElement.dataset.createdAt = messageData.created_at;
            messageElement.innerHTML = `
                <div class="message-text">${this.escapeHtml(messageData.message)}</div>
                <div class="message-time">
                    ${this.formatTime(messageData.created_at)}
                    ${messageData.is_me ? '<span class="message-status delivered">✓✓</span>' : ''}
                </div>
            `;

            if (appendTarget === container) {
                const newGroup = document.createElement('div');
                newGroup.className = 'message-group';
                newGroup.appendChild(messageElement);
                container.appendChild(newGroup);
            } else {
                appendTarget.appendChild(messageElement);
            }

            this.scrollToBottom();
        });
    }

    async handleSendMessage(e) {
        // e could be an event or fake object from key handler; be defensive
        try {
            if (e && typeof e.preventDefault === 'function') e.preventDefault();
        } catch (_) {}

        const messageInput = document.getElementById('messageInput');
        const message = messageInput ? messageInput.value.trim() : '';
        const conversationId = this.currentConversationId || (document.getElementById('conversationId') ? document.getElementById('conversationId').value : null);

        if (!message || !conversationId) return;

        // Disable UI
        const sendButton = document.getElementById('sendButton');
        let originalText = '';
        if (sendButton) {
            originalText = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '⏳';
        }

        try {
            const csrf = (window.appData && window.appData.csrfToken) ? window.appData.csrfToken : null;

            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const response = await fetch('send_message.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    ...(csrf ? { 'X-CSRF-Token': csrf } : {})
                },
                body: JSON.stringify({ conversation_id: conversationId, message }),
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text || '{}');
            } catch (jsonErr) {
                console.error('Invalid JSON from send_message.php:', jsonErr, text);
                alert('Server error (invalid response).');
                return;
            }

            if (data.success) {
                // Clear input
                if (messageInput) {
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                }

                // Optionally add the returned message immediately to chat if server returns it
                if (data.message) {
                    this.addMessageToChat(data.message);
                    // update lastMessageId if server returned ID
                    if (data.message.id) this.lastMessageId = Math.max(this.lastMessageId, Number(data.message.id));
                } else {
                    // fallback: refresh messages
                    await this.loadMessages(conversationId);
                }

                // Force refresh conversations on send
                await this.loadConversations(true);
            } else {
                alert('Failed to send message: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Send message timed out');
            } else {
                console.error('Error sending message:', error);
            }
            alert('Network error. Please check your connection.');
        } finally {
            // Re-enable UI
            if (sendButton) {
                sendButton.disabled = false;
                sendButton.innerHTML = originalText;
            }
        }
    }

    // openConversation optionally accepts the clicked element to manage UI state
    async openConversation(friendId, conversationId = null, clickedElement = null) {
        console.log('🔍 openConversation called with:', { friendId, conversationId, clickedElement });

        // Mobile view show/hide
        if (window.innerWidth <= 768) {
            const sidebar = document.querySelector('.conversations-sidebar');
            const chatArea = document.querySelector('.chat-area');
            if (sidebar) sidebar.style.display = 'none';
            if (chatArea) chatArea.style.display = 'flex';
        }

        // Remove active from all
        document.querySelectorAll('.conversation-item.active').forEach(item => {
            item.classList.remove('active');
        });

        // Activate clicked element if provided
        if (clickedElement) {
            clickedElement.classList.add('active');
        } else if (friendId) {
            const found = document.querySelector(`.conversation-item[data-friend-id="${this.escapeHtml(friendId)}"]`);
            if (found) {
                found.classList.add('active');
                clickedElement = found;
            }
        }

        try {
            let finalConversationId = conversationId;

            if (!finalConversationId) {
                // create conversation on server
                console.log('📝 Creating new conversation...');
                const csrf = (window.appData && window.appData.csrfToken) ? window.appData.csrfToken : null;

                const params = new URLSearchParams();
                params.append('friend_id', friendId);
                // include CSRF if your server requires it in POST body for create
                if (csrf) params.append('csrf_token', csrf);

                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 5000);

                const response = await fetch('create_conversation.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString(),
                    signal: controller.signal
                });
                clearTimeout(timeoutId);

                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text || '{}');
                } catch (jsonErr) {
                    console.error('Invalid JSON from create_conversation.php:', jsonErr, text);
                    throw new Error('Invalid server response while creating conversation');
                }

                if (data.error) throw new Error(data.error || 'Failed to create conversation');
                finalConversationId = data.conversation_id;
            }

            console.log('🎯 Final conversation ID:', finalConversationId);

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('friend_id', friendId);
            window.history.replaceState({}, '', url);

            this.currentConversationId = finalConversationId;
            const convIdInput = document.getElementById('conversationId');
            if (convIdInput) convIdInput.value = finalConversationId;

            // Create chat area dynamically if necessary
            const chatCreated = this.createChatArea(friendId, finalConversationId);

            if (chatCreated) {
                await this.updateChatHeader(friendId);
                await this.loadMessages(finalConversationId);
            } else {
                console.error('❌ Failed to create chat area');
            }

            // ensure polling is running for this conversation
            this.startPolling();
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Create conversation timed out');
            } else {
                console.error('❌ Error opening conversation:', error);
            }
            if (clickedElement) clickedElement.classList.remove('active');
        }
    }

    startPolling() {
        // Prevent multiple pollers
        if (this.pollingTimeout) return;

        console.log('🚨 STARTING MESSAGE POLLING');

        const poll = async () => {
            try {
                await this.checkNewMessages();
            } catch (error) {
                console.error('❌ Polling error:', error);
            } finally {
                // schedule next tick
                this.pollingTimeout = setTimeout(poll, 5000);
            }
        };

        // Kick off first tick immediately
        poll();

        // Start separate conversations polling
        this.startConversationsPolling();
    }

    startConversationsPolling() {
        // Prevent multiple pollers
        if (this.conversationsPollingTimeout) return;

        console.log('🚨 STARTING CONVERSATIONS POLLING (30s)');

        const poll = async () => {
            try {
                await this.loadConversations();
            } catch (error) {
                console.error('❌ Conversations polling error:', error);
            } finally {
                // schedule next tick
                this.conversationsPollingTimeout = setTimeout(poll, 30000);
            }
        };

        // Kick off first tick immediately
        poll();
    }

    stopPolling() {
        if (this.pollingTimeout) {
            clearTimeout(this.pollingTimeout);
            this.pollingTimeout = null;
        }
        if (this.conversationsPollingTimeout) {
            clearTimeout(this.conversationsPollingTimeout);
            this.conversationsPollingTimeout = null;
        }
    }

    async checkNewMessages() {
        // If no open conversation, still update conversations
        if (!this.currentConversationId) {
            await this.loadConversations();
            return;
        }

        if (this.isLoading) return;

        let hasNew = false;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const url = `get_messages.php?conversation_id=${encodeURIComponent(this.currentConversationId)}&after=${encodeURIComponent(this.lastMessageId)}`;
            const resp = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);

            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text || '{}');
            } catch (jsonErr) {
                console.error('Invalid JSON from get_messages.php (poll):', jsonErr, text);
                return;
            }

            if (data.error || !Array.isArray(data.messages)) return;

            const newMessages = data.messages.filter(msg => Number(msg.id || 0) > Number(this.lastMessageId || 0));
            
            if (newMessages.length > 0) {
                newMessages.forEach(msg => this.addMessageToChat(msg));
                this.lastMessageId = Math.max(this.lastMessageId, ...data.messages.map(m => Number(m.id || 0)));
                hasNew = true;
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('New messages check timed out');
            } else {
                console.error('Error checking new messages:', error);
            }
        }

        // Only refresh conversations if new messages (for current conv unread/preview)
        if (hasNew) {
            await this.loadConversations(true);
        }
    }

    async loadOlderMessages() {
        if (!this.currentConversationId || !this.hasMoreMessages || this.isLoadingOlder) return;

        this.isLoadingOlder = true;

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const url = `get_messages.php?conversation_id=${encodeURIComponent(this.currentConversationId)}&before=${encodeURIComponent(this.firstMessageId)}&limit=50`;
            const resp = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);

            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text || '{}');
            } catch (jsonErr) {
                console.error('Invalid JSON from get_messages.php (older):', jsonErr, text);
                return;
            }

            if (data.error) {
                console.error('Error loading older messages:', data.error);
                return;
            }

            let messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length === 0) {
                this.hasMoreMessages = false;
                return;
            }

            this.hasMoreMessages = data.has_more || (messages.length === 50);

            if (messages.length > 0) {
                this.firstMessageId = messages[0].id;
            }

            messages.reverse(); // DESC to ASC

            this.prependMessages(messages);
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Older messages fetch timed out');
            } else {
                console.error('Failed to load older messages:', error);
            }
        } finally {
            this.isLoadingOlder = false;
        }
    }

    prependMessages(messages) {
        const grouped = this.groupMessages(messages);
        const container = document.getElementById('messagesArea');
        if (!container) return;

        const fragment = document.createDocumentFragment();

        grouped.forEach(group => {
            if (group.type === 'date') {
                const div = document.createElement('div');
                div.className = 'date-separator';
                div.innerHTML = `<span>${group.date}</span>`;
                fragment.appendChild(div);
            } else if (group.type === 'message-group') {
                const div = document.createElement('div');
                div.className = 'message-group';
                div.innerHTML = group.messages.map(msg => `
                    <div class="message ${msg.is_me ? 'sent' : 'received'}" data-created-at="${this.escapeHtml(msg.created_at)}">
                        <div class="message-text">${this.escapeHtml(msg.message)}</div>
                        <div class="message-time">
                            ${this.formatTime(msg.created_at)}
                            ${msg.is_me ? '<span class="message-status delivered">✓✓</span>' : ''}
                        </div>
                    </div>
                `).join('');
                fragment.appendChild(div);
            }
        });

        const oldScrollHeight = container.scrollHeight;
        const oldScrollTop = container.scrollTop;
        container.insertBefore(fragment, container.firstChild);
        container.scrollTop = oldScrollTop + (container.scrollHeight - oldScrollHeight);
    }

    handleChatScroll() {
        const container = document.getElementById('messagesArea');
        if (!container) return;

        const isNearBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 100;
        const button = container.parentNode.querySelector('.scroll-to-bottom');
        if (button) {
            button.style.opacity = isNearBottom ? '0' : '1';
            button.style.pointerEvents = isNearBottom ? 'none' : 'all';
        }

        if (container.scrollTop < 100 && this.hasMoreMessages && !this.isLoadingOlder) {
            this.loadOlderMessages();
        }
    }

    scrollToBottom() {
        const container = document.getElementById('messagesArea');
        if (container) container.scrollTop = container.scrollHeight;
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;

        if (isNaN(date.getTime())) return '';

        if (diff < 60000) {
            return 'Just now';
        } else if (diff < 3600000) {
            return Math.floor(diff / 60000) + 'm ago';
        } else if (diff < 86400000) {
            return Math.floor(diff / 3600000) + 'h ago';
        } else if (diff < 604800000) {
            return Math.floor(diff / 86400000) + 'd ago';
        } else {
            return date.toLocaleDateString();
        }
    }

    escapeHtml(text = '') {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async updateChatHeader(friendId) {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);

            const resp = await fetch(`get_friend_info.php?friend_id=${encodeURIComponent(friendId)}`, { credentials: 'same-origin', signal: controller.signal });
            clearTimeout(timeoutId);

            const text = await resp.text();
            let data;
            try {
                data = JSON.parse(text || '{}');
            } catch (jsonErr) {
                console.error('Invalid JSON from get_friend_info.php:', jsonErr, text);
                return;
            }

            if (data.error) throw new Error(data.error);

            const chatHeader = document.querySelector('.chat-header');
            if (!chatHeader) return;

            const avatarStyle = data.profile_picture ? `background-image: url(../../uploads/profile_pictures/${this.escapeHtml(data.profile_picture)});` : '';
            chatHeader.innerHTML = `
                <div class="chat-header-avatar" style="${avatarStyle}">
                    ${data.profile_picture ? '' : (data.name ? this.escapeHtml(data.name.charAt(0).toUpperCase()) : '')}
                </div>
                <div class="chat-header-info">
                    <h3>${this.escapeHtml(data.name)}</h3>
                    <p>● Online</p>
                </div>
            `;
        } catch (error) {
            if (error.name === 'AbortError') {
                console.warn('Chat header fetch timed out');
            } else {
                console.error('Error updating chat header:', error);
            }
        }
    }

    openConversationFromUrl(friendId) {
        const conversation = this.findConversationByFriendId(friendId);
        if (conversation) {
            const item = document.querySelector(`.conversation-item[data-friend-id="${this.escapeHtml(friendId)}"]`);
            this.openConversation(friendId, conversation.conversation_id, item || null);
        } else {
            // If not in loaded conversations, attempt to open anyway (server will create if allowed)
            this.openConversation(friendId, null, null);
        }
    }

    findConversationByFriendId(friendId) {
        return (this.conversationsData || []).find(conv => String(conv.friend_id) === String(friendId));
    }

    // Backwards-compatible alias
    openConversationWithoutEvent(friendId, conversationId) {
        this.openConversation(friendId, conversationId, null);
    }

    createChatArea(friendId, conversationId) {
        console.log('🏗️ Creating chat area dynamically...');
        
        const chatArea = document.querySelector('.chat-area');
        if (!chatArea) {
            console.error('❌ Chat area container not found');
            return false;
        }

        // Use client-side CSRF token if provided
        const csrfToken = (window.appData && window.appData.csrfToken) ? window.appData.csrfToken : '';

        chatArea.innerHTML = `
            <div class="chat-header">
                <div class="chat-header-avatar" id="dynamicChatAvatar"></div>
                <div class="chat-header-info">
                    <h3 id="dynamicChatName">Loading...</h3>
                    <p>● Online</p>
                </div>
            </div>

            <div class="messages-area" id="messagesArea">
                <div id="debugInfo" style="display: none; background: #ffebee; padding: 10px; margin: 10px; border-radius: 5px;">
                    Debug: Conversation ID: <span id="debugConvId"></span><br>
                    Status: <span id="debugStatus">Loading...</span>
                </div>
            </div>

            <div class="message-input-area">
                <form class="message-input-form" id="messageForm" onsubmit="return false;" autocomplete="off">
                    <input type="hidden" id="conversationId" value="${this.escapeHtml(conversationId || '')}">
                    <input type="hidden" name="csrf_token" value="${this.escapeHtml(csrfToken)}">
                    <textarea 
                        class="message-input" 
                        id="messageInput" 
                        name="message"
                        placeholder="Type a message..." 
                        rows="1"
                        required
                    ></textarea>
                    <button type="submit" class="send-button" id="sendButton" aria-label="Send message">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22,2 15,22 11,13 2,9"></polygon>
                        </svg>
                    </button>
                </form>
            </div>
        `;

        // After injecting HTML, no need to rebind global listeners (they are delegated)
        // Setup scroll listener for infinite scroll
        this.setupChatListeners();

        console.log('✅ Chat area created successfully');
        return true;
    }

    setupChatListeners() {
        const container = document.getElementById('messagesArea');
        if (container && !container._hasScrollListener) {
            container.addEventListener('scroll', this.throttle(this.handleChatScroll.bind(this), 200));
            container._hasScrollListener = true;
        }
    }
}

// Initialize message manager when DOM is loaded
let messageManager = null;
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing MessageManager...');
    messageManager = new MessageManager();
    messageManager.init();
});

console.log('messages.js loaded successfully');