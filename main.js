// ===== Panel Toggle Logic =====

// DOM elements
const leftZone = document.getElementById('left-zone');
const rightZone = document.getElementById('right-zone');
const mainZone = document.getElementById('main-zone');

const leftPanel = document.getElementById('left-panel');
const rightPanel = document.getElementById('right-panel');

const btnAboutSystem = document.getElementById('btn-about-system'); // About the System button
const btnAboutUs = document.getElementById('btn-about-us');         // About Us button

// Ensure panels are focusable for accessibility
leftPanel?.setAttribute('tabindex', '-1');
rightPanel?.setAttribute('tabindex', '-1');

function showOverlay() {
  mainZone?.classList.remove('hidden');
  mainZone.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
}

function hideOverlay() {
  mainZone?.classList.add('hidden');
  // background-color reset handled by CSS with !important
}

// Close all panels
function closePanels() {
  leftPanel.classList.remove('open');
  rightPanel.classList.remove('open');
  document.body.classList.remove('panel-open');
  hideOverlay();

  // Accessibility: return focus to leftZone if available
  leftZone?.focus();

  // Reset aria attributes
  leftPanel.setAttribute('aria-hidden', 'true');
  rightPanel.setAttribute('aria-hidden', 'true');
}

// Open left panel
function openLeftPanel() {
  rightPanel.classList.remove('open');
  leftPanel.classList.add('open');
  document.body.classList.add('panel-open');
  showOverlay();

  // Accessibility
  leftPanel.setAttribute('aria-hidden', 'false');
  leftPanel.focus();
  rightPanel.setAttribute('aria-hidden', 'true');
}

// Open right panel
function openRightPanel() {
  leftPanel.classList.remove('open');
  rightPanel.classList.add('open');
  document.body.classList.add('panel-open');
  showOverlay();

  // Accessibility
  rightPanel.setAttribute('aria-hidden', 'false');
  rightPanel.focus();
  leftPanel.setAttribute('aria-hidden', 'true');
}

// Open panel by side string (for footer inline onclick)
function openPanel(side) {
  if (side === 'left') {
    openLeftPanel();
  } else if (side === 'right') {
    openRightPanel();
  }
}

// Expose openPanel globally for inline onclick handlers
window.openPanel = openPanel;

// Event listeners for zone clicks
leftZone?.addEventListener('click', () => {
  if (leftPanel.classList.contains('open')) {
    closePanels();
  } else {
    openLeftPanel();
  }
});

rightZone?.addEventListener('click', () => {
  if (rightPanel.classList.contains('open')) {
    closePanels();
  } else {
    openRightPanel();
  }
});

// Event listeners for "About" buttons if they exist
btnAboutSystem?.addEventListener('click', () => {
  if (!leftPanel.classList.contains('open')) {
    openLeftPanel();
  } else {
    closePanels();
  }
});

btnAboutUs?.addEventListener('click', () => {
  if (!rightPanel.classList.contains('open')) {
    openRightPanel();
  } else {
    closePanels();
  }
});

// Close panels if clicking on overlay (mainZone) directly
mainZone?.addEventListener('click', (e) => {
  if (e.target === mainZone) {
    closePanels();
  }
});

// Close panels on ESC key press or toggle with Alt+L / Alt+R
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closePanels();
  }
  // Alt+L to toggle left panel
  else if (e.altKey && e.key.toLowerCase() === 'l') {
    if (leftPanel.classList.contains('open')) {
      closePanels();
    } else {
      openLeftPanel();
    }
  }
  // Alt+R to toggle right panel
  else if (e.altKey && e.key.toLowerCase() === 'r') {
    if (rightPanel.classList.contains('open')) {
      closePanels();
    } else {
      openRightPanel();
    }
  }
});

// ===== Particles.js Initialization with inline config =====
particlesJS('particles-js', {
  particles: {
    number: { value: 80, density: { enable: true, value_area: 800 } },
    color: { value: '#4F46E5' },
    shape: { type: 'circle', stroke: { width: 0, color: '#000000' } },
    opacity: { value: 0.6, random: false, anim: { enable: false } },
    size: { value: 3, random: true, anim: { enable: false } },
    line_linked: {
      enable: true,
      distance: 150,
      color: '#4F46E5',
      opacity: 0.4,
      width: 1
    },
    move: {
      enable: true,
      speed: 3,
      direction: 'none',
      random: false,
      straight: false,
      out_mode: 'bounce',
      bounce: true,
      attract: { enable: false }
    }
  },
  interactivity: {
    detect_on: 'canvas',
    events: {
      onhover: { enable: true, mode: 'grab' },
      onclick: { enable: true, mode: 'push' },
      resize: true
    },
    modes: {
      grab: { distance: 140, line_linked: { opacity: 1 } },
      push: { particles_nb: 4 }
    }
  },
  retina_detect: true
});

