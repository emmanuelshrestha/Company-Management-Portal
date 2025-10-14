// Tab switching functionality
function switchTab(tabName) {
    // Hide all tab contents and remove active class from buttons
    document.querySelectorAll('.tab-content, .tab-btn').forEach(element => {
        element.classList.remove('active');
    });
    
    // Show selected tab and activate button
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
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
}

// Image preview handlers
function setupImagePreview(inputId, targetElement) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    input.addEventListener('change', function(e) {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                targetElement.style.backgroundImage = `url(${e.target.result})`;
                if (inputId === 'profile_picture') {
                    targetElement.innerHTML = '';
                }
            }
            reader.readAsDataURL(file);
        }
    });
}

// Image Modal Functions
function openImageModal(img, caption) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const captionText = document.getElementById('modalCaption');
    
    modal.style.display = 'block';
    modalImg.src = img.src;
    captionText.innerHTML = caption || '';
    document.body.style.overflow = 'hidden';
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Auto-dismiss messages
function autoDismissMessages() {
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(msg => {
            msg.style.opacity = '0';
            msg.style.transition = 'opacity 0.5s';
            setTimeout(() => msg.remove(), 500);
        });
    }, 5000);
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Form validation
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', validateProfileForm);
    }
    
    // Image previews
    setupImagePreview('profile_picture', document.querySelector('.profile-avatar'));
    setupImagePreview('cover_photo', document.querySelector('.cover-photo'));
    
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
});