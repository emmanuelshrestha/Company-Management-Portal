// Tab switching functionality
function switchSettingsTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.settings-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.settings-tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    const targetTab = document.getElementById(tabName);
    if (targetTab) {
        targetTab.classList.add('active');
    }
    
    // Add active class to clicked tab button
    event.currentTarget.classList.add('active');
    
    // Save active tab to session storage
    sessionStorage.setItem('activeSettingsTab', tabName);
}

// Theme selection functionality
function selectTheme(theme) {
    // Remove selected class from all theme options
    document.querySelectorAll('.theme-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    // Add selected class to clicked theme option
    event.currentTarget.classList.add('selected');
    
    // Update hidden input value
    document.getElementById('theme').value = theme;
    
    // Update live preview
    updateThemePreview(theme);
    
    // Show preview notification
    showThemePreviewNotification(theme);
}

function updateThemePreview(theme) {
    const previewArea = document.getElementById('theme-preview-area');
    if (previewArea) {
        // Remove all theme classes
        previewArea.classList.remove('theme-light', 'theme-dark', 'theme-blue');
        
        // Add current theme class
        previewArea.classList.add(`theme-${theme}`);
        
        // Update preview styles based on theme
        const previewContent = previewArea.querySelector('.preview-content');
        const previewHeader = previewArea.querySelector('.preview-header');
        
        switch(theme) {
            case 'light':
                previewContent.style.background = '#ffffff';
                previewContent.style.color = '#2d3748';
                previewContent.style.borderColor = '#e2e8f0';
                break;
            case 'dark':
                previewContent.style.background = '#2d3748';
                previewContent.style.color = '#f7fafc';
                previewContent.style.borderColor = '#4a5568';
                break;
            case 'blue':
                previewContent.style.background = '#ebf8ff';
                previewContent.style.color = '#2d3748';
                previewContent.style.borderColor = '#bee3f8';
                break;
        }
    }
}

function showThemePreviewNotification(theme) {
    // Remove existing notification
    const existingNotification = document.querySelector('.theme-preview-notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    
    // Create new notification
    const notification = document.createElement('div');
    notification.className = 'theme-preview-notification message msg-info';
    notification.style.cssText = `
        margin-bottom: 20px;
        padding: 12px 16px;
        border-radius: 8px;
        background: #bee3f8;
        color: #2c5282;
        border: 1px solid #90cdf4;
    `;
    notification.innerHTML = `
        <strong>Theme Preview:</strong> You're previewing the ${theme} theme. 
        <span style="font-size: 12px;">Click "Save Appearance Settings" to apply this theme.</span>
    `;
    
    // Insert notification
    const appearanceSection = document.getElementById('appearance');
    const firstFormSection = appearanceSection.querySelector('.form-section');
    firstFormSection.parentNode.insertBefore(notification, firstFormSection);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.5s ease';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 500);
        }
    }, 5000);
}

// Reset appearance settings
function resetAppearanceSettings() {
    if (confirm('Are you sure you want to reset all appearance settings to default?')) {
        // Reset theme to light
        document.querySelectorAll('.theme-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector('.theme-light').classList.add('selected');
        document.getElementById('theme').value = 'light';
        
        // Reset font size to medium
        document.getElementById('font_size').value = 'medium';
        
        // Uncheck compact mode
        document.getElementById('compact_mode').checked = false;
        
        // Update preview
        updateThemePreview('light');
        
        // Show confirmation
        showToast('Appearance settings reset to default.', 'success');
    }
}

// Font size change handler
document.addEventListener('DOMContentLoaded', function() {
    const fontSizeSelect = document.getElementById('font_size');
    if (fontSizeSelect) {
        fontSizeSelect.addEventListener('change', function() {
            updateFontSizePreview(this.value);
        });
    }
});

function updateFontSizePreview(fontSize) {
    const previewArea = document.getElementById('theme-preview-area');
    if (previewArea) {
        const previewContent = previewArea.querySelector('.preview-content');
        
    switch(fontSize) {
        case 'small':
            previewContent.style.fontSize = '12px';
            break;
        case 'medium':
            previewContent.style.fontSize = '14px';
            break;
        case 'large':
            previewContent.style.fontSize = '16px';
            break;
        case 'xlarge':
            previewContent.style.fontSize = '18px';
            break;
    }
}
}

// Compact mode change handler
document.addEventListener('DOMContentLoaded', function() {
    const compactMode = document.getElementById('compact_mode');
    if (compactMode) {
        compactMode.addEventListener('change', function() {
            updateCompactModePreview(this.checked);
        });
    }
});

function updateCompactModePreview(isCompact) {
    const previewArea = document.getElementById('theme-preview-area');
    if (previewArea) {
        const previewContent = previewArea.querySelector('.preview-content');
        
        if (isCompact) {
            previewContent.style.padding = '10px';
            previewContent.querySelector('p').style.margin = '0 0 10px 0';
        } else {
            previewContent.style.padding = '20px';
            previewContent.querySelector('p').style.margin = '0 0 15px 0';
        }
    }
}

// Bio character counter
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

// Password validation
const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');

if (newPassword && confirmPassword) {
    confirmPassword.addEventListener('input', function() {
        if (newPassword.value !== confirmPassword.value) {
            this.setCustomValidity('Passwords do not match');
            showFieldError(this, 'Passwords do not match');
        } else {
            this.setCustomValidity('');
            clearFieldError(this);
        }
    });
}

function showFieldError(field, message) {
    clearFieldError(field);
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.style.color = '#e53e3e';
    errorDiv.style.fontSize = '12px';
    errorDiv.style.marginTop = '5px';
    errorDiv.textContent = message;
    
    field.parentNode.appendChild(errorDiv);
    field.style.borderColor = '#e53e3e';
}

function clearFieldError(field) {
    const existingError = field.parentNode.querySelector('.field-error');
    if (existingError) {
        existingError.remove();
    }
    field.style.borderColor = '#e2e8f0';
}

// Password strength indicator
if (newPassword) {
    newPassword.addEventListener('input', function() {
        const strength = checkPasswordStrength(this.value);
        updatePasswordStrengthIndicator(strength);
    });
}

function checkPasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]+/)) strength++;
    if (password.match(/[A-Z]+/)) strength++;
    if (password.match(/[0-9]+/)) strength++;
    if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
    
    return strength;
}

