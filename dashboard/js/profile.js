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

// Form validation
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validateProfileForm(e) {
    const name = document.getElementById('name')?.value.trim();
    const email = document.getElementById('email')?.value.trim();
    
    if (!name || !email) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    if (!validateEmail(email)) {
        e.preventDefault();
        alert('Please enter a valid email address.');
        return false;
    }
    
    return true;
}

// Image preview handlers
function setupImagePreview(inputId, targetElement) {
    const input = document.getElementById(inputId);
    if (!input || !targetElement) return;
    
    input.addEventListener('change', function(e) {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                targetElement.style.backgroundImage = `url(${e.target.result})`;
                // Clear text content if it's a profile picture
                if (inputId === 'profile_picture') {
                    targetElement.innerHTML = '';
                }
            }
            reader.readAsDataURL(file);
        }
    });
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

// Download image function
function downloadImage() {
    const imageSrc = document.getElementById('modalImage').src;
    const link = document.createElement('a');
    link.href = imageSrc;
    link.download = 'manexis-image-' + Date.now() + '.jpg';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Share image function (basic implementation)
function shareImage() {
    if (navigator.share) {
        const imageSrc = document.getElementById('modalImage').src;
        const caption = document.getElementById('modalCaption').textContent;
        
        navigator.share({
            title: 'Manexis Image',
            text: caption || 'Check out this image from Manexis',
            url: imageSrc
        }).catch(error => console.log('Error sharing:', error));
    } else {
        // Fallback: copy image URL to clipboard
        const imageSrc = document.getElementById('modalImage').src;
        navigator.clipboard.writeText(imageSrc).then(() => {
            alert('Image URL copied to clipboard!');
        }).catch(err => {
            alert('Share not supported on this browser. Image URL: ' + imageSrc);
        });
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

// Enhanced file upload with progress indication
function enhanceFileUploads() {
    document.querySelectorAll('.file-input').forEach(input => {
        input.addEventListener('change', function() {
            const form = this.closest('form');
            const label = this.nextElementSibling;
            
            if (this.files.length > 0) {
                // Show loading state
                if (label) {
                    const originalText = label.textContent;
                    label.textContent = 'Uploading...';
                    label.style.opacity = '0.7';
                    
                    // Simulate upload completion (in real app, you'd use AJAX)
                    setTimeout(() => {
                        label.textContent = originalText;
                        label.style.opacity = '1';
                    }, 1000);
                }
                
                // Auto-submit form for image uploads
                if (form && (this.name === 'profile_picture' || this.name === 'cover_photo')) {
                    setTimeout(() => {
                        form.submit();
                    }, 100);
                }
            }
        });
    });
}

// Character counter for bio field
function setupCharacterCounter() {
    const bioField = document.getElementById('bio');
    if (!bioField) return;
    
    // Create counter element
    const counter = document.createElement('div');
    counter.className = 'char-counter';
    counter.style.textAlign = 'right';
    counter.style.fontSize = '12px';
    counter.style.color = '#718096';
    counter.style.marginTop = '-10px';
    counter.style.marginBottom = '15px';
    
    bioField.parentNode.insertBefore(counter, bioField.nextSibling);
    
    function updateCounter() {
        const length = bioField.value.length;
        const maxLength = 500; // You can adjust this
        counter.textContent = `${length}/${maxLength}`;
        
        if (length > maxLength * 0.9) {
            counter.style.color = '#e53e3e';
        } else if (length > maxLength * 0.7) {
            counter.style.color = '#ed8936';
        } else {
            counter.style.color = '#718096';
        }
    }
    
    bioField.addEventListener('input', updateCounter);
    updateCounter(); // Initial count
}

// Smooth scroll to active tab
function scrollToActiveTab() {
    const activeTab = document.querySelector('.tab-content.active');
    if (activeTab) {
        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Initialize all functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('Profile page initialized');
    
    // Form validation
    const profileForm = document.querySelector('form[action=""]');
    if (profileForm) {
        profileForm.addEventListener('submit', validateProfileForm);
    }
    
    // Image previews
    const profileAvatar = document.querySelector('.profile-avatar');
    const coverPhoto = document.querySelector('.cover-photo');
    
    if (profileAvatar) {
        setupImagePreview('profile_picture', profileAvatar);
    }
    if (coverPhoto) {
        setupImagePreview('cover_photo', coverPhoto);
    }
    
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
    
    // Enhanced file uploads
    enhanceFileUploads();
    
    // Character counter
    setupCharacterCounter();
    
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
            scrollToActiveTab();
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