// Tab switching functionality
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName);
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Add active class to clicked tab button
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
}

// Image Modal Functions
function openImageModal(imgElement, caption) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const captionText = document.getElementById('modalCaption');
    
    if (!modal || !modalImg) return;
    
    modal.style.display = 'block';
    modalImg.src = imgElement.src;
    captionText.innerHTML = caption || '';
    document.body.style.overflow = 'hidden';
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Auto-dismiss messages
function autoDismissMessages() {
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(msg => {
            if (msg.classList.contains('msg-error') || msg.classList.contains('msg-success')) {
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

// Initialize all functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('Public profile page initialized');
    
    // Modal events
    const imageModal = document.getElementById('imageModal');
    if (imageModal) {
        imageModal.addEventListener('click', function(e) {
            if (e.target === this) closeImageModal();
        });
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImageModal();
    });
    
    // Auto-dismiss messages
    autoDismissMessages();
    
    // Make post images clickable for modal
    document.querySelectorAll('.post-image-auto').forEach(img => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', function() {
            const caption = this.nextElementSibling?.textContent || '';
            openImageModal(this, caption);
        });
    });
    
    // Tab click handlers
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabName = this.getAttribute('onclick').match(/switchTab\('([^']+)'\)/)[1];
            switchTab(tabName);
        });
    });
});

// Close modal when clicking outside the image
document.getElementById('imageModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeImageModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageModal();
    }
});