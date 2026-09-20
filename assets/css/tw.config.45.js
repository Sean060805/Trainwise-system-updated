const base = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'sans-serif'],
            display: ['Fraunces', 'serif'],
            eyebrow: ['"Space Grotesk"', 'sans-serif'],
          },
          colors: {
            primary: '#1A4B8C',
            'primary-dark': '#0F3460',
            secondary: '#0D6B4D',
            'secondary-dark': '#084A34',
            danger: '#DC3545',
            success: '#0D6B4D',
            warning: '#F59E0B',
            info: '#3B82F6',
            royal: '#1A4B8C',
            'royal-2': '#0F3460',
            forest: '#0D6B4D',
            gold: '#D4A843',
            'gold-light': '#E8C96E',
            cream: '#FDF8F0',
            slate: '#5B7288',
          },
          borderRadius: {
            none: '0px',
            sm: '4px',
            DEFAULT: '8px',
            md: '12px',
            lg: '16px',
            xl: '20px',
            '2xl': '24px',
            '3xl': '32px',
            full: '9999px',
            button: '8px',
          },
          animation: {
            'fade-in': 'fadeIn 0.5s ease-in-out',
            'slide-up': 'slideUp 0.5s ease-out',
            'slide-down': 'slideDown 0.5s ease-out',
            'slide-left': 'slideLeft 0.5s ease-out',
            'slide-right': 'slideRight 0.5s ease-out',
            'bounce-in': 'bounceIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55)',
            'flip': 'flip 0.6s ease-in-out',
            'zoom-in': 'zoomIn 0.5s ease-out',
            'rotate-in': 'rotateIn 0.6s ease-out',
            'logo-rise': 'logoRise 0.5s ease-out',
          },
          keyframes: {
            fadeIn: {
              '0%': { opacity: '0' },
              '100%': { opacity: '1' },
            },
            slideUp: {
              '0%': { transform: 'translateY(50px)', opacity: '0' },
              '100%': { transform: 'translateY(0)', opacity: '1' },
            },
            slideDown: {
              '0%': { transform: 'translateY(-50px)', opacity: '0' },
              '100%': { transform: 'translateY(0)', opacity: '1' },
            },
            slideLeft: {
              '0%': { transform: 'translateX(50px)', opacity: '0' },
              '100%': { transform: 'translateX(0)', opacity: '1' },
            },
            slideRight: {
              '0%': { transform: 'translateX(-50px)', opacity: '0' },
              '100%': { transform: 'translateX(0)', opacity: '1' },
            },
            bounceIn: {
              '0%': { transform: 'scale(0.3)', opacity: '0' },
              '50%': { transform: 'scale(1.05)', opacity: '0.8' },
              '70%': { transform: 'scale(0.9)', opacity: '0.9' },
              '100%': { transform: 'scale(1)', opacity: '1' },
            },
            flip: {
              '0%': { transform: 'perspective(400px) rotateY(90deg)', opacity: '0' },
              '40%': { transform: 'perspective(400px) rotateY(-10deg)' },
              '70%': { transform: 'perspective(400px) rotateY(10deg)' },
              '100%': { transform: 'perspective(400px) rotateY(0)', opacity: '1' },
            },
            zoomIn: {
              '0%': { transform: 'scale(0.5)', opacity: '0' },
              '100%': { transform: 'scale(1)', opacity: '1' },
            },
            rotateIn: {
              '0%': { transform: 'rotate(-180deg) scale(0.3)', opacity: '0' },
              '100%': { transform: 'rotate(0) scale(1)', opacity: '1' },
            },
            logoRise: {
              '0%': { transform: 'translateY(0) scale(1)' },
              '100%': { transform: 'translateY(-30px) scale(1.1)' },
            },
          },
        },
      },
    };
base.content = ["c:/New folder/htdocs/trainwise/index.php"];
module.exports = base;
