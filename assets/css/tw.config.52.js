const base = {
  theme: {
    extend: {
      colors: {
        primary: '#1A4B8C',
        'primary-dark': '#0F3460',
        secondary: '#0D6B4D',
        'secondary-dark': '#084A34',
        accent: '#D4A843',
        gold: '#D4A843',
        'gold-light': '#E8C96E',
        'gold-soft': '#F5E6C8',
        success: '#0D6B4D',
        warning: '#F59E0B',
        danger: '#DC3545',
        info: '#3B82F6',
        royal: '#1A4B8C',
        'royal-2': '#0F3460',
        forest: '#0D6B4D',
        'forest-2': '#084A34',
        cream: '#FDF8F0',
        'cream-dim': '#F5EDDF',
      },
      borderRadius: {
        'xl': '1rem',
        '2xl': '1.5rem',
      },
      fontFamily: {
        'poppins': ['Poppins', 'sans-serif'],
      },
      boxShadow: {
        'custom': '0 4px 20px rgba(15, 24, 48, 0.08)',
        'custom-hover': '0 8px 30px rgba(15, 24, 48, 0.12)',
        'soft': '0 4px 20px rgba(0, 0, 0, 0.08)',
        'focus': '0 0 0 3px rgba(26, 75, 140, 0.2)',
        'card': '0 2px 8px rgba(0, 0, 0, 0.1)'
      },
      animation: {
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-up': 'slideUp 0.3s ease-out',
      }
    }
  }
};
base.content = ["c:/New folder/htdocs/trainwise/save_idp_forms.php"];
module.exports = base;
