document.addEventListener('DOMContentLoaded', function() {
    // Update header based on auth status
    updateAuthHeader();

    // Handle Sign In
    const signinForm = document.querySelector('#signin-form form');
    if (signinForm) {
        signinForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'signin');

            fetch('api/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Store user info in localStorage
                    localStorage.setItem('user', JSON.stringify(data.user));
                    showMessage('success', data.message);
                    // Redirect after successful login
                    setTimeout(() => {
                        window.location.href = 'index.html';
                    }, 1500);
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                showMessage('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
    }

    // Handle Sign Up
    const signupForm = document.querySelector('#signup-form form');
    if (signupForm) {
        signupForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'signup');

            // Validate password match
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm-password');
            if (password !== confirmPassword) {
                showMessage('error', 'Passwords do not match');
                return;
            }

            fetch('api/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showMessage('success', data.message);
                    // Switch to sign in tab after successful registration
                    setTimeout(() => {
                        switchTab('signin');
                    }, 1500);
                } else {
                    showMessage('error', data.message);
                }
            })
            .catch(error => {
                showMessage('error', 'An error occurred. Please try again.');
                console.error('Error:', error);
            });
        });
    }

    // Handle Logout
    const logoutButton = document.getElementById('logout-button');
    if (logoutButton) {
        logoutButton.addEventListener('click', function(e) {
            e.preventDefault();
            const formData = new FormData();
            formData.append('action', 'logout');

            fetch('api/auth.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    localStorage.removeItem('user');
                    window.location.href = 'index.html';
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        });
    }
});

// Helper function to show messages
function showMessage(type, message) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${type}`;
    messageDiv.textContent = message;
    
    // Remove any existing messages
    const existingMessages = document.querySelectorAll('.message');
    existingMessages.forEach(msg => msg.remove());
    
    // Add the new message
    const container = document.querySelector('.container');
    container.insertBefore(messageDiv, container.firstChild);
    
    // Remove message after 3 seconds
    setTimeout(() => {
        messageDiv.remove();
    }, 3000);
}

// Update auth header based on user state
function updateAuthHeader() {
    const authContainer = document.getElementById('auth-container');
    if (!authContainer) return;

    const user = JSON.parse(localStorage.getItem('user'));
    if (user) {
        authContainer.innerHTML = `
            <a href="profile.html" class="button style1 small">
                <i class="fa fa-user"></i> ${user.username}
            </a>`;
    } else {
        authContainer.innerHTML = `
            <a href="auth.html" class="button style1 small">Account</a>`;
    }
}

// Check authentication status
function checkAuth() {
    const user = JSON.parse(localStorage.getItem('user'));
    if (user) {
        // Update UI for logged-in user
        document.querySelectorAll('.auth-dependent').forEach(el => {
            el.style.display = 'block';
        });
        document.querySelectorAll('.no-auth').forEach(el => {
            el.style.display = 'none';
        });

        // Handle admin-only elements
        document.querySelectorAll('.admin-only').forEach(el => {
            el.style.display = user.role === 'admin' ? 'inline-block' : 'none';
        });

        updateAuthHeader();

        // Redirect to login if trying to access protected pages
        if (window.location.pathname.includes('profile.html')) {
            // Allow access to profile
        } else if (window.location.pathname.includes('adminDashboard.html') && user.role !== 'admin') {
            // Redirect non-admin users trying to access admin dashboard
            window.location.href = 'index.html';
        }
    } else {
        // Update UI for logged-out user
        document.querySelectorAll('.auth-dependent').forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll('.no-auth').forEach(el => {
            el.style.display = 'block';
        });
        document.querySelectorAll('.admin-only').forEach(el => {
            el.style.display = 'none';
        });
        // Redirect to login if trying to access protected pages
        if (window.location.pathname.includes('profile.html') || 
            window.location.pathname.includes('adminDashboard.html')) {
            window.location.href = 'auth.html';
        }
    }
}
