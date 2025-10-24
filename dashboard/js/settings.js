// settings.js

// Initialize settings page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Settings page initialized');
    
    // Initialize form validations
    initializeFormValidations();
    
    // Initialize theme preview
    initializeThemePreview();
    
    // Initialize character counters
    initializeCharacterCounters();
    
    // Auto-dismiss messages
    autoDismissMessages();
});

// Form validations
function initializeFormValidations() {
    // Password confirmation validation
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    if (newPassword && confirmPassword) {
        confirmPassword.addEventListener('input', function() {
            if (newPassword.value !== confirmPassword.value) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // Email validation
    const emailInput = document.getElementById('email');
    if (emailInput) {
        emailInput.addEventListener('input', function() {
            if (!this.validity.valid) {
                this.setCustomValidity('Please enter a valid email address');
            } else {
                this.setCustomValidity('');
            }
        });
    }
}

// Theme preview functionality
function initializeThemePreview() {
    const themeOptions = document.querySelectorAll('.theme-option');
    themeOptions.forEach(option => {
        option.addEventListener('click', function() {
            themeOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
            
            const theme = this.classList.contains('theme-light') ? 'light' :
                         this.classList.contains('theme-dark') ? 'dark' : 'blue';
            document.getElementById('theme').value = theme;
            
            // Preview theme change (in a real app, this would apply the theme)
            previewTheme(theme);
        });
    });
}

function previewTheme(theme) {
    // This would apply the theme in a real application
    console.log('Theme changed to:', theme);
    
    // For demo purposes, show a preview message
    const message = document.createElement('div');
    message.className = 'message msg-info';
    message.textContent = `Theme preview: ${theme} mode`;
    message.style.marginBottom = '20px';
    
    const existingMessage = document.querySelector('.message.msg-info');
    if (existingMessage) {
        existingMessage.remove();
    }
    
    document.querySelector('.main-content').insertBefore(message, document.querySelector('.settings-tabs'));
    
    setTimeout(() => {
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 500);
    }, 2000);
}

// Character counters
function initializeCharacterCounters() {
    const bioTextarea = document.getElementById('bio');
    const bioCharCount = document.getElementById('bioCharCount');
    
    if (bioTextarea && bioCharCount) {
        bioTextarea.addEventListener('input', function() {
            const length = this.value.length;
            bioCharCount.textContent = length;
            
            // Update color based on length
            if (length > 450) {
                bioCharCount.style.color = '#e53e3e';
            } else if (length > 400) {
                bioCharCount.style.color = '#ed8936';
            } else {
                bioCharCount.style.color = '#718096';
            }
        });
    }
}

// Auto-dismiss messages
function autoDismissMessages() {
    setTimeout(() => {
        document.querySelectorAll('.message').forEach(msg => {
            if (msg.classList.contains('msg-error') || msg.classList.contains('msg-success') || msg.classList.contains('msg-info')) {
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

// Enhanced form submission handling
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.textContent;
                submitBtn.textContent = 'Saving...';
                submitBtn.disabled = true;
                
                // Re-enable after 5 seconds if still disabled (form didn't submit)
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                }, 5000);
            }
        });
    });
});

// Settings export functionality
function exportSettings() {
    const settings = {
        profile: getFormData('profile'),
        privacy: getFormData('privacy'),
        notifications: getFormData('notifications'),
        appearance: getFormData('appearance')
    };
    
    const dataStr = JSON.stringify(settings, null, 2);
    const dataBlob = new Blob([dataStr], {type: 'application/json'});
    
    const link = document.createElement('a');
    link.href = URL.createObjectURL(dataBlob);
    link.download = 'manexis-settings-backup.json';
    link.click();
}

function getFormData(section) {
    const form = document.querySelector(`#${section} form`);
    if (!form) return {};
    
    const formData = new FormData(form);
    const data = {};
    
    for (let [key, value] of formData.entries()) {
        data[key] = value;
    }
    
    return data;
}

// Settings import functionality
function importSettings(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const settings = JSON.parse(e.target.result);
            applySettings(settings);
        } catch (error) {
            alert('Error importing settings: Invalid file format');
        }
    };
    reader.readAsText(file);
}

function applySettings(settings) {
    // This would apply the imported settings to the forms
    console.log('Applying imported settings:', settings);
    alert('Settings imported successfully!');
}

// Reset settings to defaults
function resetSettings(section) {
    if (confirm('Are you sure you want to reset all settings to default?')) {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => form.reset());
        
        // Reset theme selection
        document.querySelectorAll('.theme-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector('.theme-light').classList.add('selected');
        document.getElementById('theme').value = 'light';
        
        alert('Settings reset to default values.');
    }
}