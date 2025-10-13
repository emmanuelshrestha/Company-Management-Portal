        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            if (field.type === 'password') {
                field.type = 'text';
            } else {
                field.type = 'password';
            }
        }
        
        // Validate form before submission
        document.getElementById('loginForm').addEventListener('submit', function(event) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            
            let isValid = true;
        
            // Reset errors
            document.querySelectorAll('.error-message').forEach(el => el.classList.remove('show'));
            document.querySelectorAll('input').forEach(el => el.classList.remove('input-error'));
        
            // Validate email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!email || !emailRegex.test(email)) {
                document.getElementById('emailError').classList.add('show');
                document.getElementById('email').classList.add('input-error');
                isValid = false;
            }
        
            // Validate password
            if (!password) {
                document.getElementById('passwordError').classList.add('show');
                document.getElementById('password').classList.add('input-error');
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