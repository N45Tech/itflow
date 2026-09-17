---
name: N45 ITFlow
description: A quietly modern, dense operations workspace with a calm customer-facing edge.
colors:
  mountain-ink: "#0A2423"
  deep-spruce: "#123431"
  action-teal: "#167F70"
  trail-teal: "#49C8B1"
  sunrise: "#F2A65A"
  river-mist: "#DDE8E2"
  trail-paper: "#F4F0E8"
  ridge-stone: "#62736E"
  canvas: "#EEF2F1"
  surface: "#FFFFFF"
  surface-subtle: "#F6F8F7"
  border: "#D4DED9"
  ink: "#142421"
  muted: "#5B6D68"
  success: "#157347"
  warning: "#8A5D00"
  danger: "#B42318"
typography:
  headline:
    fontFamily: "Segoe UI, Inter, -apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif"
    fontSize: "1.42rem"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.025em"
  title:
    fontFamily: "Segoe UI, Inter, -apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif"
    fontSize: "1.08rem"
    fontWeight: 675
    lineHeight: 1.25
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Segoe UI, Inter, -apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Segoe UI, Inter, -apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif"
    fontSize: "0.76rem"
    fontWeight: 700
    lineHeight: 1.25
    letterSpacing: "0.025em"
rounded:
  sm: "0.45rem"
  md: "0.7rem"
  lg: "0.9rem"
spacing:
  xs: "0.25rem"
  sm: "0.5rem"
  md: "1rem"
  lg: "1.5rem"
  xl: "2rem"
components:
  button-primary:
    backgroundColor: "{colors.action-teal}"
    textColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    padding: "0.375rem 0.75rem"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted}"
    rounded: "{rounded.sm}"
    padding: "0.375rem 0.75rem"
  input:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    height: "2.35rem"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "{spacing.md}"
  status-tab:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.muted}"
    rounded: "{rounded.lg}"
    padding: "0.28rem 0.55rem"
---

# Design System: N45 ITFlow

## Overview

**Creative North Star: "The Calm Operations Desk"**

N45 ITFlow should feel like a well-run technical workspace: capable, grounded, quietly modern, and immediately legible under pressure. The agent experience preserves information density and makes hierarchy through typography, borders, and restrained brand color instead of oversized cards or excessive whitespace.

Customer-facing surfaces share the same spruce-and-teal identity with warmer paper backgrounds and more breathing room. The system avoids generic SaaS gloss, decorative gradients, alarmist color, glass effects, and shadows used as ornament.

**Key Characteristics:**

- Dense operational layouts with clear page, section, and row hierarchy.
- Deep spruce navigation, neutral work surfaces, and teal reserved for interaction.
- Flat-by-default containers with quiet borders and predictable responsive stacking.
- Semantic status color that never becomes the page's visual personality.

## Colors

The palette pairs forest neutrals with one dependable action teal; Trail Teal and Sunrise are sparse accents, not competing calls to action.

### Primary

- **Action Teal:** Primary actions, links, focus treatment, and selected states.
- **Trail Teal:** Signature accent for icons, separators, and dark-mode interactive text.

### Secondary

- **Mountain Ink:** Navigation, high-emphasis headings, and brand anchoring.
- **Deep Spruce:** Application and portal navigation surfaces.

### Tertiary

- **Sunrise:** Rare warm emphasis, warnings that need brand character, and portal focus cues.

### Neutral

- **Canvas:** Technician workspace background.
- **Surface / Surface Subtle:** Primary containers and quiet control regions.
- **Border:** Structure between dense records without heavy dividers.
- **Ink / Muted:** Primary and supporting text.

**The One Action Rule.** Use Action Teal for interaction; use success, warning, and danger only to communicate real state.

**The Contrast Pair Rule.** Filled teal states use the paired on-action foreground, including dark mode; never assume white is readable on every teal.

## Typography

- **Display Font:** Segoe UI with system sans-serif fallbacks.
- **Body Font:** Segoe UI with system sans-serif fallbacks.
- **Email Heading Font:** Georgia with Times New Roman and serif fallbacks.
- **Utility Font:** Consolas or a generic monospace fallback only when operationally useful.

**Character:** Product type is compact and neutral so identifiers, counts, and states scan quickly. Customer email uses a restrained serif heading to add warmth without changing the product interface.

### Hierarchy

- **Headline:** Page-level orientation; compact, bold, and never hero-sized.
- **Title:** Workspace and panel headings with slightly tightened tracking.
- **Body:** Default records, forms, and explanatory copy; prose should remain near 72 characters per line where practical.
- **Label:** Small, strong, lightly tracked utility text; uppercase is appropriate for table and KPI labels, not sentences.

**The Operational Scale Rule.** A routine application page should not use marketing-scale typography.

## Layout

Agent pages use the full available workspace up to a wide operational ceiling, with compact vertical rhythm and responsive Bootstrap grids. The shared workspace pattern places page identity and actions in one header, filters in a quiet secondary band, and records directly below. Dashboard and Operations use the same hierarchy for page leads, KPI strips, and panels.

At tablet widths, page leads and major headers stack. At phone widths, outer gutters tighten, workspace cards meet the viewport edges, primary actions expand when useful, status tabs keep a 44-pixel touch target, and wide records remain horizontally scrollable rather than collapsing into ambiguous prose.

