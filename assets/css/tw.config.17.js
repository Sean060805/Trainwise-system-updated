const base = {
      theme: {
        extend: {
          colors: {
            primary: '#2563eb', secondary: '#1d4ed8', accent: '#3b82f6',
            ink: '#0f172a', muted: '#64748b', line: '#e2e8f0',
            success: '#059669', warning: '#d97706', danger: '#e11d48',
            info: '#0284c7', surface: '#ffffff',
            blue: '#2563eb', 'blue-light': '#3b82f6', 'blue-soft': '#dbeafe',
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
base.content = ["c:/New folder/htdocs/trainwise/COF_admin/COF_Assessment Form.php", "c:/New folder/htdocs/trainwise/COF_admin/COF_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/COF_admin/cof_eval.php"];
module.exports = base;
