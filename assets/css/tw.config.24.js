const base = {
      theme: {
        extend: {
          colors: {
            primary: '#6d28d9', secondary: '#5b21b6', accent: '#7c3aed',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            purple: '#6d28d9', 'purple-light': '#8b5cf6', 'purple-soft': '#ede9fe',
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
base.content = ["c:/New folder/htdocs/trainwise/CAS_admin/CAS_Assessment Form.php", "c:/New folder/htdocs/trainwise/CAS_admin/CAS_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/CAS_admin/cas_eval.php"];
module.exports = base;
