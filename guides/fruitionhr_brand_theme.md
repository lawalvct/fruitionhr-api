# FruitionHR — Brand & Theme Guide

Canonical colour/theme reference for all surfaces. The Tailwind palette below is implemented in `fruitionhr-web/src/app/globals.css` as `--color-fruition-*` tokens (use classes like `bg-fruition-700`).

## Logo

Three progressive green square frames — representing growth, structure, employee progress, career levels, and company scaling. Keep this direction.

Files (in `fruitionhr-web/public/`):
- `fruitionhr-logo-full.svg` — icon + wordmark + tagline ("Empowering Your Workforce")
- `fruitionhr-logo-icon.svg` — icon only (favicon, avatars, small spaces)
- `fruitionhr_logo.png` — raster fallback

## Core palette

| Usage | Colour | Hex |
|---|---|---|
| Primary Green (brand) | Deep professional | `#047857` |
| Secondary Green (accent) | Fresh growth | `#22C55E` |
| Light Green | Soft backgrounds/cards | `#DCFCE7` |
| Dark Green | Sidebar/header text | `#064E3B` |
| Charcoal | Main text | `#111827` |
| Slate Gray | Secondary text | `#64748B` |
| Border Gray | Inputs/cards border | `#E5E7EB` |
| Background | Dashboard background | `#F8FAFC` |
| White | Cards/content surface | `#FFFFFF` |
| Warning / Payroll attention | Amber | `#F59E0B` |
| Danger / Rejected / Error | Red | `#DC2626` |
| Info / Workflow status | Blue | `#2563EB` |

## Tailwind `fruition` scale (implemented)

```
50: #ECFDF5   100: #DCFCE7   200: #BBF7D0   300: #86EFAC   400: #4ADE80
500: #22C55E  600: #16A34A   700: #047857   800: #065F46   900: #064E3B
950: #022C22
```

## Per-surface themes

### Website (marketing)
- Primary `#047857`, Accent `#22C55E`, Background `#F8FAFC`, Text `#111827`, Muted `#64748B`
- Feel: clean, modern, trustworthy — generous white space, green gradients, soft cards, HR illustrations/product mockups.

### Tenant dashboard (professional, calm)
- Sidebar `#064E3B`, Sidebar active `#22C55E`, Main background `#F8FAFC`, Cards `#FFFFFF`
- Primary buttons `#047857`, Success `#16A34A`, Warning `#F59E0B`, Danger `#DC2626`, Info `#2563EB`

### Super admin dashboard (darker, executive)
- Sidebar/Header `#022C22`, Primary `#047857`, Accent `#10B981`
- Background `#F1F5F9`, Cards `#FFFFFF`, Text `#0F172A`, Muted `#64748B`

## Gradients

```css
/* Buttons, hero sections, login pages */
background: linear-gradient(135deg, #047857 0%, #22C55E 100%);

/* Premium dark hero */
background: linear-gradient(135deg, #022C22 0%, #047857 55%, #22C55E 100%);
```

Tailwind equivalents: `bg-linear-135 from-fruition-700 to-fruition-500` and `bg-linear-135 from-fruition-950 via-fruition-700 to-fruition-500`.

## Status colours (all dashboards)

| Meaning | Hex | Usage |
|---|---|---|
| Success / Approved | `#16A34A` | approvals, completed payroll |
| Warning / Attention | `#F59E0B` | pending payroll, expiring documents |
| Danger / Rejected | `#DC2626` | rejections, errors, overdue |
| Info / In workflow | `#2563EB` | in-progress workflow states |
