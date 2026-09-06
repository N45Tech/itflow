---
name: N45 Field Mode
description: Calm operational clarity for technicians working at a client site.
colors:
  action: "#126e61"
  action-dark: "#7bdfc3"
  focus: "#087b6b"
  paper: "#f4f0e8"
  surface: "#fffdf8"
  ink: "#0a2423"
  muted: "#50635d"
  line: "#c9d6ce"
  soft: "#dcece5"
  paper-dark: "#0a2423"
  surface-dark: "#123431"
  ink-dark: "#f0f2ea"
  muted-dark: "#b9d0c5"
  line-dark: "#385750"
  soft-dark: "#23473f"
  masthead-support: "#d1eadf"
  danger: "#9b3228"
  danger-dark: "#ffad9f"
  attention-surface: "#fae4c9"
  attention-ink: "#71451b"
typography:
  headline:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "clamp(1.7rem, 3vw, 2.3rem)"
    fontWeight: 650
    lineHeight: 1.15
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "1.35rem"
    fontWeight: 650
    lineHeight: 1.25
    letterSpacing: "-0.015em"
  subhead:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "1.05rem"
    lineHeight: 1.4
  body:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "0.95rem"
    fontWeight: 600
  button:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 650
  hint:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "0.91rem"
    fontWeight: 400
    lineHeight: 1.5
  metadata:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "0.86rem"
    fontWeight: 400
  tag:
    fontFamily: "Segoe UI, system-ui, sans-serif"
    fontSize: "0.79rem"
    fontWeight: 600
    lineHeight: 1.4
rounded:
  tag: "5px"
  control: "8px"
  button: "9px"
  group: "10px"
  panel: "14px"
  dialog: "16px"
spacing:
  compact: "8px"
  tight: "12px"
  standard: "20px"
  generous: "24px"
components:
  button-primary:
    backgroundColor: "{colors.action}"
    textColor: "{colors.surface}"
    typography: "{typography.button}"
    rounded: "{rounded.button}"
    padding: "11px 18px"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.button}"
    rounded: "{rounded.button}"
    padding: "11px 18px"
  button-quiet:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    typography: "{typography.button}"
    rounded: "{rounded.button}"
    padding: "8px 11px"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "11px 12px"
  navigation-item:
    textColor: "{colors.muted}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
  navigation-item-active:
    backgroundColor: "{colors.soft}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.control}"
  tag:
    backgroundColor: "{colors.soft}"
    textColor: "{colors.ink}"
    typography: "{typography.tag}"
    rounded: "{rounded.tag}"
    padding: "4px 9px"
  tag-attention:
    backgroundColor: "{colors.attention-surface}"
    textColor: "{colors.attention-ink}"
    typography: "{typography.tag}"
    rounded: "{rounded.tag}"
    padding: "4px 9px"
  work-list-row:
    textColor: "{colors.ink}"
    padding: "19px 0"
  active-visit:
    backgroundColor: "{colors.soft}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: "23px 25px"
  arrival-panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
    padding: "22px"
  dialog:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.dialog}"
    padding: "26px"
---

# Design System: N45 Field Mode

## Overview

**Creative North Star: "N45 in the field"**

Field Mode carries N45's calm, capable operational identity into work at a client site. Warm paper, dark spruce, clear type, and restrained green actions keep job context legible beside the next useful action. The interface is compact enough for real work, with generous touch controls and ordinary language.

This document records the built system within `agent/field/`, from `field.css`, `shell.html`, and the rendered components in `app.mjs`. It extends the incumbent identity in the root `DESIGN.md` and `PRODUCT.md`; it does not replace guidance for the wider PSA or customer email. The accepted workflow remains in `docs/n45/field-mode.md`. The customer appointment projection keeps its existing portal components.

**Key Characteristics:**

- Warm neutral work surfaces beneath a steady dark spruce masthead.
- Text-led lists and task sections with quiet dividing rules.
- Large, plainly labeled controls and visible keyboard focus.
- Tonal emphasis for an active visit, selection, and arrival confirmation.
- A saved light/dark preference with the same information hierarchy.

