/**
 * RestoPOS - Main JavaScript
 */

// Global state
const App = {
    currentOrder: null,
    orderItems: [],
    selectedCategory: null,
    notifications: [],
    pollingInterval: null
};

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    initNotifications();
    initModals();
    startPolling();
});

// ============================================
// Notifications
// ============================================
function initNotifications() {
    const bell = document.getElementById('notificationsBell');
    const panel = document.getElementById('notificationsPanel');
    
    if (bell && panel) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            panel.classList.toggle('active');
            if (panel.classList.contains('active')) {
                loadNotifications();
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!panel.contains(e.target) && !bell.contains(e.target)) {
                panel.classList.remove('active');
            }
        });
    }
}

async function loadNotifications() {
    try {
        const response = await fetch('/api/notifications.php');
        const data = await response.json();
        
        if (data.success) {
            renderNotifications(data.notifications);
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

function renderNotifications(notifications) {
    const list = document.getElementById('notificationsList');
    if (!list) return;
    
    if (notifications.length === 0) {
        list.innerHTML = '<div class="notification-item"><p class="text-muted text-center">No notifications</p></div>';
        return;
    }
    
    list.innerHTML = notifications.map(n => `
        <div class="notification-item ${n.read_at ? '' : 'unread'}" data-id="${n.id}">
            <div class="title">${escapeHtml(n.title)}</div>
            <div class="message">${escapeHtml(n.message)}</div>
            <div class="time">${formatTimeAgo(n.created_at)}</div>
        </div>
    `).join('');
}

async function markAllRead() {
    try {
        await fetch('/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_all_read' })
        });
        loadNotifications();
        
        const badge = document.querySelector('.notifications-bell .badge');
        if (badge) badge.remove();
    } catch (error) {
        console.error('Error marking notifications as read:', error);
    }
}

// ============================================
// Polling for updates
// ============================================
function startPolling() {
    // Poll every 10 seconds for updates
    App.pollingInterval = setInterval(async function() {
        await checkForUpdates();
    }, 10000);
}

async function checkForUpdates() {
    try {
        const response = await fetch('/api/status.php');
        const data = await response.json();
        
        // Update notification count
        if (data.unread_notifications !== undefined) {
            updateNotificationBadge(data.unread_notifications);
        }
        
        // Trigger custom event for page-specific updates
        document.dispatchEvent(new CustomEvent('app:update', { detail: data }));
    } catch (error) {
        console.error('Polling error:', error);
    }
}

function updateNotificationBadge(count) {
    const bell = document.querySelector('.notifications-bell');
    if (!bell) return;
    
    let badge = bell.querySelector('.badge');
    
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge';
            bell.appendChild(badge);
        }
        badge.textContent = count;
    } else if (badge) {
        badge.remove();
    }
}

// ============================================
// Modals
// ============================================
function initModals() {
    // Close modal when clicking overlay
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    
    // Close buttons
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            if (modal) closeModal(modal.id);
        });
    });
    
    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const activeModal = document.querySelector('.modal-overlay.active');
            if (activeModal) closeModal(activeModal.id);
        }
    });
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// ============================================
// Toast Notifications
// ============================================
function showToast(message, type = 'info', duration = 3000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${escapeHtml(message)}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideIn 0.3s ease reverse';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ============================================
// API Helpers
// ============================================
async function apiCall(url, method = 'GET', data = null) {
    const options = {
        method,
        headers: {
            'Content-Type': 'application/json'
        }
    };
    
    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'API request failed');
        }
        
        return result;
    } catch (error) {
        showToast(error.message, 'error');
        throw error;
    }
}

// ============================================
// Order Functions
// ============================================
async function createOrder(tableId, numberOfPeople) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'create',
        table_id: tableId,
        number_of_people: numberOfPeople
    });
}

async function addItemToOrder(orderId, menuItemId, quantity = 1, notes = '', modifications = []) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'add_item',
        order_id: orderId,
        menu_item_id: menuItemId,
        quantity: quantity,
        notes: notes,
        modifications: modifications
    });
}

async function updateItemQuantity(orderItemId, quantity) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'update_quantity',
        order_item_id: orderItemId,
        quantity: quantity
    });
}

async function removeItem(orderItemId) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'remove_item',
        order_item_id: orderItemId
    });
}

async function sendToKitchen(orderId) {
    return await apiCall('/api/orders.php', 'POST', {
        action: 'send_to_kitchen',
        order_id: orderId
    });
}

async function requestBill(orderId, tillId = null) {
    const body = { action: 'request_bill', order_id: orderId };
    if (tillId) body.till_id = tillId;
    return await apiCall('/api/orders.php', 'POST', body);
}

// ============================================
// Kitchen Functions
// ============================================
async function updateKitchenStatus(orderItemId, status) {
    return await apiCall('/api/kitchen.php', 'POST', {
        action: 'update_status',
        order_item_id: orderItemId,
        status: status
    });
}

// ============================================
// Payment Functions
// ============================================
async function applyDiscount(orderId, type, value, reason = '') {
    return await apiCall('/api/payments.php', 'POST', {
        action: 'apply_discount',
        order_id: orderId,
        discount_type: type,
        discount_value: value,
        reason: reason
    });
}

async function processPayment(orderId, method, amount, reference = '') {
    return await apiCall('/api/payments.php', 'POST', {
        action: 'process_payment',
        order_id: orderId,
        method: method,
        amount: amount,
        reference: reference
    });
}

// ============================================
// Utility Functions
// ============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return '$' + parseFloat(amount).toFixed(2);
}

function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
    return Math.floor(seconds / 86400) + ' days ago';
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: true 
    });
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Confirm action helper
function confirmAction(message) {
    return new Promise((resolve) => {
        if (confirm(message)) {
            resolve(true);
        } else {
            resolve(false);
        }
    });
}
