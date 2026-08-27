# N45 ITFlow Design System

## Overview

N45's ITFlow surfaces should feel calm, capable, and locally accountable. The
product UI prioritizes dense operational clarity; customer email uses the same
identity with more breathing room and a single dominant action.

## Color

| Token | Value | Use |
| --- | --- | --- |
| Mountain Ink | `#0A2423` | Primary headings and brand rule |
| Deep Spruce | `#123431` | Dark supporting surfaces |
| Action Teal | `#167F70` | Email links and accessible buttons |
| Trail Teal | `#49C8B1` | Signature accent and separators |
| Sunrise | `#F2A65A` | Sparse warm emphasis |
| River Mist | `#DDE8E2` | Email canvas and quiet borders |
| Trail Paper | `#F4F0E8` | Supporting light surface |
| Ridge Stone | `#62736E` | Secondary text |

## Typography

- Product UI: Segoe UI with system sans-serif fallbacks.
- Email headings: Georgia with Times New Roman and serif fallbacks.
- Email body: Segoe UI, Arial, Helvetica, and sans-serif fallbacks.
- Utility labels: Consolas or a generic monospace fallback when operationally
  useful; never as decoration.

## Customer Email

- Use a centered, 640-pixel table shell on a River Mist canvas.
- Keep styles inline; media queries may enhance narrow screens but content must
  remain usable when a client strips the head block.
- Use one primary action button with descriptive text.
- Present identifiers, dates, statuses, and amounts in a simple fact table.
- Keep the hosted PNG N45 lockup at a maximum width of 360 pixels.
- Preserve ITFlow's exact ticket reply marker in reply-enabled messages.
- Include a meaningful preheader and an equivalent plain-text body.

## Voice

State what happened, what it means, and what the recipient can do next. Prefer
"View ticket" and "Review invoice" over "Click here." Use reassuring language
for security events and avoid blame or alarmist phrasing.

## Responsive Behavior

At narrow widths, remove nonessential outer padding, stack fact labels above
values, and expand the primary action to the available width. Do not depend on
hover, web fonts, SVG support, or JavaScript.
