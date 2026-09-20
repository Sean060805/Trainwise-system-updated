const base = {
      theme: {
        extend: {
          colors: {
            primary:   '#4c1d95',
            secondary: '#3b0f7a',
            accent:    '#6d28d9',
            ink:       '#0f172a',
            muted:     '#64748b',
            line:      '#e2e8f0',
            success:   '#059669',
            warning:   '#d97706',
            danger:    '#e11d48',
            info:      '#0284c7',
            surface:   '#ffffff',
            colviolet: '#4c1d95',
            'colviolet-light': '#6d28d9',
            'colviolet-soft':  '#f5f3ff',
            colgold:   '#daa520',
            'colgold-light': '#e8c96e',
            'colgold-soft':  '#fefce8',
          },
          fontFamily: {
            'sans':    ['Inter', 'system-ui', 'sans-serif'],
            'display': ['Plus Jakarta Sans', 'Inter', 'sans-serif'],
            'mono':    ['JetBrains Mono', 'ui-monospace', 'monospace'],
          },
          borderRadius: { DEFAULT: '12px', 'button': '10px' },
        }
      }
    };
base.content = ["c:/New folder/htdocs/trainwise/COL_admin/col_eval.php"];
module.exports = base;
