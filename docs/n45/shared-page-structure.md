# Shared page structure

N45 pages keep ITFlow's PHP routes, permissions, forms, and Bootstrap behavior. The shared UI helpers in `functions/ui.php` centralize only the repeated presentation contract: hierarchy, safe attributes, actions, state navigation, context, and empty states.

## Page shapes

Use `n45RenderPageHeader()` in one of two modes:

- `variant => workspace` renders the compact dark header used by dense record lists. Place it inside `<section class="card n45-workspace">`, followed by a `.n45-filter-bar` and the result content.
- The default page variant renders breadcrumbs, a page lead, supporting text, status badges, client context, and actions. Use it for dashboards, detail pages, and finance pages with summary metrics above their result card.

```php
<section class="card n45-workspace" aria-labelledby="assets-page-title">
    <?php
    n45RenderPageHeader(array(
        'variant' => 'workspace',
        'title' => 'Assets',
        'title_id' => 'assets-page-title',
        'icon' => 'fa-desktop',
        'tabs' => $asset_page_tabs,
        'actions' => $asset_page_actions,
        'context' => array(
            'label' => $client_name_raw,
            'href' => 'client_overview.php?client_id=' . $client_id,
        ),
    ));
    ?>
    <div class="card-header n45-filter-bar">...</div>
    <div class="table-responsive">...</div>
</section>
```

## Primitives

- `n45RenderPageHeader()` — page identity, breadcrumbs, context, tabs, badges, and actions.
- `n45RenderPageTabs()` — compact semantic view/state navigation with `aria-current`.
- `n45RenderPageActions()` — primary, secondary, menu, and split-menu actions.
- `n45RenderClientContextIndicator()` — client scope without repeating sidebar navigation.
- `n45RenderStatusBadge()` — restrained semantic status.
- `n45RenderEmptyState()` — first-use and filtered-to-zero guidance.
- `n45RenderModalHeader()` — consistent modal title and labeled close control.

Action and tab configuration is plain data. Labels, URLs, classes, and allowed attributes are escaped or constrained by the renderer. Permission checks and business rules remain in the calling page; a renderer must never decide whether an action is authorized.

## Migration checklist

1. Keep existing query, permission, and POST behavior unchanged.
2. Move one primary action and related secondary actions into the header configuration.
3. Move view/status navigation into `tabs`; keep data filters in `.n45-filter-bar`.
4. Use a single page-level `h1` and connect the containing region with `aria-labelledby`.
5. Use `.n45-data-table` on the primary result table and the shared filter footer for result counts and pagination.
6. Use the shared empty state; distinguish no records from no filtered matches.
7. Preserve client scope in links and expose it with the context indicator.
8. Verify desktop density, narrow-screen stacking, keyboard focus, dark mode, destructive action color, and modal behavior.

The first migration set covers Assets, Contacts, Credentials, Projects, Project detail, Invoices, Networks, Racks, Global Search, and the Asset creation modal. Tickets and Clients already use the same CSS structure and can move to the renderer when their next functional change touches those headers.
