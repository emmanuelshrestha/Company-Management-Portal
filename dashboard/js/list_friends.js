// Friend search functionality
document.getElementById('friendSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const friendRows = document.querySelectorAll('.friend-row');
    
    friendRows.forEach(row => {
        const name = row.getAttribute('data-name');
        const email = row.getAttribute('data-email');
        
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            row.style.display = 'flex';
        } else {
            row.style.display = 'none';
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