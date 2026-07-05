# Members module PatternFly 6 visual restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Swap the Members module's club-orange visual identity for a PatternFly-6-resolved palette and restructure the Übersicht dashboard to match the design handoff's KPI-row + two-column layout, with zero behavior/route/data changes.

**Architecture:** This is a pure CSS-token + Blade-markup change. The existing `.ui-*` component classes in `resources/css/app.css` already mirror PF6 conventions structurally (card, button, table, KPI tile, badge), so the restyle is (1) replacing hex values in the `:root` custom-property block, (2) updating a handful of hardcoded hex values in `app/Modules/Members/resources/views/layout.blade.php`, and (3) restructuring `dashboard.blade.php` to the handoff's 3-KPI-tiles + 220px/1fr layout using two new small CSS component classes.

**Tech Stack:** Laravel Blade, Tailwind v4 (`@theme`/`@import 'tailwindcss'`), Alpine.js (unaffected), Vite.

---

## File structure

- Modify: `resources/css/app.css` — `:root` token values, border-radius values, two new component classes (`.ui-dashboard-lower`, `.ui-age-row`, `.ui-category-bar-*`) and their responsive collapse rule.
- Modify: `app/Modules/Members/resources/views/layout.blade.php` — hardcoded page-background and sidebar hex values.
- Modify: `app/Modules/Members/resources/views/dashboard.blade.php` — full rewrite to the KPI-row + two-column layout (no controller/data changes; `DashboardController` already supplies `$activeCount`, `$openDuesCount`, `$openDuesSum`, `$categoryCounts`, `$ageStructure`).

No other Members view needs changes: `members/index.blade.php`'s only hardcoded hex values (`#6a6e73`, `#3e8635`, `#c9190b`) are already correct under the new palette (confirmed by grep — see spec), and `dues/index.blade.php`, `fiscal-years/*.blade.php`, `fee-categories/index.blade.php` use only `.ui-*` classes, which pick up the new palette automatically once the CSS variables change.

---

### Task 1: Update CSS design tokens

**Files:**
- Modify: `resources/css/app.css:22-34` (root token block)
- Modify: `resources/css/app.css:62-68` (`.ui-card` radius)
- Modify: `resources/css/app.css:299-311` (`.ui-btn` radius)
- Modify: `resources/css/app.css:218-236` (`.ui-input`/`.ui-select` radius)
- Modify: `resources/css/app.css:420-423` (`.ui-badge-success` color)

- [ ] **Step 1: Replace the `:root` token block**

Current (`resources/css/app.css:22-34`):
```css
:root {
    --ui-orange: #bf4316;
    --ui-orange-hover: #9e3412;
    --ui-orange-soft: #fde8e1;
    --ui-text: #151515;
    --ui-text-secondary: #6a6e73;
    --ui-text-muted: #b8b8b8;
    --ui-bg: #fafafa;
    --ui-surface: #ffffff;
    --ui-border: #e0e0e0;
    --ui-sidebar: #1b1d21;
    --ui-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
```

Replace with:
```css
:root {
    --ui-orange: #0066cc;
    --ui-orange-hover: #004080;
    --ui-orange-soft: #e7f1fa;
    --ui-text: #151515;
    --ui-text-secondary: #6a6e73;
    --ui-text-muted: #b8bbbe;
    --ui-bg: #f0f0f0;
    --ui-surface: #ffffff;
    --ui-border: #d2d2d2;
    --ui-sidebar: #151515;
    --ui-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}
```

(Variable names are unchanged — only values move from the club-orange palette to PatternFly-6-resolved hex. Every `.ui-*` class that references `var(--ui-orange)`, `var(--ui-bg)`, `var(--ui-border)`, `var(--ui-sidebar)`, etc. — including `.ui-kpi`'s brand-colored top border and `.ui-popup-header`'s dark background — picks this up automatically.)

- [ ] **Step 2: Update card and control border-radius to PF6 small/medium scale**

Current (`resources/css/app.css:62-68`):
```css
.ui-card {
    background: var(--ui-surface);
    border: 1px solid var(--ui-border);
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}
```

Replace `border-radius: 8px;` with `border-radius: 6px;`.

Current (`resources/css/app.css:299-311`):
```css
.ui-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 0 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease;
    cursor: pointer;
}
```

