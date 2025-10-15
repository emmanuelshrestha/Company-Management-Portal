// messages.js - Chat functionality (cleaned & modernized)
class MessageManager {
    constructor() {
        this.currentConversationId = null;
        this.pollingInterval = null;
        this.lastMessageId = 0;
        this.isLoading = false;
        this.conversationsData = [];
    }

    // Called when DOM is ready
    async init() {
        console.log('MessageManager initialized');
        this.initializeEventListeners();
        await this.loadConversations();

        // Wait for DOM to be completely ready
        setTimeout(() => {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('friend_id')) {
                const friendId = urlParams.get('friend_id');
                console.log('🔄 Opening conversation from URL:', friendId);
                this.openConversationFromUrl(friendId);
            }
        }, 500); // Increased delay to ensure PHP rendering is complete
    }

    initializeEventListeners() {
        // Message form submission
        const messageForm = document.getElementById('messageForm');
        if (messageForm) {
            messageForm.addEventListener('submit', (e) => this.handleSendMessage(e));
        }

        // Message input auto-resize and Enter-to-send
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', (e) => this.autoResizeTextarea(e));
            messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.handleSendMessage(e);
                }
            });
        }

        // Browser back button handling
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
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
    }

    async loadConversations() {
        console.log('loadConversations called');

        const container = document.getElementById('conversationsList');
        if (!container) {
            console.error('conversationsList container not found!');
            return;
        }

        try {
            const response = await fetch('get_conversations.php');
            const data = await response.json();

            if (data.error) {
                console.error('API Error:', data.error);
                return;
            }

            this.conversationsData = data.conversations || [];
            this.renderConversations(this.conversationsData);
        } catch (error) {
            console.error('Load conversations error:', error);
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
            return;
        }

        container.innerHTML = conversations.map(conv => {
            const activeClass = this.currentConversationId == conv.conversation_id ? 'active' : '';
            const avatarStyle = conv.profile_picture ? `background-image: url(../../uploads/profile_pictures/${conv.profile_picture});` : '';
            const avatarContent = conv.profile_picture ? '' : (conv.friend_name ? conv.friend_name.charAt(0).toUpperCase() : '');
            const previewText = conv.last_message
                ? ((conv.is_sent_by_me ? 'You: ' : '') + this.escapeHtml(
                    conv.last_message.length > 30 ? conv.last_message.substring(0, 30) + '...' : conv.last_message
                ))
                : 'Start a conversation';
            const timeHtml = conv.last_message_time
                ? `<div class="conversation-time">${this.formatTime(conv.last_message_time)}</div>`
                : (conv.last_message ? `<div class="conversation-time">Just now</div>` : '');
            const unreadDot = conv.unread ? '<div class="conversation-unread-dot" aria-hidden="true"></div>' : '';

            return `
                <div class="conversation-item ${activeClass}" 
                    data-friend-id="${conv.friend_id}" 
                    data-conversation-id="${conv.conversation_id || ''}">
                    <div class="conversation-avatar" style="${avatarStyle}">${avatarContent}</div>
                    <div class="conversation-info">
                        <div class="conversation-name">${this.escapeHtml(conv.friend_name)}</div>
                        <div class="conversation-preview">${previewText}</div>
                        ${timeHtml}
                    </div>
                    ${unreadDot}
                </div>
            `;
        }).join('');

        // SIMPLIFIED CLICK HANDLER - This should fix the issue
        container.addEventListener('click', (event) => {
            const conversationItem = event.target.closest('.conversation-item');
            if (conversationItem) {
                const friendId = conversationItem.getAttribute('data-friend-id');
                const conversationId = conversationItem.getAttribute('data-conversation-id') || null;
                console.log('🎯 Conversation clicked:', friendId, conversationId);
                this.openConversation(friendId, conversationId, conversationItem);
            }
        });
    }

    async loadMessages(conversationId) {
        console.log('🚀 loadMessages called for conversation:', conversationId);

        // FIX: Ensure chat area is visible
        const chatArea = document.querySelector('.chat-area');
        const messagesArea = document.getElementById('messagesArea');
        
        if (chatArea) {
            chatArea.style.display = 'flex';
            console.log('✅ Made chat area visible');
        }
        
        if (!messagesArea) {
            console.error('❌ messagesArea still not found after making chat area visible');
            return;
        }

        if (this.isLoading) return;
        this.isLoading = true;

        const debugInfo = document.getElementById('debugInfo');
        const debugConvId = document.getElementById('debugConvId');
        const debugStatus = document.getElementById('debugStatus');

        if (debugInfo) {
            debugInfo.style.display = 'block';
            if (debugConvId) debugConvId.textContent = conversationId;
            if (debugStatus) debugStatus.textContent = 'Fetching messages...';
        }

        try {
            const response = await fetch(`get_messages.php?conversation_id=${conversationId}`);
            const data = await response.json();

            if (data.error) {
                console.error('Error loading messages:', data.error);
                if (debugStatus) debugStatus.textContent = 'Error: ' + data.error;
                return;
            }

            const messages = data.messages || [];
            if (debugStatus) debugStatus.textContent = `Loaded ${messages.length} messages`;

            this.renderMessages(messages);
            this.lastMessageId = messages.length > 0 ? Math.max(...messages.map(m => m.id)) : this.lastMessageId;
        } catch (error) {
            console.error('Failed to load messages:', error);
            if (debugStatus) debugStatus.textContent = 'Fetch error: ' + (error.message || error);
        } finally {
            this.isLoading = false;
        }
    }

    renderMessages(messages) {
        const container = document.getElementById('messagesArea');
        if (!container) return;

        if (!Array.isArray(messages) || messages.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; color: #718096; margin-top: 50px;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">💬</div>
                    <h3>No messages yet</h3>
                    <p>Start the conversation!</p>
                </div>
            `;
            return;
        }

        container.innerHTML = messages.map(msg => `
            <div class="message ${msg.is_me ? 'sent' : 'received'}">
                <div class="message-text">${this.escapeHtml(msg.message)}</div>
                <div class="message-time">${this.formatTime(msg.created_at)}</div>
            </div>
        `).join('');

        this.scrollToBottom();
    }

    async handleSendMessage(e) {
        e.preventDefault();

        const messageInput = document.getElementById('messageInput');
        const message = messageInput ? messageInput.value.trim() : '';
        const conversationId = this.currentConversationId;

        if (!message || !conversationId) return;

        // Disable UI
        const sendButton = document.getElementById('sendButton');
        const originalText = sendButton.innerHTML;
        if (sendButton) {
            sendButton.disabled = true;
            sendButton.innerHTML = '⏳';
        }

        try {
            const response = await fetch('send_message.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ conversation_id: conversationId, message })
            });

            const data = await response.json();

            if (data.success) {
                // Clear input
                if (messageInput) {
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                }
                
                // Refresh conversations list
                await this.loadConversations();
            } else {
                alert('Failed to send message: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error sending message:', error);
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

        // FIX: Check if we're on mobile and need to show chat area
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

        // If no clickedElement but we have friendId, find and activate the correct item
        if (!clickedElement && friendId) {
            clickedElement = document.querySelector(`.conversation-item[data-friend-id="${friendId}"]`);
            if (clickedElement) {
                clickedElement.classList.add('active');
                clickedElement.style.opacity = '0.7';
            }
        }

        // Add loading style to clicked element if provided
        if (clickedElement) {
            clickedElement.classList.add('active');
            clickedElement.style.opacity = '0.7';
        }

        try {
            let finalConversationId = conversationId;
            console.log('🔄 Getting conversation ID...');

            if (!finalConversationId) {
                console.log('📝 Creating new conversation...');
                const response = await fetch('create_conversation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `friend_id=${encodeURIComponent(friendId)}`
                });

                const data = await response.json();
                console.log('📨 create_conversation.php response:', data);
                
                if (data.error) throw new Error(data.error);
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

            // Create chat area dynamically
            console.log('👤 Creating chat area...');
            const chatCreated = this.createChatArea(friendId, finalConversationId);

            if (chatCreated) {
                console.log('👤 Updating chat header...');
                await this.updateChatHeader(friendId);
                
                console.log('📨 Loading messages...');
                await this.loadMessages(finalConversationId);
            } else {
                console.error('❌ Failed to create chat area');
            }
            
            console.log('🔄 Starting polling...');
            this.startPolling();
            
        } catch (error) {
            console.error('❌ Error opening conversation:', error);
            if (clickedElement) clickedElement.classList.remove('active');
        } finally {
            if (clickedElement) clickedElement.style.opacity = '1';
        }
    }

    startPolling() {
        if (this.pollingInterval) clearInterval(this.pollingInterval);

        this.pollingInterval = setInterval(() => this.checkNewMessages(), 2000); // 2 seconds
    }

    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }

    async checkNewMessages() {
        if (!this.currentConversationId || this.isLoading) return;

        try {
            const response = await fetch(`get_messages.php?conversation_id=${this.currentConversationId}&after=${this.lastMessageId}`);
            const data = await response.json();

            if (data.error || !Array.isArray(data.messages)) return;

            const newMessages = data.messages.filter(msg => msg.id > this.lastMessageId);
            if (newMessages.length > 0) {
                newMessages.forEach(msg => this.addMessageToChat(msg));
                this.lastMessageId = Math.max(...data.messages.map(m => m.id));
                this.loadConversations(); // Refresh conversation list
            }
        } catch (error) {
            console.error('Error checking new messages:', error);
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
            const response = await fetch(`get_friend_info.php?friend_id=${friendId}`);
            const data = await response.json();

            if (data.error) throw new Error(data.error);

            const chatHeader = document.querySelector('.chat-header');
            if (!chatHeader) return;

            chatHeader.innerHTML = `
                <div class="chat-header-avatar" style="${data.profile_picture ? `background-image: url(../../uploads/profile_pictures/${data.profile_picture});` : ''}">
                    ${data.profile_picture ? '' : (data.name ? data.name.charAt(0).toUpperCase() : '')}
                </div>
                <div class="chat-header-info">
                    <h3>${this.escapeHtml(data.name)}</h3>
                    <p>● Online</p>
                </div>
            `;
        } catch (error) {
            console.error('Error updating chat header:', error);
        }
    }

    openConversationFromUrl(friendId) {
        const conversation = this.findConversationByFriendId(friendId);
        if (conversation) {
            // find corresponding conversation DOM item if exists
            const item = document.querySelector(`.conversation-item[data-friend-id="${friendId}"]`);
            this.openConversation(friendId, conversation.conversation_id, item || null);
        } else {
            // If not in loaded conversations, still attempt to open (will create if needed)
            this.openConversation(friendId, null, null);
        }
    }

    findConversationByFriendId(friendId) {
        return (this.conversationsData || []).find(conv => String(conv.friend_id) === String(friendId));
    }

    // Kept for backward compatibility if needed
    openConversationWithoutEvent(friendId, conversationId) {
        // Trigger openConversation without a click element
        this.openConversation(friendId, conversationId, null);
    }

    addMessageToChat(messageData) {
        const container = document.getElementById('messagesArea');
        if (!container) return;

        // Remove "no messages" text if present
        if (container.querySelector('h3')) {
            container.innerHTML = '';
        }

        const messageElement = document.createElement('div');
        messageElement.className = `message ${messageData.is_me ? 'sent' : 'received'}`;
        messageElement.innerHTML = `
            <div class="message-text">${this.escapeHtml(messageData.message)}</div>
            <div class="message-time">${this.formatTime(messageData.created_at)}</div>
        `;

        container.appendChild(messageElement);
        this.scrollToBottom();
    }

    createChatArea(friendId, conversationId) {
        console.log('🏗️ Creating chat area dynamically...');
        
        const chatArea = document.querySelector('.chat-area');
        if (!chatArea) {
            console.error('❌ Chat area container not found');
            return false;
        }
        
        // Create the chat area HTML structure
        chatArea.innerHTML = `
            <div class="chat-header">
                <div class="chat-header-avatar" id="dynamicChatAvatar">
                    <!-- Avatar will be filled by updateChatHeader -->
                </div>
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
                <!-- Messages will be loaded via JavaScript -->
            </div>

            <div class="message-input-area">
                <form class="message-input-form" id="messageForm">
                    <input type="hidden" id="conversationId" value="${conversationId}">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <textarea 
                        class="message-input" 
                        id="messageInput" 
                        placeholder="Type a message..." 
                        rows="1"
                    ></textarea>
                    <button type="submit" class="send-button" id="sendButton">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22,2 15,22 11,13 2,9"></polygon>
                        </svg>
                    </button>
                </form>
            </div>
        `;
    
        // Re-initialize event listeners for the new form
        this.initializeEventListeners();
        
        console.log('✅ Chat area created successfully');
        return true;
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