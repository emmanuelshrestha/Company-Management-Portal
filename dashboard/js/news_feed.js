// Add some interactivity to post actions
document.querySelectorAll('.post-action').forEach(button => {
    button.addEventListener('click', function() {
        // For now, just show a simple alert
        // In a real app, you'd implement actual like/comment/share functionality
        const action = this.textContent.trim();
        alert(`${action} functionality coming soon!`);
    });
});

// Auto-refresh the feed every 30 seconds
setInterval(() => {
    // You could implement AJAX refresh here
    console.log('Feed refresh check...');
}, 30000);