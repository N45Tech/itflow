<?php

/*
 * Shared N45 interface primitives.
 *
 * These helpers intentionally render server-side Bootstrap/AdminLTE markup.
 * They keep page structure, escaping and accessible labels consistent without
 * moving navigation, permissions or form behavior out of the owning page.
 */

function n45UiClassNames($class_names)
{
    $tokens = preg_split('/\s+/', trim((string) $class_names));
    $safe_tokens = array();

    foreach ($tokens as $token) {
        if ($token !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            $safe_tokens[] = $token;
        }
    }

    return implode(' ', array_unique($safe_tokens));
}

function n45UiIconClass($icon)
{
    $icon = n45UiClassNames($icon);
    return $icon !== '' ? $icon : 'fa-circle';
}

function n45UiAttributes(array $attributes)
{
    $html = '';

    foreach ($attributes as $name => $value) {
        $name = strtolower((string) $name);
        $is_allowed = in_array($name, array('id', 'type', 'title', 'target', 'rel', 'role', 'name', 'value', 'disabled'), true)
            || strpos($name, 'aria-') === 0
            || strpos($name, 'data-') === 0;

        if (!$is_allowed || !preg_match('/^[a-z][a-z0-9_-]*$/', $name) || $value === false || $value === null) {
            continue;
        }

        if ($value === true) {
            $html .= ' ' . $name;
            continue;
        }

        $html .= ' ' . $name . '="' . escapeHtml((string) $value) . '"';
    }

    return $html;
}

function n45UiActionClass(array $action)
{
    $variant = $action['variant'] ?? 'secondary';
    $allowed_variants = array('primary', 'secondary', 'outline-primary', 'outline-secondary', 'dark', 'danger', 'outline-danger', 'warning');
    if (!in_array($variant, $allowed_variants, true)) {
        $variant = 'secondary';
    }

    $size = ($action['size'] ?? '') === 'sm' ? ' btn-sm' : '';
    $extra = n45UiClassNames($action['class'] ?? '');

    return 'btn btn-' . $variant . $size . ($extra !== '' ? ' ' . $extra : '');
}

function n45UiActionContent(array $action)
{
    $icon = $action['icon'] ?? '';
    $label = $action['label'] ?? '';
    $icon_html = $icon !== ''
        ? '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($icon)) . ($label !== '' ? ' me-2' : '') . '" aria-hidden="true"></i>'
        : '';

    return $icon_html . ($label !== '' ? '<span>' . escapeHtml($label) . '</span>' : '');
}

function n45UiActionHtml(array $action)
{
    $type = $action['type'] ?? 'link';
    if ($type === 'menu' || $type === 'split-menu') {
        $button = $action['button'] ?? $action;
        $items = $action['items'] ?? array();
        $menu_label = $action['menu_label'] ?? ('More ' . strtolower($button['label'] ?? '') . ' actions');
        $menu_alignment = !empty($action['align_end']) ? ' dropdown-menu-end' : '';

        $html = '<div class="btn-group">';
        if ($type === 'split-menu') {
            $button['type'] = $button['tag'] ?? 'button';
            $html .= n45UiActionHtml($button);
            $html .= '<button type="button" class="' . escapeHtml(n45UiActionClass($button)) . ' dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="' . escapeHtml($menu_label) . '"></button>';
        } else {
            $attributes = $button['attributes'] ?? array();
            $attributes['data-bs-toggle'] = 'dropdown';
            $attributes['aria-expanded'] = 'false';
            $html .= '<button type="button" class="' . escapeHtml(n45UiActionClass($button)) . ' dropdown-toggle"' . n45UiAttributes($attributes) . '>' . n45UiActionContent($button) . '</button>';
        }

        $html .= '<div class="dropdown-menu' . $menu_alignment . '">';
        foreach ($items as $item) {
            if (($item['type'] ?? '') === 'separator') {
                $html .= '<div class="dropdown-divider" role="separator"></div>';
                continue;
            }

            $item_class = 'dropdown-item ' . n45UiClassNames($item['class'] ?? '');
            $item_attributes = $item['attributes'] ?? array();
            $href = escapeHtml($item['href'] ?? '#');
            $html .= '<a class="' . escapeHtml(trim($item_class)) . '" href="' . $href . '"' . n45UiAttributes($item_attributes) . '>' . n45UiActionContent($item) . '</a>';
        }
        $html .= '</div></div>';

        return $html;
    }

    $attributes = $action['attributes'] ?? array();
    $tag = $type === 'button' ? 'button' : 'a';
    if ($tag === 'button' && !isset($attributes['type'])) {
        $attributes['type'] = 'button';
    }

    $href = $tag === 'a' ? ' href="' . escapeHtml($action['href'] ?? '#') . '"' : '';
    return '<' . $tag . $href . ' class="' . escapeHtml(n45UiActionClass($action)) . '"' . n45UiAttributes($attributes) . '>' . n45UiActionContent($action) . '</' . $tag . '>';
}

