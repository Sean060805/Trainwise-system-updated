const base = {
      theme: {
        extend: {
          colors: {
            primary: '#65a30d', secondary: '#4d7c0f', accent: '#84cc16',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            green: '#65a30d', 'green-light': '#84cc16', 'green-soft': '#f0fdf4',
            gold: '#ca8a04', 'gold-light': '#eab308', 'gold-soft': '#fef3c7',
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
base.content = ["c:/New folder/htdocs/trainwise/CONAH_admin/CONAH_Assessment Form.php", "c:/New folder/htdocs/trainwise/CONAH_admin/CONAH_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/CONAH_admin/conah_eval.php"];
module.exports = base;
