// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    if (field.type === 'password') {
        field.type = 'text';
    } else {
        field.type = 'password';
    }
}

// Validate password requirements
function validatePassword(password) {
    return {
        length: password.length >= 8,
        number: /\d/.test(password),
        lowercase: /[a-z]/.test(password),
        uppercase: /[A-Z]/.test(password)
    };
}

// Update requirement indicators
function updateRequirements(password) {
    const reqs = validatePassword(password);
    
    document.getElementById('req-length').classList.toggle('valid', reqs.length);
    document.getElementById('req-number').classList.toggle('valid', reqs.number);
    document.getElementById('req-lowercase').classList.toggle('valid', reqs.lowercase);
    document.getElementById('req-uppercase').classList.toggle('valid', reqs.uppercase);
    
    return Object.values(reqs).every(val => val);
}

// Listen to password input
document.getElementById('new_password').addEventListener('input', function(e) {
    updateRequirements(e.target.value);
});

// Validate form before submission
document.getElementById('changePasswordForm').addEventListener('submit', function(event) {
    const currentPassword = document.getElementById('current_password').value.trim();
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    let isValid = true;

    // Reset errors
    document.querySelectorAll('.error-message').forEach(el => el.classList.remove('show'));
    document.querySelectorAll('input').forEach(el => el.classList.remove('input-error'));

    // Validate current password
    if (!currentPassword) {
        document.getElementById('currentPasswordError').classList.add('show');
        document.getElementById('current_password').classList.add('input-error');
        isValid = false;
    }

    // Validate new password
    if (!updateRequirements(newPassword)) {
        document.getElementById('newPasswordError').classList.add('show');
        document.getElementById('new_password').classList.add('input-error');
        isValid = false;
    }

    // Validate password match
    if (newPassword !== confirmPassword) {
        document.getElementById('confirmPasswordError').classList.add('show');
        document.getElementById('confirm_password').classList.add('input-error');
        isValid = false;
    }

    if (!isValid) {
        event.preventDefault();
    }
});

// Auto-hide server message after 5 seconds
window.addEventListener('DOMContentLoaded', function() {
    const msg = document.getElementById('serverMessage');
    if (msg && msg.classList.contains('show')) {
        setTimeout(function() {
            msg.classList.remove('show');
        }, 5000);
    }
});