function n45RenderPageTabs(array $tabs, $aria_label = 'Page sections')
{
    if (!$tabs) {
        return;
    }

    echo '<nav class="n45-status-tabs" aria-label="' . escapeHtml($aria_label) . '">';
    foreach ($tabs as $tab) {
        if (isset($tab['visible']) && !$tab['visible']) {
            continue;
        }

        $current = !empty($tab['active']) ? ' aria-current="page"' : '';
        $icon = !empty($tab['icon']) ? '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($tab['icon'])) . '" aria-hidden="true"></i>' : '';
        $count = isset($tab['count']) ? '<strong>' . escapeHtml((string) $tab['count']) . '</strong> ' : '';
        echo '<a href="' . escapeHtml($tab['href'] ?? '#') . '"' . $current . '>' . $icon . $count . escapeHtml($tab['label'] ?? '') . '</a>';
    }
    echo '</nav>';
}

function n45UiClientContextHtml(array $context)
{
    if (empty($context['label'])) {
        return '';
    }

    $icon = $context['icon'] ?? 'fa-building';
    $content = '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($icon)) . '" aria-hidden="true"></i><span>' . escapeHtml($context['label']) . '</span>';
    if (!empty($context['href'])) {
        return '<a class="n45-client-context" href="' . escapeHtml($context['href']) . '">' . $content . '</a>';
    }

    return '<span class="n45-client-context">' . $content . '</span>';
}

function n45RenderClientContextIndicator(array $context)
{
    echo n45UiClientContextHtml($context);
}

function n45RenderPageActions(array $actions)
{
    if (!$actions) {
        return;
    }

    echo '<div class="n45-page-actions card-tools d-print-none">';
    foreach ($actions as $action) {
        if (isset($action['visible']) && !$action['visible']) {
            continue;
        }
        echo n45UiActionHtml($action);
    }
    echo '</div>';
}

function n45RenderBreadcrumbs(array $breadcrumbs)
{
    if (!$breadcrumbs) {
        return;
    }

    echo '<nav aria-label="Breadcrumb" class="d-print-none"><ol class="breadcrumb n45-breadcrumb">';
    foreach ($breadcrumbs as $index => $breadcrumb) {
        $is_last = $index === array_key_last($breadcrumbs);
        if ($is_last || empty($breadcrumb['href'])) {
            echo '<li class="breadcrumb-item active" aria-current="page">' . escapeHtml($breadcrumb['label'] ?? '') . '</li>';
        } else {
            echo '<li class="breadcrumb-item"><a href="' . escapeHtml($breadcrumb['href']) . '">' . escapeHtml($breadcrumb['label'] ?? '') . '</a></li>';
        }
    }
    echo '</ol></nav>';
}

