(function () {
    const KEY = 'theme-settings';
    const DEFAULT = { mode: 'light', h: 116, s: 56, over: null };

    const PALETTES = [
        ['Default', 116, 56], 
        ['Maple', 23, 78, { bg: [23, 78, 47], sidebar: [22, 40, 14], strong: [33, 89, 63], soft: [33, 89, 63], accent: [33, 89, 63], card: [36, 100, 94],}],
        ['Ocean', 200, 28, { bg: [200, 28, 72], sidebar: [193, 13, 20], strong: [196, 17, 57], soft: [47, 60, 89], accent: [47, 60, 89], card: [47, 60, 89],}],
        ['Corporate', 51, 9, { bg: [51, 9, 85], sidebar: [159, 56, 7], strong: [101, 15, 33], soft: [108, 8, 54], accent: [108, 8, 54], card: [47, 60, 89],}],
        ['Retro', 65, 100, { bg: [65, 100, 90], sidebar: [109, 47, 34], strong: [217, 39, 41], soft: [109, 47, 34], accent: [65, 100, 90], card: [65, 100, 90],}],

        ['Gray', 0, 0], ['Blue', 215, 55], ['Yellow', 48, 80],['Rose', 345, 60],
    ];

    function load() {
        try {
            const saved = { ...DEFAULT, ...JSON.parse(localStorage.getItem(KEY)) };
            if (saved.mode !== 'dark') saved.mode = 'light';
            return saved;
        } catch { return { ...DEFAULT }; }
    }
    let state = load();

    const isDark = () => state.mode === 'dark';

    function build(h, s, dark, over) {
        const hsl = (sat, l, a) => `hsl(${h} ${sat}% ${l}%${a ? ` / ${a}` : ''})`;
        const t = dark ? {
            bg: hsl(s * 0.4, 9), sidebar: hsl(s * 0.3, 13), text: hsl(20 + s * 0.1, 88),
            'main-text': hsl(20, 92), 'on-bg': hsl(20, 92),
            strong: hsl(s * 0.4, 28), 'on-strong': hsl(20, 95),
            soft: hsl(s * 0.3, 20), card: hsl(s * 0.3, 16),
            accent: hsl(Math.min(s, 60) * 0.7, 32), hover: 'rgba(255,255,255,0.08)',
            border: 'rgba(255,255,255,0.15)',
        } : {
            bg: hsl(s, 19), sidebar: hsl(Math.min(s, 20), 98), text: hsl(s, 19),
            'main-text': hsl(s, 5, 0.95), 'on-bg': '#ffffff',
            strong: hsl(s * 0.45, 40), 'on-strong': '#ffffff',
            soft: hsl(s * 0.45, 83), card: '#ffffff',
            accent: hsl(Math.min(s * 1.5, 80), 76), hover: 'rgba(97,97,97,0.15)',
            border: hsl(s, 19, 0.2),
        };

        t['on-accent'] = t.text;
        t['sidebar-text'] = t.text;

        const set = (k, v) => { t[k] = `hsl(${v[0]} ${v[1]}% ${v[2]}%)`; };
        if (over) for (const k in over) set(k, over[k]);

        if (over && over.sidebar) {
            const [sh, , sl] = over.sidebar;
            if (sl < 50) {
                t['sidebar-text'] = `hsl(${sh} 30% 92%)`;
                t.hover = 'rgba(255,255,255,0.1)';
            } else {
                t['sidebar-text'] = `hsl(${sh} 40% 15%)`;
                t.hover = 'rgba(97,97,97,0.15)';
            }
        }
        return t;
    }

    function apply() {
        const dark = isDark();
        const root = document.documentElement;
        const tokens = build(state.h, state.s, dark, state.over);
        for (const k in tokens) root.style.setProperty('--' + k, tokens[k]);
        root.style.colorScheme = dark ? 'dark' : 'light';
    }

    function save(patch) {
        state = { ...state, ...patch };
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch {}
        apply();
        render();
    }

    function hexToHs(hex) {
        const r = parseInt(hex.slice(1, 3), 16) / 255,
              g = parseInt(hex.slice(3, 5), 16) / 255,
              b = parseInt(hex.slice(5, 7), 16) / 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        const l = (max + min) / 2;
        let h = 0;
        if (d) {
            if (max === r) h = ((g - b) / d) % 6;
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
        }
        const s = d ? d / (1 - Math.abs(2 * l - 1)) : 0;
        return { h: Math.round((h * 60 + 360) % 360), s: Math.round(Math.min(s, 0.8) * 100) };
    }

    let grid, modeBtns;

    function render() {
        if (!grid) return;
        modeBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === state.mode));
        const dark = isDark();

        grid.innerHTML = '';
        PALETTES.forEach(([name, h, s, over]) => {
            const t = build(h, s, dark, over);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'swatch' + (state.h === h && state.s === s ? ' selected' : '');
            btn.title = name;
            btn.innerHTML = `<span class="swatch-dot"
                style="--c1:${t.soft};--c2:${t.strong};--c3:${t.accent}"></span>`;
            btn.addEventListener('click', () => save({ h, s, over: over || null }));
            grid.appendChild(btn);
        });

        const custom = document.createElement('label');
        custom.className = 'swatch swatch-custom';
        custom.title = 'Custom color';
        custom.innerHTML = '<span>🎨</span><input type="color">';
        custom.querySelector('input').addEventListener('input', e => save({ ...hexToHs(e.target.value), over: null }));
        grid.appendChild(custom);
    }

    function initPicker() {
        grid = document.getElementById('swatch-grid');
        if (!grid) return;
        modeBtns = [...document.querySelectorAll('.mode-btn')];
        modeBtns.forEach(b => b.addEventListener('click', () => save({ mode: b.dataset.mode })));
        render();
    }

    apply();
    document.addEventListener('DOMContentLoaded', initPicker);
})();