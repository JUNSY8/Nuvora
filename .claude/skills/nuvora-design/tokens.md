# Velzon dark tokens

Source page: https://themesbrand.com/velzon/html/default/index-dark.html

Attributes on that page: `data-layout="vertical"`, `data-sidebar="dark"`, `data-topbar="light"`, `data-bs-theme="dark"`, `data-sidebar-size="lg"`.

## Tailwind theme

Put these in `frontend/src/app/styles.css` and use the token names in components.

```css
@import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap");
@import "tailwindcss";

@theme {
  --font-sans: "Poppins", sans-serif;
  --radius-vz: 0.25rem;

  --color-vz-primary: #405189;
  --color-vz-secondary: #3577f1;
  --color-vz-success: #0ab39c;
  --color-vz-info: #299cdb;
  --color-vz-warning: #f7b84b;
  --color-vz-danger: #f06548;

  --color-vz-body: #1a1d21;
  --color-vz-text: #ced4da;
  --color-vz-muted: #878a99;
  --color-vz-heading: #ced4da;
  --color-vz-card: #212529;
  --color-vz-elevated: #2a2d31;
  --color-vz-border: #32383e;

  --color-vz-sidebar: #212529;
  --color-vz-sidebar-item: #7c7f90;
  --color-vz-sidebar-title: #5f6270;
  --color-vz-sidebar-active: #ffffff;

  --color-vz-topbar: #292e32;
  --color-vz-topbar-item: #e9ecef;
  --color-vz-topbar-button: #31363c;
  --color-vz-topbar-search: #202328;

  --color-vz-input: #262a2f;
  --color-vz-input-border: #2a2f34;
  --color-vz-dropdown: #292e33;
  --color-vz-dropdown-item: #adb5bd;
  --color-vz-dropdown-hover: #2f343a;
  --color-vz-footer: #212529;
}
```

## Measures

| Element | Value |
| --- | --- |
| Sidebar width | 250px |
| Topbar height | 70px |
| Content padding | 1.5rem, bottom 60px |
| Card and control radius | 0.25rem |
| Sidebar active background | rgba(255, 255, 255, 0.15) |
| Card shadow | 0 1px 2px rgba(56, 65, 74, 0.15) |

## Emphasis text on dark

| Role | Color |
| --- | --- |
| Primary | #6674a1 |
| Secondary | #5d92f4 |
| Success | #3bc2b0 |
| Info | #54b0e2 |
| Warning | #f9c66f |
| Danger | #f3846d |

Soft backgrounds use the brand hue at 15% opacity: primary `rgba(64, 81, 137, 0.15)`, success `rgba(10, 179, 156, 0.15)`, warning `rgba(247, 184, 75, 0.15)`, danger `rgba(240, 101, 72, 0.15)`.
