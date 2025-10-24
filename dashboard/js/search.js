// search.js

// Auto-focus search input
document.addEventListener('DOMContentLoaded', function() {
    console.log('Search page initialized');
    
    // Focus search input
    const searchInput = document.querySelector('input[name="search_query"]');
    if (searchInput) {
        searchInput.focus();
    }

    // Confirm before sending request
    document.querySelectorAll('form[action=""] button[type="submit"]').forEach(button => {
        button.addEventListener('click', function(e) {
            const userName = this.getAttribute('data-user-name');
            if (userName && !confirm(`Send friend request to ${userName}?`)) {
                e.preventDefault();
            }
        });
    });

    // Disable button on submit
    document.querySelectorAll('form[action=""]').forEach(form => {
        if (form.querySelector('input[name="send_request"]')) {
            form.addEventListener('submit', function(e) {
                const button = this.querySelector('button[type="submit"]');
                if (button) {
                    button.textContent = 'Sending...';
                    button.disabled = true;
                    button.style.opacity = '0.7';
                }
            });
        }
    });

    // Auto-dismiss messages
    autoDismissMessages();

    // Smooth scroll to sent requests
    const sentRequestsLink = document.querySelector('a[href="#sent-requests"]');
    if (sentRequestsLink) {
        sentRequestsLink.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.getElementById('sent-requests');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
});

// Auto-dismiss messages
function autoDismissMessages() {
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(msg => {
            if (msg.classList.contains('msg-error') || msg.classList.contains('msg-success') || msg.classList.contains('msg-info')) {
                msg.style.opacity = '0';
                msg.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                    if (msg.parentNode) {
                        msg.remove();
                    }
                }, 500);
            }
        });
    }, 5000);
}

// Enhanced search with debouncing
let searchTimeout;
function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        // This would be for real-time search, currently using form submission
    }, 300);
}

// Make user cards more interactive
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.user-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 15px rgba(0, 0, 0, 0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });
});