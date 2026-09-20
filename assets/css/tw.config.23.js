const base = {
      theme: {
        extend: {
          colors: {
            primary: '#d97706', secondary: '#b45309', accent: '#f59e0b',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            gold: '#f59e0b', 'gold-light': '#fbbf24', 'gold-soft': '#fef3c7',
            dark: '#1f2937', 'dark-soft': '#374151',
          },
          fontFamily: {
            'sans': ['Inter', 'system-ui', 'sans-serif'],
            'display': ['Plus Jakarta Sans', 'Inter', 'sans-serif'],
            'mono': ['JetBrains Mono', 'ui-monospace', 'monospace'],
          },
          borderRadius: { DEFAULT: '12px', 'button': '10px' },
        }
      }
    };
base.content = ["c:/New folder/htdocs/trainwise/CBAA_admin/CBAA_Assessment Form.php", "c:/New folder/htdocs/trainwise/CBAA_admin/CBAA_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/CBAA_admin/cbaa_eval.php"];
module.exports = base;
