const base = {
      theme: {
        extend: {
          colors: {
            primary: '#16a34a', secondary: '#15803d', accent: '#22c55e',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            green: '#16a34a', 'green-light': '#4ade80', 'green-soft': '#dcfce7',
            brown: '#a16207', 'brown-light': '#ca8a04', 'brown-soft': '#fef3c7',
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
base.content = ["c:/New folder/htdocs/trainwise/CA_admin/CA_Assessment Form.php", "c:/New folder/htdocs/trainwise/CA_admin/CA_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/CA_admin/ca_eval.php"];
module.exports = base;
