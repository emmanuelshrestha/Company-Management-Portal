const contentTextarea = document.getElementById('content');
const charCounter = document.getElementById('charCounter');
const charCount = document.getElementById('charCount');
const previewSection = document.getElementById('previewSection');
const previewContent = document.getElementById('previewContent');
const previewBtn = document.getElementById('previewBtn');
const submitBtn = document.getElementById('submitBtn');
const postForm = document.getElementById('postForm');

// Character counter
contentTextarea.addEventListener('input', function() {
    const length = this.value.length;
    charCount.textContent = length;
    
    // Update counter color
    charCounter.className = 'char-counter';
    if (length > 400) {
        charCounter.classList.add('warning');
    }
    if (length > 480) {
        charCounter.classList.add('error');
    }
    
    // Enable/disable submit button
    submitBtn.disabled = length === 0 || length > 500;
});

// Preview functionality
previewBtn.addEventListener('click', function() {
    const content = contentTextarea.value.trim();
    if (content) {
        previewContent.textContent = content;
        previewSection.style.display = 'block';
        
        // Scroll to preview
        previewSection.scrollIntoView({ behavior: 'smooth' });
    } else {
        alert('Please enter some content to preview.');
        contentTextarea.focus();
    }
});

// Auto-resize textarea
contentTextarea.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = (this.scrollHeight) + 'px';
});

// Form submission handling
postForm.addEventListener('submit', function(e) {
    const content = contentTextarea.value.trim();
    
    if (!content) {
        e.preventDefault();
        alert('Please enter some content for your post.');
        contentTextarea.focus();
        return false;
    }
    
    if (content.length > 500) {
        e.preventDefault();
        alert('Post content cannot exceed 500 characters.');
        return false;
    }
    
    // Show loading state
    submitBtn.textContent = 'Posting...';
    submitBtn.disabled = true;
});

// Auto-focus textarea
contentTextarea.focus();