function updatePasswordStrengthIndicator(strength) {
    let indicator = document.getElementById('password-strength-indicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'password-strength-indicator';
        indicator.style.marginTop = '5px';
        indicator.style.fontSize = '12px';
        document.getElementById('new_password').parentNode.appendChild(indicator);
    }
    
    const strengths = {
        0: { text: 'Very Weak', color: '#e53e3e' },
        1: { text: 'Weak', color: '#ed8936' },
        2: { text: 'Fair', color: '#ecc94b' },
        3: { text: 'Good', color: '#48bb78' },
        4: { text: 'Strong', color: '#38a169' },
        5: { text: 'Very Strong', color: '#25855a' }
    };
    
    const currentStrength = strengths[strength] || strengths[0];
    indicator.innerHTML = `Strength: <span style="color: ${currentStrength.color}; font-weight: bold;">${currentStrength.text}</span>`;
}

// Toast notification system
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        background: ${type === 'success' ? '#48bb78' : type === 'error' ? '#e53e3e' : '#3498db'};
        color: white;
        border-radius: 8px;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(100%);
        transition: transform 0.3s ease;
    `;
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 100);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 300);
    }, 3000);
}

// Danger zone actions
function deleteAccount() {
    const confirmation = prompt('This action cannot be undone. Type "DELETE" to confirm:');
    if (confirmation === 'DELETE') {
        showToast('Account deletion request received. This action cannot be undone.', 'error');
        
        // Simulate API call
        setTimeout(() => {
            if (confirm('Final confirmation: Are you absolutely sure you want to delete your account? This will permanently remove all your data.')) {
                showToast('Account deletion in progress...', 'error');
                // In a real implementation, this would submit a form or make an API call
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = document.getElementById('csrf_token').value;
                
                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_account';
                deleteInput.value = '1';
                
                form.appendChild(csrfInput);
                form.appendChild(deleteInput);
                document.body.appendChild(form);
                form.submit();
            }
        }, 1000);
    } else {
        showToast('Account deletion cancelled.', 'info');
    }
}

// Auto-dismiss messages
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

// Initialize settings when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize current theme
    const currentTheme = '<?php echo $current_theme; ?>';
    if (currentTheme) {
        // Set the theme selection
        document.querySelectorAll('.theme-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector(`.theme-${currentTheme}`).classList.add('selected');
        document.getElementById('theme').value = currentTheme;
        updateThemePreview(currentTheme);
    }
    
    // Initialize font size preview
    const currentFontSize = document.getElementById('font_size').value;
    updateFontSizePreview(currentFontSize);
    
    // Initialize compact mode preview
    const currentCompactMode = document.getElementById('compact_mode').checked;
    updateCompactModePreview(currentCompactMode);
    
    // Restore active tab
    const activeTab = sessionStorage.getItem('activeSettingsTab');
    if (activeTab) {
        const tabButton = document.querySelector(`.settings-tab[onclick="switchSettingsTab('${activeTab}')"]`);
        if (tabButton) {
            tabButton.click();
        }
    }
    
    // Enhanced form submission handling
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