function n45RenderPageHeader(array $config)
{
    $variant = $config['variant'] ?? 'page';
    $title = $config['title'] ?? '';
    $title_id = $config['title_id'] ?? '';
    $icon = $config['icon'] ?? '';
    $description = $config['description'] ?? '';
    $tabs = $config['tabs'] ?? array();
    $actions = $config['actions'] ?? array();
    $badges = $config['badges'] ?? array();
    $context = $config['context'] ?? array();
    $title_attributes = $title_id !== '' ? ' id="' . escapeHtml($title_id) . '"' : '';
    $icon_html = $icon !== '' ? '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($icon)) . ' me-2" aria-hidden="true"></i>' : '';

    if ($variant === 'workspace') {
        echo '<header class="card-header n45-workspace-header">';
        echo '<div class="n45-workspace-heading"><div class="n45-page-title-row"><h1 class="n45-workspace-title"' . $title_attributes . '>' . $icon_html . escapeHtml($title) . '</h1>';
        foreach ($badges as $badge) {
            echo n45UiStatusBadgeHtml($badge['label'] ?? '', $badge['tone'] ?? 'secondary', $badge['icon'] ?? '');
        }
        echo '</div>';
        if ($context) {
            echo n45UiClientContextHtml($context);
        }
        n45RenderPageTabs($tabs, $config['tabs_label'] ?? ($title . ' views'));
        echo '</div>';
        n45RenderPageActions($actions);
        echo '</header>';
        return;
    }

    n45RenderBreadcrumbs($config['breadcrumbs'] ?? array());
    echo '<header class="n45-page-lead">';
    echo '<div><div class="n45-page-title-row"><h1' . $title_attributes . '>' . $icon_html . escapeHtml($title) . '</h1>';
    foreach ($badges as $badge) {
        echo n45UiStatusBadgeHtml($badge['label'] ?? '', $badge['tone'] ?? 'secondary', $badge['icon'] ?? '');
    }
    if ($context) {
        echo n45UiClientContextHtml($context);
    }
    echo '</div>';
    if ($description !== '') {
        echo '<p>' . escapeHtml($description) . '</p>';
    }
    echo '</div>';
    if ($actions) {
        echo '<div class="n45-page-lead-actions">';
        foreach ($actions as $action) {
            if (!isset($action['visible']) || $action['visible']) {
                echo n45UiActionHtml($action);
            }
        }
        echo '</div>';
    }
    echo '</header>';
}

function n45UiStatusBadgeHtml($label, $tone = 'secondary', $icon = '')
{
    $allowed_tones = array('primary', 'secondary', 'success', 'warning', 'danger', 'info', 'dark', 'light');
    if (!in_array($tone, $allowed_tones, true)) {
        $tone = 'secondary';
    }

    $icon_html = $icon !== '' ? '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($icon)) . ' me-1" aria-hidden="true"></i>' : '';
    return '<span class="badge text-bg-' . escapeHtml($tone) . ' n45-status-badge">' . $icon_html . escapeHtml($label) . '</span>';
}

function n45RenderStatusBadge($label, $tone = 'secondary', $icon = '')
{
    echo n45UiStatusBadgeHtml($label, $tone, $icon);
}

function n45RenderEmptyState(array $config)
{
    $title = $config['title'] ?? 'Nothing here yet';
    $description = $config['description'] ?? '';
    $icon = $config['icon'] ?? 'fa-inbox';
    $action = $config['action'] ?? null;

    echo '<div class="n45-empty-state text-center py-5 px-3" role="status">';
    echo '<i class="fa fa-3x ' . escapeHtml(n45UiIconClass($icon)) . ' mb-3 d-block" aria-hidden="true"></i>';
    echo '<h2 class="h5">' . escapeHtml($title) . '</h2>';
    if ($description !== '') {
        echo '<p class="small mb-' . ($action ? '3' : '0') . '">' . escapeHtml($description) . '</p>';
    }
    if ($action) {
        echo n45UiActionHtml($action);
    }
    echo '</div>';
}

function n45RenderModalHeader($title, $icon = '', array $config = array())
{
    $theme = ($config['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $close_class = $theme === 'dark' ? ' btn-close-white' : '';
    $icon_html = $icon !== '' ? '<i class="fa fa-fw ' . escapeHtml(n45UiIconClass($icon)) . ' me-2" aria-hidden="true"></i>' : '';

    echo '<div class="modal-header n45-modal-header bg-' . $theme . '">';
    echo '<h2 class="modal-title fs-5">' . $icon_html . escapeHtml($title) . '</h2>';
    echo '<button type="button" class="btn-close' . $close_class . '" data-bs-dismiss="modal" aria-label="Close"></button>';
    echo '</div>';
}
