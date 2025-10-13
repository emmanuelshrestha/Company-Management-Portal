// Debug version with better error handling
console.log('News Feed JS loaded');

// Like functionality
document.querySelectorAll('.like-btn').forEach(button => {
    button.addEventListener('click', async function() {
        const postId = this.getAttribute('data-post-id');
        const likeIcon = this.querySelector('.like-icon');
        const likeCount = this.closest('.post-card').querySelector('.like-count');
        
        console.log('Like clicked for post:', postId);
        
        try {
            const formData = new URLSearchParams();
            formData.append('action', 'like_post');
            formData.append('post_id', postId);
            formData.append('csrf_token', getCsrfToken());
            
            console.log('Sending like request:', {
                action: 'like_post',
                post_id: postId,
                csrf_token: getCsrfToken()
            });

            const response = await fetch('news_feed.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            });

            console.log('Response status:', response.status);
            const data = await response.json();
            console.log('Response data:', data);

            if (data.success) {
                if (data.liked) {
                    likeIcon.textContent = '❤️';
                    this.classList.add('liked');
                } else {
                    likeIcon.textContent = '🤍';
                    this.classList.remove('liked');
                }
                likeCount.textContent = data.like_count + ' likes';
            } else {
                alert('Error: ' + data.message);
            }
        } catch (error) {
            console.error('Fetch error:', error);
            alert('Network error: ' + error.message);
        }
    });
});

// Comment functionality
document.querySelectorAll('.comment-btn').forEach(button => {
    button.addEventListener('click', function() {
        const postId = this.getAttribute('data-post-id');
        const commentsSection = document.getElementById(`comments-${postId}`);
        const commentInput = commentsSection.querySelector('.comment-input');
        
        if (commentsSection.style.display === 'none') {
            commentsSection.style.display = 'block';
            loadComments(postId);
            // Auto-focus on comment input
            setTimeout(() => {
                commentInput.focus();
            }, 100);
        } else {
            commentsSection.style.display = 'none';
        }
    });
});

// Post comment
document.querySelectorAll('.btn-comment').forEach(button => {
    button.addEventListener('click', async function() {
        const postId = this.getAttribute('data-post-id');
        const commentInput = this.closest('.comment-form').querySelector('.comment-input');
        const content = commentInput.value.trim();
        
        console.log('Post comment for post:', postId, 'Content:', content);
        
        if (content) {
            try {
                const formData = new URLSearchParams();
                formData.append('action', 'add_comment');
                formData.append('post_id', postId);
                formData.append('content', content);
                formData.append('csrf_token', getCsrfToken());

                console.log('Sending comment request:', {
                    action: 'add_comment',
                    post_id: postId,
                    content: content,
                    csrf_token: getCsrfToken()
                });

                const response = await fetch('news_feed.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                });

                console.log('Comment response status:', response.status);
                const data = await response.json();
                console.log('Comment response data:', data);

                if (data.success) {
                    commentInput.value = '';
                    loadComments(postId);
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Comment fetch error:', error);
                alert('Network error: ' + error.message);
            }
        } else {
            alert('Please enter a comment');
        }
    });
});

// Enter key to post comment
document.querySelectorAll('.comment-input').forEach(input => {
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const postId = this.getAttribute('data-post-id');
            const content = this.value.trim();
            
            if (content) {
                const commentBtn = this.closest('.comment-form').querySelector('.btn-comment');
                commentBtn.click();
            }
        }
    });
});

async function loadComments(postId) {
    const commentsList = document.getElementById(`comments-list-${postId}`);
    
    try {
        // Show loading
        commentsList.innerHTML = '<p>Loading comments...</p>';
        
        // Fetch comments from server
        const formData = new URLSearchParams();
        formData.append('action', 'load_comments');
        formData.append('post_id', postId);
        formData.append('csrf_token', getCsrfToken());

        const response = await fetch('news_feed.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: formData
        });

        const data = await response.json();
        console.log('Comments loaded:', data);

        if (data.success && data.comments) {
            displayComments(commentsList, data.comments);
        } else {
            commentsList.innerHTML = '<p>No comments yet.</p>';
        }
    } catch (error) {
        console.error('Error loading comments:', error);
        commentsList.innerHTML = '<p>Error loading comments.</p>';
    }
}

function displayComments(container, comments) {
    if (comments.length === 0) {
        container.innerHTML = '<p>No comments yet. Be the first to comment!</p>';
        return;
    }

    let commentsHTML = '';
    comments.forEach(comment => {
        commentsHTML += `
            <div class="comment-item">
                <div class="comment-header">
                    <span class="comment-author">${escapeHtml(comment.name)}</span>
                    <span class="comment-date">${formatCommentDate(comment.created_at)}</span>
                </div>
                <div class="comment-content">${escapeHtml(comment.content)}</div>
            </div>
        `;
    });
    
    container.innerHTML = commentsHTML;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCommentDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    
    return date.toLocaleDateString();
}

function getCsrfToken() {
    const token = document.getElementById('csrf_token')?.value;
    console.log('CSRF Token found:', token);
    return token || '';
}

console.log('All event listeners attached');