const base = {
      theme: {
        extend: {
          colors: {
            primary: '#065f46', secondary: '#047857', accent: '#059669',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            green: '#065f46', 'green-light': '#059669', 'green-soft': '#d1fae5',
            grey: '#6b7280', 'grey-light': '#9ca3af', 'grey-soft': '#f3f4f6',
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
base.content = ["c:/New folder/htdocs/trainwise/COE_admin/COE_Assessment Form.php", "c:/New folder/htdocs/trainwise/COE_admin/COE_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/COE_admin/coe_eval.php"];
module.exports = base;
