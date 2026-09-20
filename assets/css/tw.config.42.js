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
            success: '#0D6B4D',
            warning: '#F59E0B',
            danger: '#DC3545',
            info: '#3B82F6',
            royal: '#1A4B8C',
            'royal-2': '#0F3460',
            forest: '#0D6B4D',
            'forest-2': '#084A34',
            brass: '#D4A843',
            'brass-strong': '#B8922A',
            'brass-soft': '#F5E6C8',
          },
          fontFamily: {
            'poppins': ['Inter', 'sans-serif'],
            'display': ['Fraunces', 'serif'],
            'eyebrow': ['"Space Grotesk"', 'sans-serif'],
          },
          boxShadow: {
            'custom': '0 4px 20px rgba(15, 24, 48, 0.08)',
            'custom-hover': '0 10px 32px rgba(15, 24, 48, 0.14)',
          },
          borderRadius: {
            'xl': '1rem',
            '2xl': '1.5rem',
          },
          animation: {
            'fade-in': 'fadeIn 0.5s ease-in-out',
            'slide-up': 'slideUp 0.3s ease-out',
            'fade-up': 'fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both',
          }
        }
      }
    };
base.content = ["c:/New folder/htdocs/trainwise/training_recommendations.php"];
module.exports = base;
