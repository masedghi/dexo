# Dexo design

Dexo brings the visual system of the original store operations panel to a standalone WooCommerce plugin. The public interface uses the DEXO wordmark and the current store’s name. It covers orders, products, posts, pages, media, packing slips and sign-in. Content navigation follows native WordPress capabilities and uses the same list and record-dialog pattern.

## Visual tokens

| Element | Value |
| --- | --- |
| Page canvas | `#f8f9f6` |
| Sidebar, top bar and content panels | `#ffffff` |
| Ink, headings and primary actions | `#142316` |
| Borders | `#e4e8dc` |
| Sage sign-in panel | `#eaf0e2` |
| Active navigation | `#ecf1e7` with `#254520` text |
| Focus outline and brand mark | `#b813b3` |
| Typography | Vazirmatn, 13 px body text, 28 px page titles and 12 px supporting controls |

Status badges use soft green for completed or in-stock, blue for processing, amber for pending or on-hold and terracotta for failed or cancelled. Keep the status text visible with each color. Vazirmatn is bundled under the [SIL Open Font License](assets/vazirmatn-OFL.txt).

## Layout

- The 244 px sidebar contains the wordmark, store card, store navigation and account footer. The 72 px top bar contains the breadcrumb, date and storefront link. The sidebar is on the right in Persian and on the left in English.
- The light workspace uses the same white surface for toolbar and list. Align controls, table headers, muted IDs, clickable record names and compact status badges consistently across order and product lists.
- Open order and product details in a bordered modal over the list. The URL points to the record so a refresh or form submission reopens it. Escape and the close control return to the list. Packing slips have a separate print view.
- At 760 px and below, the sidebar becomes a drawer with a backplate and the list renders as labelled rows. The two-column login becomes a single form column. Keyboard focus remains visible, and reduced-motion preferences disable the drawer transition.
- Desktop sign-in pairs a sage brand panel with the native WordPress form on an off-white background. The Dexo stylesheet is scoped to logins that return to Dexo.

The public plugin uses a local font and server-rendered views. Additive features should reuse the tokens and visible hierarchy described here. Keep navigation scoped to each user’s allowed sections, and show private order fields only when the existing capabilities allow them.
