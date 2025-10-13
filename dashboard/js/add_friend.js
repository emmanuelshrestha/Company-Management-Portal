// Auto-focus search input
document.querySelector('input[name="search_query"]')?.focus();

// Confirm before sending request
document.querySelectorAll('form[action=""]').forEach(form => {
    if (form.querySelector('input[name="send_request"]')) {
        form.addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.textContent = 'Sending...';
            button.disabled = true;
        });
    }
});