## Colors

The field palette uses N45's paper, ink, and spruce with a darker action teal and stronger secondary text than the root email palette.

### Primary

- **Action Teal** (`action`) colors links, primary buttons, checkboxes, carets, and progress. Its dark-theme counterpart is a light mint (`action-dark`).
- **Focus Teal** (`focus`) provides the keyboard outline; dark mode shares the action mint for focus.

### Secondary

- **Attention Paper and Brown** (`attention-surface`, `attention-ink`) form a fixed pair for priority and unresolved-issue tags in both themes.
- **Error Red** (`danger`, `danger-dark`) identifies explanatory form errors. Text carries the meaning alongside color.

### Neutral

| Role | Light theme token | Dark theme token |
| --- | --- | --- |
| Page canvas | `paper` | `paper-dark` |
| Controls and raised forms | `surface` | `surface-dark` |
| Main text | `ink` | `ink-dark` |
| Supporting text | `muted` | `muted-dark` |
| Borders and dividers | `line` | `line-dark` |
| Selection and active-visit fill | `soft` | `soft-dark` |

The masthead stays dark spruce in both themes, using `surface-dark`, `ink-dark`, and `line-dark`, with `masthead-support` for connection text and the PSA link. Theme substitution changes role assignments together; primary button text follows the surface role.

**The State Has Words Rule.** Pair status color with a readable state label, an active navigation state, or an explanatory message.

## Typography

**Body and interface font:** Segoe UI with system-ui and sans-serif fallbacks. The built interface has functional page headings rather than a separate display face; it does not require a web-font download.

The hierarchy uses a compact, nonuniform scale. The headline role identifies the current page or job; title headings divide work sections; subheads name tasks. Body text remains at the base size, while labels, hints, and metadata step down gently. Form buttons retain body-size text with additional weight.

Job headlines use a fixed narrow-screen size (1.75rem). Paragraphs are bounded at 72ch, hints at 70ch, and the plain-text document reader uses a line height of 1.6 with preserved line breaks and long-word wrapping. Schedule times and elapsed activity numbers use tabular numerals. Case is ordinary sentence case; identifiers and acronyms retain their operational spelling.

**The Context Before Detail Rule.** Make the job or section title strongest, then support it with client, site, time, and status in the smaller text roles.

## Layout

The main column is centered within a maximum width of 1140px, including 24px side padding at wide widths. The masthead is 76px high. At the wide breakpoint (760px), navigation is a sticky row beneath the masthead, and job content can split into a flexible main area and a site-information aside. That split uses a 1.7-to-1 ratio, a minimum aside width of 260px, and a 44px gap.

Below the wide breakpoint, the masthead becomes 66px high, content has 20px side gutters, and the five-destination navigation is fixed to the bottom with safe-area padding. Main content reserves 105px at the bottom. The job aside stacks below a dividing rule. Heading actions and active-visit actions move beneath their text, while job section links scroll horizontally. Activity choices wrap into two columns where space requires it.

The spacing rhythm is practical rather than a strict mathematical scale: compact gaps join controls, medium gaps separate action groups, and larger spacing separates work sections. Lists use repeated horizontal rules and vertical row padding. Forms use a 19px row gap and can be bounded to 720px. Long instructions wrap instead of widening the layout.

**The Rows Carry Work Rule.** Use divided rows for schedules, documents, assets, notes, issues, and time history; reserve enclosed panels for arrival choices, active visits, and focused forms.

## Elevation & Depth

Persistent work surfaces are flat. Paper, surface fill, soft state fill, and thin borders supply most of the hierarchy. Only transient notices and modal forms use shadows; their exact values are recorded in the sidecar. Dialogs also dim the background with a translucent ink backdrop.

**The Transient Lift Rule.** Keep lists and persistent panels free of shadows; use depth to distinguish notices and modal forms from the work beneath them.

