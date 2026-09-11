const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const root = 'c:/New folder/htdocs/trainwise';
const scratch = __dirname;
const cssOutDir = path.join(root, 'assets', 'css');
fs.mkdirSync(cssOutDir, { recursive: true });

const groups = JSON.parse(fs.readFileSync(path.join(scratch, 'tw_groups.json'), 'utf8'));

// Shared input CSS with the 3 Tailwind directives.
const inputCssPath = path.join(scratch, 'tw-input.css');
fs.writeFileSync(inputCssPath, '@tailwind base;\n@tailwind components;\n@tailwind utilities;\n');

for (const g of groups) {
    const contentGlobs = g.files.map(rel => JSON.stringify((root + '/' + rel.replace(/\\/g, '/'))));
    const cfgPath = path.join(scratch, `tw.config.${g.id}.js`);
    const cfgContent =
`const base = ${g.configText};
base.content = [${contentGlobs.join(', ')}];
module.exports = base;
`;
    fs.writeFileSync(cfgPath, cfgContent);
}

console.log('Wrote', groups.length, 'config files. Building...');

let failCount = 0;
for (const g of groups) {
    const cfgPath = path.join(scratch, `tw.config.${g.id}.js`);
    const outPath = path.join(cssOutDir, `tw-${g.id}.css`);
    const cmd = `npx tailwindcss -i "${inputCssPath}" -o "${outPath}" --config "${cfgPath}" --minify`;
    try {
        execSync(cmd, { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (e) {
        failCount++;
        console.log('BUILD FAILED for group', g.id, ':', e.stderr ? e.stderr.toString() : e.message);
    }
}
console.log('Done.', failCount, 'failures out of', groups.length);
