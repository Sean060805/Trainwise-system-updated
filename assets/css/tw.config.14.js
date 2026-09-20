const base = {
      theme: {
        extend: {
          colors: {
            primary:   '#4f46e5',
            secondary: '#4338ca',
            accent:    '#6366f1',
            ink:       '#0f172a',
            muted:     '#64748b',
            line:      '#e2e8f0',
            success:   '#059669',
            warning:   '#d97706',
            danger:    '#e11d48',
            info:      '#0284c7',
            surface:   '#ffffff',
            cteblue:   '#3b82f6',
            'cteblue-light': '#60a5fa',
            'cteblue-soft':  '#eff6ff',
            cteyellow: '#fbbf24',
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
base.content = ["c:/New folder/htdocs/trainwise/CTE_admin/CTE_Assessment_Form.php", "c:/New folder/htdocs/trainwise/CTE_admin/CTE_Individual_Development_Plan_Form.php", "c:/New folder/htdocs/trainwise/CTE_admin/cte_eval.php"];
module.exports = base;
