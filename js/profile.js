
document.addEventListener('DOMContentLoaded', function() {
    initializeNavigation();
    initializeProviderActions();
    initializeAlerts();
});


function initializeNavigation() {
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
    
    const dashboardBtn = document.getElementById('dashboard-btn');
    if (dashboardBtn) {
        dashboardBtn.addEventListener('click', function() {
            window.location.href = window.profileData.baseUrl + 'dashboard/index';
        });
    }
}

function initializeProviderActions() {
    const profileMain = document.querySelector('.profile-main');
    
    if (profileMain) {
        profileMain.addEventListener('click', function(e) {
            if (e.target.classList.contains('disconnect-btn')) {
                e.preventDefault(); 
                handleDisconnect(e.target);
            }
        });
        
        profileMain.addEventListener('click', function(e) {
            if (e.target.classList.contains('priority-btn')) {
                e.preventDefault(); 
                handlePriority(e.target);
            }
        });
        
    }
}


function initializeAlerts() {
    const successAlerts = document.querySelectorAll('.alert-success');
    successAlerts.forEach(alert => {
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    });
}

async function handleLogout() {
    try {
        showToast('Logging out...', 'info');
        
        const response = await fetch(window.profileData.baseUrl + 'api/auth/logout', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Logged out successfully', 'success');
            setTimeout(() => {
                window.location.href = window.profileData.baseUrl + 'auth/login';
            }, 1000);
        } else {
            showToast('Logout failed', 'error');
        }
        
    } catch (error) {
        console.error('Logout error:', error);
        showToast('Logout failed', 'error');
    }
}

async function handleDisconnect(button) {
    const providerCard = button.closest('.provider-card');
    const accountId = providerCard.getAttribute('data-account-id');
    const providerName = providerCard.getAttribute('data-provider-name') || 'this provider';
    
    if (!confirm(`Are you sure you want to disconnect ${providerName}?`)) {
        return;
    }
    
    setButtonLoading(button, true);
    
    try {
        showToast('Disconnecting provider...', 'info');
        
        const formData = new FormData();
        formData.append('account_id', accountId);
        
        const response = await fetch(window.profileData.baseUrl + 'api/profile/disconnect-provider', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Provider disconnected successfully', 'success');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showToast(data.error || 'Failed to disconnect provider', 'error');
        }
        
    } catch (error) {
        console.error('Disconnect error:', error);
        showToast('Failed to disconnect provider', 'error');
    } finally {
        setButtonLoading(button, false);
    }
}


async function handlePriority(button) {
    const providerCard = button.closest('.provider-card');
    const accountId = providerCard.getAttribute('data-account-id');
    const direction = button.getAttribute('data-direction');
    
    setButtonLoading(button, true);
    
    try {
        showToast('Updating priority...', 'info');
        
        const formData = new FormData();
        formData.append('account_id', accountId);
        formData.append('direction', direction);
        
        const response = await fetch(window.profileData.baseUrl + 'api/profile/move-provider', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include'
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Priority updated successfully', 'success');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            showToast(data.error || 'Failed to update priority', 'error');
        }
        
    } catch (error) {
        console.error('Move provider error:', error);
        showToast('Failed to update priority', 'error');
    } finally {
        setButtonLoading(button, false);
    }
}


function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = 'toast';
    
    const icons = {
        error: '❌',
        success: '✅',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    toast.innerHTML = `
        <div class="toast-content">
            <span>${icons[type]} ${message}</span>
            <button class="toast-close" onclick="closeToast(this)">×</button>
        </div>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        if (toast.parentNode) {
            closeToast(toast.querySelector('.toast-close'));
        }
    }, 4000);
}

function closeToast(closeBtn) {
    const toast = closeBtn.closest('.toast');
    if (toast && toast.parentNode) {
        toast.parentNode.removeChild(toast);
    }
}

function setButtonLoading(button, loading) {
    if (loading) {
        button.disabled = true;
        button.setAttribute('data-original-text', button.textContent);
        
        if (button.classList.contains('priority-btn')) {
            button.textContent = '...';
        } else {
            button.textContent = 'Loading...';
        }
    } else {
        button.disabled = false;
        button.textContent = button.getAttribute('data-original-text') || button.textContent;
    }
}

window.closeToast = closeToast;