The only entrance animation reveals an active visit over 0.28 seconds with a clipped lower edge. It runs only when reduced motion is not requested. There is no shared hover-motion system: buttons use an immediate brightness change.

## Shapes

Controls have gently rounded corners, buttons are slightly softer, and compact rectangular tags retain distinct edges. Larger arrival and active-visit panels use the panel radius; focused dialogs use the largest radius. Form fields, secondary buttons, list rules, and arrival panels use a one-pixel border. The N45 mark keeps its existing asset geometry.

## Components

### Buttons

Primary buttons are filled action controls; secondary buttons use a transparent fill and quiet border; quiet buttons omit the visible border. Standard controls have a minimum height of 46px and retain descriptive text. Hover reduces brightness to 92%; disabled buttons lower opacity to 50% and keep the disabled cursor. The common keyboard treatment is a three-pixel focus outline offset by three pixels. Button-like links share the same shape and sizing.

### Inputs / Fields

Fields use the surface fill, ink text, a quiet border, and the control radius. Labels sit above fields, and checkboxes sit beside wrapping explanatory text. Inputs and selects have a minimum height of 46px; textareas start at 105px and resize vertically. Placeholders use the supporting text color at full opacity. Validation messages appear in error red with alert semantics. Pending note submission can make draft fields read-only and disable selection while preserving their contents.

### Navigation

The main navigation uses text labels and a soft filled current item. Its narrow-screen targets are at least 48px high, with equal available width. Job navigation uses the same selected treatment in a horizontally scrollable row, with targets at least 44px high. Project filtering uses a bordered segmented group and a soft fill on the pressed option. Current and pressed states are represented in markup as well as visually.

### Tags and work rows

Tags are small, filled state labels rather than buttons. The neutral version uses the soft fill; attention uses the warm semantic pair. Rows place the task or job name above supporting context and allow metadata to wrap. A schedule time column uses tabular figures. Task sections show instructions and evidence requirements beside the available next actions; completion can remain disabled with its reason shown in text.

### Arrival and active-visit panels

Arrival confirmation uses a bordered surface panel with a question, context, and direct actions. An active visit uses the soft fill and groups the current job, activity state, elapsed time, and visit controls. Both share the panel radius. The active panel stacks and uses 21px padding on narrow screens. Its state tag is operational information, not a decorative heading label.

### Dialogs and notices

Native dialogs provide a titled, focused form with a visible Close control. They are bounded to 540px and the available viewport width, with a maximum height of 90dvh; narrow screens use 22px internal padding. Notices sit above page content, use inverse text and fill, and announce changes politely. The privacy cover occupies the viewport and places a single reopening action beneath the N45 mark and explanatory text; covered work and navigation are hidden and inert.

### Customer appointment projection

The appointment section in `client/ticket.php` uses the portal's existing card, heading, supporting-text, and outline-button styles. `js/field_portal.js` supplies the technician and visit state, estimated arrival when relevant, a timestamped last-shared-location link, and the completed summary. It uses a polite live region. This projection inherits portal styling rather than importing the field app stylesheet; its behavior is documented in the field workflow contract.

## Do's and Don'ts

### Do:

- **Do** use the paired light and dark roles together, including action text and supporting text.
- **Do** retain N45's existing mark and the dark spruce masthead.
- **Do** preserve readable state labels, descriptive action text, and visible keyboard focus.
- **Do** keep operational records in divided rows and let long content wrap.
- **Do** keep the bottom navigation, safe-area allowance, and reserved content space working together on narrow screens.

### Don't:

- **Don't** introduce decorative gradients, promotional display treatments, or generic SaaS styling into this operational surface.
- **Don't** replace state words with color alone or use decorative glyphs as controls.
- **Don't** add shadows to persistent rows or panels that the built system keeps flat.
- **Don't** extend the active-visit reveal into ambient or repeated decorative animation.
- **Don't** apply these scoped field tokens to the wider PSA, portal, or email without checking their incumbent guidance.
