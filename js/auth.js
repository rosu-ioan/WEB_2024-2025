
document.addEventListener('DOMContentLoaded', function() {
    initializeTabs();
    
    initializeForms();
    
    initializeValidation();
});



function initializeTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    const formContainers = document.querySelectorAll('.auth-form-container');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');
            
            tabButtons.forEach(btn => btn.classList.remove('active'));
            formContainers.forEach(container => container.classList.remove('active'));
            
            this.classList.add('active');
            document.getElementById(targetTab + '-form').classList.add('active');
            
            clearAlerts();
            
            const firstInput = document.querySelector(`#${targetTab}-form .form-input`);
            if (firstInput) {
                firstInput.focus();
            }
        });
    });
}


function initializeForms() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
    
    if (registerForm) {
        registerForm.addEventListener('submit', handleRegister);
    }
}


function initializeValidation() {
    const inputs = document.querySelectorAll('.form-input');
    
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            clearFieldError(this);
        });
    });
    
    const confirmPassword = document.getElementById('register-confirm');
    if (confirmPassword) {
        confirmPassword.addEventListener('input', validatePasswordMatch);
    }
}

async function handleLogin(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    clearFormErrors(form);
    
    if (!validateLoginForm(form)) {
        return;
    }
    
    setButtonLoading(submitBtn, true);
    
    try {
        const apiUrl = 'api/auth/login';
        
        const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('Login successful! Redirecting...', 'success');
            
            setTimeout(() => {
                window.location.href = data.redirect || 'dashboard';
            }, 1500);
            
        } else {
            if (data.errors) {
                showFieldErrors(data.errors);
            } else {
                showAlert(data.message || data.error || 'Login failed. Please try again.', 'error');
            }
        }
        
    } catch (error) {
        console.error('Login error:', error);
        showAlert('Network error. Please check your connection and try again.', 'error');
    } finally {
        setButtonLoading(submitBtn, false);
    }
}


async function handleRegister(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    clearFormErrors(form);
    
    if (!validateRegisterForm(form)) {
        return;
    }
    
    setButtonLoading(submitBtn, true);
    
    try {
        const apiUrl = 'api/auth/register';
        
        const response = await fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('Account created successfully! Redirecting...', 'success');
            
            setTimeout(() => {
                window.location.href = data.redirect || 'dashboard';
            }, 1500);
            
        } else {
            if (data.errors) {
                showFieldErrors(data.errors);
            } else {
                showAlert(data.message || data.error || 'Registration failed. Please try again.', 'error');
            }
        }
        
    } catch (error) {
        console.error('Register error:', error);
        showAlert('Network error. Please check your connection and try again.', 'error');
    } finally {
        setButtonLoading(submitBtn, false);
    }
}


function validateLoginForm(form) {
    let isValid = true;
    
    const email = form.querySelector('[name="email"]');
    const password = form.querySelector('[name="password"]');
    
    if (!email.value.trim()) {
        showFieldError(email, 'Email is required');
        isValid = false;
    } else if (!isValidEmail(email.value)) {
        showFieldError(email, 'Please enter a valid email address');
        isValid = false;
    }
    
    if (!password.value.trim()) {
        showFieldError(password, 'Password is required');
        isValid = false;
    }
    
    return isValid;
}


function validateRegisterForm(form) {
    let isValid = true;
    
    const username = form.querySelector('[name="username"]');
    const email = form.querySelector('[name="email"]');
    const password = form.querySelector('[name="password"]');
    const confirmPassword = form.querySelector('[name="confirm_password"]');
    
    if (!username.value.trim()) {
        showFieldError(username, 'Username is required');
        isValid = false;
    } else if (username.value.length < 3) {
        showFieldError(username, 'Username must be at least 3 characters');
        isValid = false;
    }
    
    if (!email.value.trim()) {
        showFieldError(email, 'Email is required');
        isValid = false;
    } else if (!isValidEmail(email.value)) {
        showFieldError(email, 'Please enter a valid email address');
        isValid = false;
    }
    
    if (!password.value.trim()) {
        showFieldError(password, 'Password is required');
        isValid = false;
    } else if (password.value.length < 8) {
        showFieldError(password, 'Password must be at least 8 characters');
        isValid = false;
    }
    
    if (!confirmPassword.value.trim()) {
        showFieldError(confirmPassword, 'Please confirm your password');
        isValid = false;
    } else if (password.value !== confirmPassword.value) {
        showFieldError(confirmPassword, 'Passwords do not match');
        isValid = false;
    }
    
    return isValid;
}

function validateField(field) {
    const value = field.value.trim();
    const name = field.getAttribute('name');
    
    switch (name) {
        case 'email':
            if (!value) {
                showFieldError(field, 'Email is required');
                return false;
            } else if (!isValidEmail(value)) {
                showFieldError(field, 'Please enter a valid email address');
                return false;
            }
            break;
            
        case 'username':
            if (!value) {
                showFieldError(field, 'Username is required');
                return false;
            } else if (value.length < 3) {
                showFieldError(field, 'Username must be at least 3 characters');
                return false;
            }
            break;
            
        case 'password':
            if (!value) {
                showFieldError(field, 'Password is required');
                return false;
            } else if (value.length < 8) {
                showFieldError(field, 'Password must be at least 8 characters');
                return false;
            }
            break;
    }
    
    clearFieldError(field);
    return true;
}


function validatePasswordMatch() {
    const password = document.getElementById('register-password');
    const confirmPassword = document.getElementById('register-confirm');
    
    if (confirmPassword.value && password.value !== confirmPassword.value) {
        showFieldError(confirmPassword, 'Passwords do not match');
    } else {
        clearFieldError(confirmPassword);
    }
}


function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

function showFieldError(field, message) {
    const errorElement = document.getElementById(field.id + '-error');
    if (errorElement) {
        errorElement.textContent = message;
        errorElement.classList.add('show');
    }
    field.classList.add('error');
    field.classList.remove('valid');
}

function clearFieldError(field) {
    const errorElement = document.getElementById(field.id + '-error');
    if (errorElement) {
        errorElement.textContent = '';
        errorElement.classList.remove('show');
    }
    field.classList.remove('error');
    if (field.value.trim()) {
        field.classList.add('valid');
    }
}


function showFieldErrors(errors) {
    Object.keys(errors).forEach(fieldName => {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            showFieldError(field, errors[fieldName]);
        }
    });
}


function clearFormErrors(form) {
    const errorElements = form.querySelectorAll('.error-message');
    const inputElements = form.querySelectorAll('.form-input');
    
    errorElements.forEach(element => {
        element.textContent = '';
        element.classList.remove('show');
    });
    
    inputElements.forEach(input => {
        input.classList.remove('error', 'valid');
    });
}

function showAlert(message, type = 'error') {
    const alertsContainer = document.getElementById('alerts');
    const alertClass = type === 'error' ? 'error-alert' : 'success-alert';
    
    alertsContainer.innerHTML = `<div class="${alertClass}">${message}</div>`;
    
    if (type === 'success') {
        setTimeout(() => {
            clearAlerts();
        }, 5000);
    }
}

function clearAlerts() {
    const alertsContainer = document.getElementById('alerts');
    alertsContainer.innerHTML = '';
}


function setButtonLoading(button, loading) {
    if (loading) {
        button.disabled = true;
        button.classList.add('btn-loading');
        button.setAttribute('data-original-text', button.textContent);
        button.textContent = 'Loading...';
    } else {
        button.disabled = false;
        button.classList.remove('btn-loading');
        button.textContent = button.getAttribute('data-original-text') || 'Submit';
    }
};