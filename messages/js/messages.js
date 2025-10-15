// messages.js - Chat functionality
class MessageManager {
    constructor() {
        this.currentConversationId = null;
        this.pollingInterval = null;
        this.lastMessageId = 0;
        this.isLoading = false;
        
        // Don't load anything in constructor - wait for DOM ready
    }

    // Add an init method that gets called when DOM is ready
    async init() {
        console.log('MessageManager initialized');
        this.initializeEventListeners();
        await this.loadConversations(); // Wait for conversations to load

        // Now conversations are loaded, try to open the one from URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('friend_id')) {
            const friendId = urlParams.get('friend_id');
            // Find the conversation data and open it directly
            this.openConversationFromUrl(friendId);
        }
    }

    initializeEventListeners() {
        // Message form submission
        const messageForm = document.getElementById('messageForm');
        if (messageForm) {
            messageForm.addEventListener('submit', (e) => this.handleSendMessage(e));
        }

        // Message input auto-resize
        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            messageInput.addEventListener('input', this.autoResizeTextarea.bind(this));
        }

        // Enter key to send (Shift+Enter for new line)
        if (messageInput) {
            messageInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.handleSendMessage(e);
                }
            });
        }
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
        
        console.log('Container found, loading...');
        
        try {
            const response = await fetch('get_conversations.php');
            console.log('Fetch response:', response);
            
            const data = await response.json();
            console.log('Conversations data received:', data);
            
            if (data.error) {
                console.error('API Error:', data.error);
                return;
            }

            console.log(`Rendering ${data.conversations.length} conversations`);
            this.conversationsData = data.conversations;
            this.renderConversations(data.conversations);
            
        } catch (error) {
            console.error('Load conversations error:', error);
        }
    }

    renderConversations(conversations) {
    const container = document.getElementById('conversationsList');
    
    if (conversations.length === 0) {
        container.innerHTML = `
            <div style="padding: 40px 20px; text-align: center; color: #718096;">
                <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;">💬</div>
                <h3>No conversations yet</h3>
                <p>Start a conversation with your friends!</p>
            </div>
        `;
        return;
    }

    container.innerHTML = conversations.map(conv => `
        <div class="conversation-item ${this.currentConversationId == conv.conversation_id ? 'active' : ''}" 
             onclick="messageManager.openConversation(${conv.friend_id}, ${conv.conversation_id})">
            <div class="conversation-avatar" style="${conv.profile_picture ? `background-image: url(../../uploads/profile_pictures/${conv.profile_picture});` : ''}">
                ${conv.profile_picture ? '' : conv.friend_name.charAt(0).toUpperCase()}
            </div>
            <div class="conversation-info">
                <div class="conversation-name">${this.escapeHtml(conv.friend_name)}</div>
                <div class="conversation-preview">
                    ${conv.last_message ? 
                        (conv.is_sent_by_me ? 'You: ' : '') + this.escapeHtml(
                            conv.last_message.length > 30 ? 
                            conv.last_message.substring(0, 30) + '...' : 
                            conv.last_message
                        ) : 
                        'Start a conversation'}
                </div>
                ${conv.last_message_time ? `
                    <div class="conversation-time">
                        ${this.formatTime(conv.last_message_time)}
                    </div>
                ` : conv.last_message ? '<div class="conversation-time">Just now</div>' : ''}
            </div>
            ${conv.unread ? '<div style="width: 8px; height: 8px; background: #667eea; border-radius: 50%; margin-left: auto;"></div>' : ''}
        </div>
    `).join('');
    }

    async loadMessages(conversationId) {
        if (this.isLoading) return;
        
        this.isLoading = true;
        const messagesArea = document.getElementById('messagesArea');
        
        // Show debug info
        const debugInfo = document.getElementById('debugInfo');
        const debugConvId = document.getElementById('debugConvId');
        const debugStatus = document.getElementById('debugStatus');
        
        if (debugInfo) {
            debugInfo.style.display = 'block';
            debugConvId.textContent = conversationId;
            debugStatus.textContent = 'Fetching messages...';
        }
        
        try {
            console.log('Loading messages for conversation:', conversationId);
            const response = await fetch(`get_messages.php?conversation_id=${conversationId}`);
            console.log('Response status:', response.status);
            
            const data = await response.json();
            console.log('Response data:', data);
            
            if (data.error) {
                console.error('Error loading messages:', data.error);
                if (debugStatus) debugStatus.textContent = 'Error: ' + data.error;
                return;
            }

            if (debugStatus) debugStatus.textContent = `Loaded ${data.messages ? data.messages.length : 0} messages`;
            
            this.renderMessages(data.messages);
            this.lastMessageId = data.messages && data.messages.length > 0 ? Math.max(...data.messages.map(m => m.id)) : 0;
            
        } catch (error) {
            console.error('Failed to load messages:', error);
            if (debugStatus) debugStatus.textContent = 'Fetch error: ' + error.message;
        } finally {
            this.isLoading = false;
        }
    }

    renderMessages(messages) {
        const container = document.getElementById('messagesArea');
        
        if (messages.length === 0) {
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
        const message = messageInput.value.trim();
        const conversationId = this.currentConversationId;

        if (!message || !conversationId) return;

        const sendButton = document.getElementById('sendButton');
        sendButton.disabled = true;

        try {
            const response = await fetch('send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    message: message
                })
            });

            const data = await response.json();
            
            if (data.success) {
                messageInput.value = '';
                messageInput.style.height = 'auto';
                // this.addMessageToChat(data.message);
                this.loadConversations(); // Refresh conversation list
            } else {
                console.error('Failed to send message:', data.error);
                alert('Failed to send message. Please try again.');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('Error sending message. Please check your connection.');
        } finally {
            sendButton.disabled = false;
        }
    }

    addMessageToChat(messageData) {
        const container = document.getElementById('messagesArea');
        
        // Remove empty state if present
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

    async openConversation(friendId, conversationId) {
        // Remove active class from all items
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Add loading state to clicked item
        event.currentTarget.classList.add('active');
        event.currentTarget.style.opacity = '0.7';
        
        try {
            let finalConversationId = conversationId;
            
            // If no conversation exists, create one
            if (!conversationId) {
                const response = await fetch('create_conversation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `friend_id=${friendId}`
                });
                
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                
                finalConversationId = data.conversation_id;
            }
            
            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('friend_id', friendId);
            window.history.pushState({}, '', url);
            
            this.currentConversationId = finalConversationId;
            document.getElementById('conversationId').value = finalConversationId;

            // Update chat header with friend info
            this.updateChatHeader(friendId);
            
            // Load messages
            await this.loadMessages(finalConversationId);
            this.startPolling();
            
        } catch (error) {
            console.error('Error opening conversation:', error);
            // Remove active class on error
            event.currentTarget.classList.remove('active');
        } finally {
            // Remove loading state
            event.currentTarget.style.opacity = '1';
        }
    }

    startPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        
        this.pollingInterval = setInterval(() => {
            this.checkNewMessages();
        }, 3000); // Check every 3 seconds
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
            
            if (data.error || !data.messages) return;

            const newMessages = data.messages.filter(msg => msg.id > this.lastMessageId);
            
            if (newMessages.length > 0) {
                newMessages.forEach(msg => this.addMessageToChat(msg));
                this.lastMessageId = Math.max(...data.messages.map(m => m.id));
                this.loadConversations(); // Update conversation list
            }
        } catch (error) {
            console.error('Error checking new messages:', error);
        }
    }

    scrollToBottom() {
        const container = document.getElementById('messagesArea');
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) { // Less than 1 minute
            return 'Just now';
        } else if (diff < 3600000) { // Less than 1 hour
            return Math.floor(diff / 60000) + 'm ago';
        } else if (diff < 86400000) { // Less than 1 day
            return Math.floor(diff / 3600000) + 'h ago';
        } else if (diff < 604800000) { // Less than 1 week
            return Math.floor(diff / 86400000) + 'd ago';
        } else {
            return date.toLocaleDateString();
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async updateChatHeader(friendId) {
        try {
            // Get friend info from conversations data or make a separate API call
            const response = await fetch(`get_friend_info.php?friend_id=${friendId}`);
            const data = await response.json();
            
            if (data.error) throw new Error(data.error);
            
            // Update chat header
            const chatHeader = document.querySelector('.chat-header');
            chatHeader.innerHTML = `
                <div class="chat-header-avatar" style="${data.profile_picture ? `background-image: url(../../uploads/profile_pictures/${data.profile_picture});` : ''}">
                    ${data.profile_picture ? '' : data.name.charAt(0).toUpperCase()}
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
        // Find conversation data and open it directly instead of clicking DOM
        const conversation = this.findConversationByFriendId(friendId);
        if (conversation) {
            this.openConversation(friendId, conversation.conversation_id);
        }
    }

    findConversationByFriendId(friendId) {
        // You'll need to store conversations data when loading them
        return this.conversationsData.find(conv => conv.friend_id == friendId);
    }

    openConversationWithoutEvent(friendId, conversationId) {
        // Same as openConversation but without event handling
        let finalConversationId = conversationId;
        
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('friend_id', friendId);
        window.history.pushState({}, '', url);
        
        this.currentConversationId = finalConversationId;
        document.getElementById('conversationId').value = finalConversationId;

        // Update chat header with friend info
        this.updateChatHeader(friendId);
        
        // Load messages
        this.loadMessages(finalConversationId);
        this.startPolling();
        
        // Update UI active state
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.classList.remove('active');
        });
        // Find and activate the correct conversation item
        const conversationItem = document.querySelector(`[onclick*="openConversation(${friendId}"]`);
        if (conversationItem) {
            conversationItem.classList.add('active');
        }
    }
}

// Initialize message manager when DOM is loaded
let messageManager;

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing MessageManager...');
    messageManager = new MessageManager();
    messageManager.init(); // Call init instead of relying on constructor
});

// For debugging - check if script loaded
console.log('messages.js loaded successfully');

// Handle browser back button
window.addEventListener('popstate', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (!urlParams.has('friend_id')) {
        // Return to conversations list
        messageManager.stopPolling();
        messageManager.currentConversationId = null;
        
        // Mobile: switch back to conversations view
        if (window.innerWidth <= 768) {
            document.querySelector('.conversations-sidebar').style.display = 'flex';
            document.querySelector('.chat-area').style.display = 'none';
        }
        
        messageManager.loadConversations();
    }
});