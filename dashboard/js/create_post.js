const contentTextarea = document.getElementById('content');
const charCounter = document.getElementById('charCounter');
const charCount = document.getElementById('charCount');
const fileInput = document.getElementById('post_image');
const imagePreview = document.getElementById('imagePreview');
const previewImage = document.getElementById('previewImage');
const removeImageBtn = document.getElementById('removeImage');
const imageUploadSection = document.getElementById('imageUploadSection');
const uploadArea = document.getElementById('uploadArea');
const uploadPlaceholder = document.getElementById('uploadPlaceholder');
const imageCaption = document.getElementById('image_caption');
const captionSection = document.getElementById('captionSection');
const submitBtn = document.getElementById('submitBtn');
const postForm = document.getElementById('postForm');
const toggleUploadBtn = document.getElementById('toggleUpload');

// Initialize - hide preview initially
imagePreview.style.display = 'none';

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
    
    updateSubmitButton();
});

// File input change
fileInput.addEventListener('change', function(e) {
    handleFileSelection(this.files[0]);
});

// Handle file selection
function handleFileSelection(file) {
    if (file) {
        // Validate file type
        if (!file.type.match('image.*')) {
            alert('Please select an image file (JPG, PNG, GIF, WebP).');
            resetImageUpload();
            return;
        }
        
        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be smaller than 5MB. Your file is ' + (file.size / (1024 * 1024)).toFixed(2) + 'MB.');
            resetImageUpload();
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            imagePreview.style.display = 'block';
            uploadPlaceholder.style.display = 'none';
            uploadArea.classList.add('has-image');
            captionSection.classList.add('visible');
        }
        reader.onerror = function() {
            alert('Error reading the image file. Please try another image.');
            resetImageUpload();
        }
        reader.readAsDataURL(file);
    }
    updateSubmitButton();
}

// Reset image upload
function resetImageUpload() {
    fileInput.value = '';
    imagePreview.style.display = 'none';
    uploadPlaceholder.style.display = 'block';
    uploadArea.classList.remove('has-image');
    captionSection.classList.remove('visible');
    imageCaption.value = '';
    updateSubmitButton();
}

// Remove image
removeImageBtn.addEventListener('click', function() {
    resetImageUpload();
});

// Toggle upload section
function toggleUploadSection() {
    const uploadArea = document.getElementById('uploadArea');
    const captionSection = document.getElementById('captionSection');
    const toggleBtn = document.getElementById('toggleUpload');
    
    if (uploadArea.style.display !== 'none') {
        uploadArea.style.display = 'none';
        captionSection.style.display = 'none';
        toggleBtn.textContent = 'Show';
    } else {
        uploadArea.style.display = 'block';
        if (fileInput.files.length > 0) {
            captionSection.style.display = 'block';
        }
        toggleBtn.textContent = 'Hide';
    }
}

// Drag and drop functionality
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    uploadArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, unhighlight, false);
});

function highlight() {
    uploadArea.classList.add('dragover');
}

function unhighlight() {
    uploadArea.classList.remove('dragover');
}

uploadArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length > 0) {
        handleFileSelection(files[0]);
    }
}

// Click on upload area to trigger file input
uploadArea.addEventListener('click', function(e) {
    if (e.target !== removeImageBtn && !e.target.closest('.image-preview')) {
        fileInput.click();
    }
});

// Update submit button state
function updateSubmitButton() {
    const hasContent = contentTextarea.value.trim().length > 0;
    const hasImage = fileInput.files.length > 0;
    submitBtn.disabled = !hasContent && !hasImage;
    
    // Update button text based on content
    if (hasImage && !hasContent) {
        submitBtn.textContent = 'Post Image';
    } else if (hasContent && !hasImage) {
        submitBtn.textContent = 'Post';
    } else if (hasContent && hasImage) {
        submitBtn.textContent = 'Post with Image';
    } else {
        submitBtn.textContent = 'Post';
    }
}

// Form submission handling
postForm.addEventListener('submit', function(e) {
    const content = contentTextarea.value.trim();
    const hasImage = fileInput.files.length > 0;
    
    if (!content && !hasImage) {
        e.preventDefault();
        alert('Please enter some content or select an image to post.');
        contentTextarea.focus();
        return false;
    }
    
    if (content.length > 500) {
        e.preventDefault();
        alert('Post content cannot exceed 500 characters.');
        return false;
    }
    
    // Show loading state
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Posting...';
    submitBtn.disabled = true;
    
    // Re-enable button if form doesn't submit (for debugging)
    setTimeout(() => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }, 5000);
});

// Auto-focus textarea
contentTextarea.focus();

// Initialize button state
updateSubmitButton();