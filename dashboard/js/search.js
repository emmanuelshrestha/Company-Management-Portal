// search.js

// Auto-focus search input
document.querySelector('input[name="search_query"]')?.focus();

// Confirm before sending request
document.querySelectorAll('form[action=""] button[type="submit"]').forEach(button => {
    button.addEventListener('click', function(e) {
        const userName = this.getAttribute('data-user-name');
        if (!confirm(`Send friend request to ${userName}?`)) {
            e.preventDefault();
        }
    });
});

// Disable button on submit
document.querySelectorAll('form[action=""]').forEach(form => {
    if (form.querySelector('input[name="send_request"]')) {
        form.addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.textContent = 'Sending...';
            button.disabled = true;
        });
    }
});