---
name: nuvora-design
description: >-
  Apply the Nuvora visual system based on the Velzon dark dashboard
  (vertical layout, dark sidebar, dark topbar). Use when creating or
  changing Vue screens, layout, Tailwind styles, login, dashboard,
  tables, cards, or any Nuvora frontend UI.
---

# Nuvora Design

Visual source: the Velzon dark dashboard at [index-dark.html](https://themesbrand.com/velzon/html/default/index-dark.html).

That page sets `data-layout="vertical"`, `data-sidebar="dark"`, `data-topbar="light"`, and `data-bs-theme="dark"`. Reproduce that combination. Do not switch the sidebar to the indigo `#405189` variant and do not use the light content background `#f3f3f9`.

Implement the look with Tailwind 4 tokens in `frontend/src/app/styles.css`. Do not copy Themesbrand CSS, JavaScript, images, or fonts into the repository. Poppins is loaded from Google Fonts.

Read [tokens.md](tokens.md) before styling a screen.

## Shell

Authenticated screens use this frame:

1. Fixed sidebar, 250px, background `#212529`. Logo "Nuvora" at the top. Section labels in `#5f6270`. Items in `#7c7f90`. Hover and active items are white on `rgba(255, 255, 255, 0.15)`, with a 4px radius.
2. Fixed topbar, 70px, background `#292e32`, starting where the sidebar ends. Search field background `#202328`. Icon buttons `#31363c`. Title and user name `#e9ecef`.
3. Main region background `#1a1d21`, offset by the sidebar and topbar, padding `1.5rem`, bottom padding `60px`. On viewports under 768px, hide the sidebar behind a button in the topbar.
4. Footer text `#878a99` on `#212529`.

Auth screens (login, register) have no sidebar. Center a card on `#1a1d21`.

## Components

- Cards: background `#212529`, border `1px solid #32383e`, radius `0.25rem`, title `#ced4da`.
- Body text `#ced4da`. Muted text `#878a99`. Headings `#ced4da`, Poppins, weight 600.
- Primary button: background `#405189`, white text, radius `0.25rem`. Secondary button: `#3577f1`.
- Success `#0ab39c`, info `#299cdb`, warning `#f7b84b`, danger `#f06548`. Soft badges use the same hue at 15% opacity with the matching text-emphasis color from tokens.md.
- Inputs: background `#262a2f`, border `#2a2f34`, text `#ced4da`, radius `0.25rem`. Focus ring `#405189`. Labels weight 500.
- Tables: header `#2a2d31`, row `#212529`, border `#32383e`, hover `#25282c`.
- Dropdowns: background `#292e33`, item `#adb5bd`, hover `#2f343a`.
- Page title sits in the content, not inside the card. A primary action sits on the right of that title row.

## Product content

Use parking operations, not the ecommerce sample. Stat cards may show ocupación, sesiones, ingresos, and dispositivos. Empty, loading, error, and permission-denied states stay inside the same card frame.

Copy is Spanish. Keep business rules out of Vue components.

## Checks

- Colors match tokens.md, including dark body `#1a1d21` and dark sidebar `#212529`.
- Focus is visible and form errors use `#f06548` with `role="alert"`.
- Interactive controls have accessible names.
- No Velzon asset files or demo product names are added to the repo.