// ===== Login/Register Form Handling =====
document.addEventListener('DOMContentLoaded', () => {
  const loginBtn = document.getElementById('loginBtn');
  const registerBtn = document.getElementById('registerBtn');
  const loginForm = document.getElementById('loginForm');
  const registerForm = document.getElementById('registerForm');
  const mainButtons = document.getElementById('mainButtons');
  const mainTitle = document.getElementById('mainTitle');
  const mainSubtitle = document.getElementById('mainSubtitle');
  const termsText = document.getElementById('termsText');

  // When main Login button is clicked
  loginBtn?.addEventListener('click', () => {
    showOnlyForm('loginForm');
  });

  // When main Register button is clicked
  registerBtn?.addEventListener('click', () => {
    showOnlyForm('registerForm');
  });

  // When clicking internal "Register" inside login form
  document.querySelectorAll('[onclick*="registerForm"]').forEach(btn => {
    btn.addEventListener('click', () => {
      showOnlyForm('registerForm');
    });
  });

  // When clicking internal "Login" inside register form
  document.querySelectorAll('[onclick*="loginForm"]').forEach(btn => {
    btn.addEventListener('click', () => {
      showOnlyForm('loginForm');
    });
  });

  // Global function to switch to a specific form
  window.showOnly = function (formToShow) {
    loginForm?.classList.add('hidden');
    registerForm?.classList.add('hidden');
    formToShow?.classList.remove('hidden');

    mainButtons?.classList.add('hidden');
    mainTitle?.classList.add('hidden');
    mainSubtitle?.classList.add('hidden');
    termsText?.classList.add('hidden');
  };

  // Close function when X is clicked
  window.closeForm = function () {
    loginForm?.classList.add('hidden');
    registerForm?.classList.add('hidden');

    mainButtons?.classList.remove('hidden');
    mainTitle?.classList.remove('hidden');
    mainSubtitle?.classList.remove('hidden');
    termsText?.classList.remove('hidden');
  };

  // Notification functions
  window.showNotification = function(message, type = 'success') {
    const notification = document.getElementById('notification');
    if (!notification) return;
    
    const notificationContent = notification.querySelector('.notification-content');
    
    // Clear previous classes
    notificationContent.className = 'notification-content';
    notificationContent.classList.add(`notification-${type}`);
    
    // Set message
    notification.querySelector('.notification-message').textContent = message;
    
    // Set icon based on type
    const iconMap = {
      success: '✓',
      warning: '⚠️',
      error: '✗'
    };
    notification.querySelector('.notification-icon').textContent = iconMap[type] || '';
    
    // Show notification
    notification.classList.add('show');
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
      hideNotification();
    }, 5000);
  };

  window.hideNotification = function() {
    const notification = document.getElementById('notification');
    if (notification) {
      notification.classList.remove('show');
    }
  };

  // Form submissions
  const loginFormElement = document.getElementById('loginFormElement');
  if (loginFormElement) {
    loginFormElement.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const formData = new FormData(loginFormElement);
      const errorElement = document.getElementById('loginError');
      
      try {
        const response = await fetch('login_register.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
          // Redirect based on user role
          const redirectUrl = result.redirect || 'dashboard.php';
          window.location.href = redirectUrl;
        } else {
          errorElement.textContent = result.error || 'Login failed. Please try again.';
          errorElement.classList.remove('hidden');
          showNotification(result.error || 'Login failed', 'error');
        }
      } catch (error) {
        errorElement.textContent = 'Network error. Please check your connection and try again.';
        errorElement.classList.remove('hidden');
        showNotification('Network error. Please try again.', 'error');
        console.error('Login error:', error);
      }
    });
  }

  const registerFormElement = document.getElementById('registerFormElement');
  if (registerFormElement) {
    registerFormElement.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const formData = new FormData(registerFormElement);
      const errorElement = document.getElementById('registerError');
      const successElement = document.getElementById('registerSuccess');
      errorElement.classList.add('hidden');
      successElement.classList.add('hidden');
      
      // Client-side validation
      const password = document.getElementById('regPassword').value;
      const confirmPassword = document.getElementById('confirmPassword').value;
      
      if (password.length < 8) {
        errorElement.textContent = 'Password must be at least 8 characters long';
        errorElement.classList.remove('hidden');
        showNotification('Password must be at least 8 characters long', 'error');
        return;
      }
      
      if (password !== confirmPassword) {
        errorElement.textContent = 'Passwords do not match';
        errorElement.classList.remove('hidden');
        showNotification('Passwords do not match', 'error');
        return;
      }
      
      try {
        const response = await fetch('login_register.php', {
          method: 'POST',
          body: formData
        });
        
        if (!response.ok) throw new Error('Network response was not ok');
        
        const result = await response.json();
        
        if (result.success) {
          successElement.textContent = result.message || 'Registration successful! Your account is pending approval.';
          successElement.classList.remove('hidden');
          
          showNotification(result.message || 'Registration successful! Your account is pending approval.', 'warning');
          
          // Clear form on success
          registerFormElement.reset();
          
          // Auto-close form after 3 seconds
          setTimeout(() => {
            closeForm();
          }, 3000);
        } else {
          errorElement.textContent = result.error || 'Registration failed. Please try again.';
          errorElement.classList.remove('hidden');
          showNotification(result.error || 'Registration failed', 'error');
        }
      } catch (error) {
        errorElement.textContent = 'Network error. Please check your connection and try again.';
        errorElement.classList.remove('hidden');
        showNotification('Network error. Please try again.', 'error');
        console.error('Registration error:', error);
      }
    });
  }
});

// Alias for showOnly function (backward compatibility)
function showOnlyForm(formId) {
  const form = document.getElementById(formId);
  if (form) window.showOnly(form);
}