Replace `border-radius: 6px;` with `border-radius: 3px;`.

Current (`resources/css/app.css:218-228`):
```css
.ui-input,
.ui-select,
.ui-textarea {
    width: 100%;
    border: 1px solid #c7c7c7;
    border-radius: 6px;
    background: #fff;
    color: var(--ui-text);
    font-family: var(--font-body);
    font-size: 14px;
}
```

Replace `border-radius: 6px;` with `border-radius: 3px;`.

- [ ] **Step 3: Align the success badge color with the PF6 status token**

Current (`resources/css/app.css:420-423`):
```css
.ui-badge-success {
    background: #f0faf0;
    color: #3e8635;
}
```

`#3e8635` already matches `--pf-t--global--color--status--success--default` — leave this class unchanged. (No edit needed; this step exists only to confirm during review that no stray success-color hex needs touching. Skip to Step 4.)

- [ ] **Step 4: Rebuild frontend assets**

Run (inside WSL, per project convention):
```bash
wsl npm run build
```
Expected: Vite build completes with no errors, `public/build/` manifest updated.

- [ ] **Step 5: Commit**

```bash
git add resources/css/app.css public/build
git commit -m "Restyle Members module tokens to PatternFly 6 palette"
```

---

### Task 2: Update hardcoded hex in the Members layout shell

**Files:**
- Modify: `app/Modules/Members/resources/views/layout.blade.php:14-15,35`

- [ ] **Step 1: Update the page background utility class**

Current (`app/Modules/Members/resources/views/layout.blade.php:14`):
```blade
<div class="flex min-h-screen bg-[#f3f1ec]">
```

Replace with:
```blade
<div class="flex min-h-screen bg-[#f0f0f0]">
```

- [ ] **Step 2: Update the sidebar/masthead background**

Current (`app/Modules/Members/resources/views/layout.blade.php:15`):
```blade
    <aside style="width:180px; min-height:100vh; background:#1b1d21; flex-shrink:0; display:flex; flex-direction:column;">
```

Replace with:
```blade
    <aside style="width:180px; min-height:100vh; background:#151515; flex-shrink:0; display:flex; flex-direction:column;">
```

- [ ] **Step 3: Update the active-nav-item accent color**

Current (`app/Modules/Members/resources/views/layout.blade.php:35`), find `border-left:{{ $active ? '3px solid #bf4316' : '3px solid transparent' }};` inside the nav loop's inline style and replace `#bf4316` with `#0066cc`:
```blade
                <a href="{{ route($item['route']) }}" onmouseover="if (!this.dataset.active) { this.style.background='#26292e'; this.style.color='#ffffff'; }" onmouseout="if (!this.dataset.active) { this.style.background='transparent'; this.style.color='#b2b6bd'; }" data-active="{{ $active ? '1' : '' }}" style="display:block; padding:12px 14px 12px {{ $active ? '11px' : '17px' }}; font-family:var(--font-body); font-size:14px; text-decoration:none; transition:background 0.15s ease, color 0.15s ease; color:{{ $active ? '#ffffff' : '#b2b6bd' }}; font-weight:{{ $active ? '700' : '400' }}; background:{{ $active ? '#34363b' : 'transparent' }}; border-left:{{ $active ? '3px solid #0066cc' : '3px solid transparent' }};">
```

- [ ] **Step 4: Commit**

```bash
git add app/Modules/Members/resources/views/layout.blade.php
git commit -m "Update Members sidebar/masthead hex to PatternFly 6 palette"
```

---

### Task 3: Add dashboard layout CSS components

**Files:**
- Modify: `resources/css/app.css` (append near the `.ui-kpi*` rules, e.g. after line 372, and extend the existing `@media (max-width: 768px)` block at line 508-514)

- [ ] **Step 1: Add the two-column dashboard-lower grid and age-list rows**