The client portal keeps a calmer Trail Paper canvas and a persistent navigation model that becomes an off-canvas menu on small screens. Customer email uses a centered 640-pixel table shell and must remain useful when clients strip head styles.

## Elevation & Depth

The interface is flat by default. Canvas, surface tone, and one-pixel borders establish hierarchy; ordinary cards do not float. A single restrained ambient shadow is reserved for true overlays such as menus, dialogs, and authentication cards.

**The Flat-by-Default Rule.** If a border or tonal shift can explain containment, do not add a shadow.

## Shapes

Controls use gently curved small corners, standard cards use a medium radius, and dialogs or authentication shells may use the large radius. Pills are reserved for compact status navigation and badges. Dense tables stay rectilinear inside their parent container, and overlay menus must not be clipped by decorative overflow rules.

## Components

### Buttons

- **Shape:** Compact curved controls using the small radius.
- **Primary:** Action Teal fill with a contrast-safe foreground; one dominant action per region.
- **Secondary:** White or quiet surface fill with a visible border and muted-strong text.
- **Hover / Focus:** Short color transitions and a visible three-pixel teal focus ring; reduced-motion preferences remove meaningful transition duration.
- **Pending:** Preserve the control's hierarchy, pair a compact spinner with action-specific copy, expose `aria-busy`, and prevent repeated activation until navigation or recovery.

### Interaction Feedback

- **Form actions:** High-value create, update, resolve, and destructive forms opt into the shared pending state. The chosen submit action must remain present in the request even after its control is disabled.
- **AJAX dialogs:** Open an accessible loading shell immediately. Network or server failures remain inside the dialog with specific recovery copy plus Retry and Close actions; never fall back to a native browser alert.
- **Focus:** Move focus into loaded dialog content, return it to the originating control on close or cancellation, and use the parent menu trigger when the original control becomes hidden.
- **Recovery:** Abort stale requests, suppress duplicate modal loads and form submissions, and clear stale busy states when a page returns from the back-forward cache.

### Automated Investigations

- **Placement:** Keep analysis inside the existing automation incident card so source state remains the primary record.
- **Disclosure:** Label model output as read-only and AI-generated, and state explicitly when no remediation was attempted.
- **Structure:** Present the concise summary first, then likely cause, impact, confidence, evidence, recommended actions, and expandable unknowns.
- **Trust:** Render only escaped structured fields; never present model HTML, credentials, or unverified conclusions as confirmed facts.

### Status Tabs and Badges

- **Style:** Compact pills within the light workspace header; selection uses a quiet teal-tinted fill and Action Teal underline.
- **State:** `aria-current="page"` is required for active navigation. Badges communicate state and counts, never decoration.

### Cards / Containers

- **Corner Style:** Gently curved medium radius.
- **Background:** Surface over canvas with a quiet structural border.
- **Shadow Strategy:** None at rest; overlays only.
- **Internal Padding:** Compact for agent records, more generous for the client portal.

### Shared Page Structure

- **Workspace lists:** One compact light workspace header holds the page title, state or type tabs, client context, and a right-aligned primary action, matching the established Vendors composition. Filters live in the quiet band immediately below it; records and pagination follow without a second title bar.
- **Detail and summary pages:** Breadcrumbs precede one page lead containing the title, restrained status, supporting text, client context, and actions. Summary metrics or record panels follow in reading order.
- **Action hierarchy:** Expose one primary action per page region. Import, export, linking, archival, and destructive operations belong in a labeled secondary or split menu.
- **Reusable PHP:** New or migrated pages use the renderers in `functions/ui.php` for page headers, tabs, actions, context, badges, empty states, and modal headers. Business rules and permission checks stay in the calling page.
- **Results:** Primary tables use `.n45-data-table`; shared listing footers announce the visible range and provide pagination. Empty states distinguish an unused workspace from filters that happen to return zero rows.

### Inputs / Fields

- **Style:** White surface, strong neutral border, compact height, and small radius.
- **Focus:** Action Teal border plus a soft focus ring.
- **Labels:** Strong muted text close to the associated control; placeholder text remains secondary.

### Navigation

The application sidebar is Deep Spruce with low-contrast section labels, light default links, restrained hover fill, and a single Action Teal active state. The top bar is a light utility surface. The portal follows the same identity with simpler language and clearer section grouping.

### Customer Email

Use one primary action with descriptive text, a meaningful preheader, an equivalent plain-text body, and a simple fact table for identifiers, dates, statuses, and amounts. Keep styles inline, keep the hosted PNG lockup at no more than 360 pixels wide, and preserve ITFlow's exact ticket reply marker in reply-enabled messages.

## Do's and Don'ts

### Do:

- **Do** preserve dense scanning on technician pages while maintaining 44-pixel mobile touch targets.
- **Do** use semantic headings, labeled navigation, `aria-current`, and visible keyboard focus.
- **Do** load the isolated N45 theme after upstream and compatibility CSS.
- **Do** use descriptive customer language such as “View ticket” and “Review invoice.”
- **Do** verify both light and dark contrast when adding filled interactive states.

### Don't:

- **Don't** target retired AdminLTE 3 shell selectors in new theme work.
- **Don't** use decorative gradients, glass effects, excessive shadows, or oversized hero typography.
- **Don't** recolor destructive, warning, success, or informational actions as neutral menu items.
- **Don't** spend vertical space duplicating navigation already present in the sidebar.
- **Don't** rely on hover, web fonts, SVG support, or JavaScript for essential email meaning.
