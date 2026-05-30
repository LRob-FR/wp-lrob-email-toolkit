# LRob brand palette — "Deep Glass"

Derived from variant **D** of the admin-theme mockup. Reusable for the lrob.fr
WordPress theme rework. Glass surfaces use **dark-navy translucency** (not white)
so depth never washes the page out; solid hex equivalents are given for places
where translucency isn't practical.

## Core brand

| Role | Hex | Notes |
|---|---|---|
| **Amber accent** | `#ffb700` | links, buttons, focus, active state |
| Amber accent — hover | `#ffc62e` | button/link hover |
| Text on amber | `#000000` | black text on amber buttons |
| Links (on dark) | `#ffb700` | body/table links use full amber — reads better than a soft tint |
| **Heading blue** | `#4fb3ec` | titles & section headings |

## Backgrounds (darkest → lightest)

| Role | Value | Notes |
|---|---|---|
| **Page base** | `#051826` | the keeper — primary navy |
| Page gradient | `linear-gradient(160deg, #03101b 0%, #062236 50%, #0a3152 100%)` | energetic backdrop |
| Panel navy (solid alt) | `#0D345F` | for opaque panels / royal accents |
| **Card (glass)** | `rgba(11, 35, 55, .82)` + `backdrop-filter: blur(8px)` | translucent navy card |
| Card (solid fallback) | `#0a2236` | when blur/translucency isn't available |
| Input / field | `#0f2d44` | solid, for legibility |
| Soft fill / hover | `#11314a` | table row hover, secondary buttons |

## Text

| Role | Hex |
|---|---|
| **Bright text** ("baby powder") | `#FBFEF9` |
| **Body / muted text** | `#C3C3C3` |

## Lines / borders

| Role | Glass (over navy) | Solid equivalent |
|---|---|---|
| Line | `rgba(255,255,255,.12)` | `#273c4e` |
| Line — strong | `rgba(255,255,255,.22)` | `#405262` |

## Status colours (semantic — kept conventional)

| State | Foreground | Background | Text-tint (on dark) |
|---|---|---|---|
| Success | `#3bbf5b` | `#13301e` | `#86dd9f` |
| Warning | `#e0a92a` | `#2f2912` | `#e8cc6e` |
| Danger  | `#e86365` | `#3a1c1d` | `#f3a9aa` |
| Info / accent | `#ffb700` | `rgba(255,183,0,.14)` | `#ffb700` |

## Ready-to-paste CSS custom properties

```css
:root {
  /* brand */
  --lrob-amber:        #ffb700;
  --lrob-amber-hover:  #ffc62e;
  --lrob-on-amber:     #000000;
  --lrob-blue:         #4fb3ec;

  /* backgrounds */
  --lrob-page:         #051826;
  --lrob-page-grad:    linear-gradient(160deg, #03101b 0%, #062236 50%, #0a3152 100%);
  --lrob-panel:        #0d345f;
  --lrob-card:         rgba(11, 35, 55, .82);   /* + backdrop-filter: blur(8px) */
  --lrob-card-solid:   #0a2236;
  --lrob-input:        #0f2d44;
  --lrob-soft:         #11314a;

  /* text */
  --lrob-text:         #fbfef9;
  --lrob-text-muted:   #c3c3c3;

  /* lines */
  --lrob-line:         rgba(255,255,255,.12);
  --lrob-line-strong:  rgba(255,255,255,.22);

  /* status */
  --lrob-success:      #3bbf5b;
  --lrob-warning:      #e0a92a;
  --lrob-danger:       #e86365;
}
```

## Quick usage cheatsheet

- **Buttons**: amber `#ffb700` bg, black text; hover `#ffc62e`.
- **Links** in body text: full amber `#ffb700` (reads better than a soft tint on dark).
- **Headings**: `#4fb3ec`.
- **Body copy**: `#C3C3C3`; emphasised/lead copy: `#FBFEF9`.
- **Cards**: translucent navy + blur over the gradient page; fall back to `#0a2236` solid.
- **Focus ring**: `0 0 0 2px rgba(255,183,0,.14)` + amber border.