Insert directly after the `.ui-kpi-meta` rule (`resources/css/app.css:369-372`):
```css
.ui-dashboard-lower {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 1rem;
}

.ui-age-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 0;
    border-bottom: 1px solid #f2f2f2;
    font-size: 13px;
    color: var(--ui-text);
}

.ui-age-row:last-child {
    border-bottom: none;
}

.ui-category-bar-row + .ui-category-bar-row {
    margin-top: 0.75rem;
}

.ui-category-bar-label {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: 0.35rem;
    font-size: 13px;
    color: var(--ui-text);
}

.ui-category-bar-track {
    height: 7px;
    border-radius: 4px;
    background: var(--ui-border);
    overflow: hidden;
}

.ui-category-bar-fill {
    height: 100%;
    border-radius: 4px;
    background: var(--ui-orange);
}
```

- [ ] **Step 2: Collapse the two-column layout on small screens**

Current (`resources/css/app.css:508-514`):
```css
@media (max-width: 768px) {
    .ui-grid-2,
    .ui-grid-3,
    .ui-grid-4 {
        grid-template-columns: 1fr;
    }
}
```

Replace with:
```css
@media (max-width: 768px) {
    .ui-grid-2,
    .ui-grid-3,
    .ui-grid-4,
    .ui-dashboard-lower {
        grid-template-columns: 1fr;
    }
}
```

- [ ] **Step 3: Rebuild frontend assets**

Run:
```bash
wsl npm run build
```
Expected: Vite build completes with no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css public/build
git commit -m "Add dashboard two-column layout CSS components"
```

---

### Task 4: Restructure the Übersicht dashboard view

**Files:**
- Modify: `app/Modules/Members/resources/views/dashboard.blade.php` (full rewrite)

No controller change — `app/Modules/Members/Http/Controllers/DashboardController.php` already passes `$activeCount`, `$openDuesCount`, `$openDuesSum`, `$categoryCounts` (assoc array: category name => count), and `$ageStructure` (`['buckets' => [bucket => count, ...], 'unknown' => count]`).

- [ ] **Step 1: Replace the view content**

Current full file (`app/Modules/Members/resources/views/dashboard.blade.php`):
```blade
@extends('members::layout')
@section('admin-title', __('members::admin.dashboard.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dashboard.title') }}</h1></div>

    <div class="ui-grid-3">
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.active_members') }}</p>
                <p class="text-3xl font-bold">{{ $activeCount }}</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.open_dues') }}</p>
                <p class="text-3xl font-bold">{{ $openDuesCount }}</p>
                <p class="ui-kpi-meta">{{ number_format((float) $openDuesSum, 2) }} €</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.by_category') }}</p>
                @forelse($categoryCounts as $categoryName => $count)
                    <p>{{ $categoryName }}: {{ $count }}</p>
                @empty
                    <p class="ui-kpi-meta">—</p>
                @endforelse
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.age_structure') }}</p>
                @foreach($ageStructure['buckets'] as $bucket => $count)
                    <p>{{ __('members::admin.dashboard.age_'.$bucket) }}: {{ $count }}</p>
                @endforeach
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.age_unknown') }}: {{ $ageStructure['unknown'] }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
```

Replace with:
```blade
@extends('members::layout')
@section('admin-title', __('members::admin.dashboard.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dashboard.title') }}</h1></div>

    <div class="ui-grid-3">
        <div class="ui-card ui-kpi">
            <div class="ui-card-body">
                <p class="ui-kpi-label">{{ __('members::admin.dashboard.active_members') }}</p>
                <p class="ui-kpi-value">{{ $activeCount }}</p>
            </div>
        </div>
        <div class="ui-card ui-kpi">
            <div class="ui-card-body">
                <p class="ui-kpi-label">{{ __('members::admin.dashboard.open_dues') }}</p>
                <p class="ui-kpi-value">{{ $openDuesCount }}</p>
                <p class="ui-kpi-meta">{{ number_format((float) $openDuesSum, 2) }} €</p>
            </div>
        </div>
        <div class="ui-card ui-kpi">
            <div class="ui-card-body">
                <p class="ui-kpi-label">{{ __('members::admin.dashboard.by_category') }}</p>
                <p class="ui-kpi-value">{{ count($categoryCounts) }}</p>
            </div>
        </div>
    </div>

    <div class="ui-dashboard-lower">
        <div class="ui-card">
            <div class="ui-card-header"><h2>{{ __('members::admin.dashboard.age_structure') }}</h2></div>
            <div class="ui-card-body">
                @foreach($ageStructure['buckets'] as $bucket => $count)
                    <div class="ui-age-row">
                        <span>{{ __('members::admin.dashboard.age_'.$bucket) }}</span>
                        <span>{{ $count }}</span>
                    </div>
                @endforeach
                <div class="ui-age-row">
                    <span>{{ __('members::admin.dashboard.age_unknown') }}</span>
                    <span>{{ $ageStructure['unknown'] }}</span>
                </div>
            </div>
        </div>

        <div class="ui-card">
            <div class="ui-card-header"><h2>{{ __('members::admin.dashboard.by_category') }}</h2></div>
            <div class="ui-card-body">
                @php $maxCategoryCount = count($categoryCounts) > 0 ? max($categoryCounts) : 0; @endphp
                @forelse($categoryCounts as $categoryName => $count)
                    <div class="ui-category-bar-row">
                        <div class="ui-category-bar-label">
                            <span>{{ $categoryName }}</span>
                            <span>{{ $count }}</span>
                        </div>
                        <div class="ui-category-bar-track">
                            <div class="ui-category-bar-fill" style="width: {{ $maxCategoryCount > 0 ? round($count / $maxCategoryCount * 100) : 0 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="ui-kpi-meta">—</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 2: Commit**

