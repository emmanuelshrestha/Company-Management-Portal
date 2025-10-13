// Friend search functionality
document.getElementById('friendSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const friendCards = document.querySelectorAll('.friend-card');
    
    friendCards.forEach(card => {
        const name = card.getAttribute('data-name');
        const email = card.getAttribute('data-email');
        
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
});

// Remove friend confirmation
document.querySelectorAll('.btn-remove').forEach(button => {
    button.addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to remove this friend?')) {
            e.preventDefault();
        }
    });
});

// Auto-focus search input
document.getElementById('friendSearch').focus();