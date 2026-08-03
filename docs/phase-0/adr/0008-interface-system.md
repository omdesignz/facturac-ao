# ADR 0008: Interface System

- Status: Accepted
- Date: 2026-08-02

## Context

The application must serve entrepreneurs and accounting teams, use the licensed Tailwind Plus catalogue, support dark mode and theming, and use Lucide icons and ECharts.

## Decision

Use Tailwind Plus Vue patterns as the structural source for shells, forms, tables, stats, progress, dialogs, and feedback. Adapt them into reusable Vue components using Tailwind CSS 4.3, Headless UI, `@lucide/vue`, Inertia 3, and Wayfinder.

The design language is a calm fiscal cockpit with Portuguese (`pt-AO`) copy, progressive disclosure, a deep mineral-green brand foundation, warm compliance accents, and explicit status text/icons. ECharts visualisations always have an accessible text/table summary. Application theme never changes legal print rendering.

## Consequences

- Copied Tailwind Plus components are normalised rather than edited independently on each page.
- Heroicons in source examples are consistently replaced with Lucide.
- Every component ships with light/dark, mobile, keyboard, focus, and reduced-motion consideration.