```bash
git add app/Modules/Members/resources/views/dashboard.blade.php
git commit -m "Restructure Übersicht dashboard to KPI-row + two-column layout"
```

---

### Task 5: Visual verification across all 5 screens

**Files:** none (verification only)

- [ ] **Step 1: Start the app and log in as an admin**

Run (WSL, per project convention — see `feedback_php_wsl` memory):
```bash
wsl php artisan serve
```
Log in and navigate to `/members/dashboard`.

- [ ] **Step 2: Check the Übersicht screen**

Confirm:
- 3 KPI tiles across the top (Aktive Mitglieder, Offene Beiträge, Mitglieder pro Kategorie), each with a blue (`#0066cc`) top border, big bold number, uppercase label.
- Below: a 220px-wide "Altersstruktur" card (left) with hairline-divided rows, and a wider "Mitglieder pro Kategorie" card (right) with a horizontal bar per category, bar length proportional to the largest category's count.
- No console errors.

- [ ] **Step 3: Check Mitglieder, Beiträge, Fiskaljahre, Beitragskategorien**

For each of `/members`, `/members/dues`, `/members/fiscal-years`, `/members/fee-categories`:
- Cards have a 6px radius, `#d2d2d2` border, white surface.
- Buttons/inputs have a 3px radius; primary buttons are blue (`#0066cc`).
- Sidebar and masthead are near-black (`#151515`), active nav item has a blue left accent.
- All existing filters, inline-cell editing (click a name/email/fee-category/joined-at cell on the Mitglieder list), and form submissions still work exactly as before — this task changed no PHP or JS logic.

- [ ] **Step 4: Confirm no regressions in behavior**

Click "Bearbeiten" on a member row, edit a field, confirm it still autosaves on blur (per `members/index.blade.php`'s existing Alpine logic — untouched by this plan). Confirm the "Beiträge für dieses Fiskaljahr generieren" button still works.

No commit for this task — it's verification only. If any visual issue is found, fix it in the relevant task's files and re-run this task's checks.

---

## Self-review notes

- **Spec coverage:** token table (Task 1 + 2), Übersicht layout restructuring (Task 3 + 4), "no other screen needs structural changes" (confirmed via grep in Task file-structure section, verified in Task 5). Out-of-scope items from the spec (Funktionen, Mail, row-edit mode, Ruhend status, payment-status pills, Beiträge inline-add row) are untouched — no task creates them.
- **Placeholder scan:** none — every step has literal before/after code or an exact shell command.
- **Type/name consistency:** `.ui-kpi-label`/`.ui-kpi-value` (existing classes, previously unused in `dashboard.blade.php` which used `.ui-kpi-meta` + raw Tailwind `text-3xl font-bold` — Task 4 switches to the existing intended classes) are used consistently between Task 3 (CSS, no new KPI classes needed since they already exist) and Task 4 (Blade). New classes introduced in Task 3 (`.ui-dashboard-lower`, `.ui-age-row`, `.ui-category-bar-row/-label/-track/-fill`) are exactly the classes used in Task 4's replacement markup.
