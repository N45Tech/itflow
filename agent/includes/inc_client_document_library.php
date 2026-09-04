<?php

isset($mysqli, $client_id) && intval($client_id) > 0 || die('Client context required');
enforceUserPermission('module_support');
enforceClientAccess(intval($client_id));

$document_search = is_string($_GET['document_q'] ?? null) ? trim($_GET['document_q']) : '';
$document_search = substr($document_search, 0, 200);
$document_search_sql = mysqli_real_escape_string($mysqli, $document_search);
$library_where = "document_client_id = $client_id AND document_archived_at IS NULL
    AND (document_name LIKE '%$document_search_sql%' OR COALESCE(document_description, '') LIKE '%$document_search_sql%')";
$document_total = intval(mysqli_fetch_row(documentationDbQuery("SELECT COUNT(*) FROM documents
    WHERE $library_where", 'Could not count client documents'))[0]);
$document_page_size = 25;
$document_pages = max(1, (int) ceil($document_total / $document_page_size));
$document_page = max(1, min($document_pages, intval($_GET['document_page'] ?? 1)));
$document_offset = ($document_page - 1) * $document_page_size;
$client_documents = documentationDbQuery("SELECT document_id, document_name, document_description,
    document_client_visible, COALESCE(document_updated_at, document_created_at) AS changed_at
    FROM documents WHERE $library_where ORDER BY document_name, document_id
    LIMIT $document_page_size OFFSET $document_offset", 'Could not load client documents');
$document_page_url = static fn(int $page): string => 'documentation.php?' . http_build_query([
    'client_id' => $client_id, 'document_q' => $document_search, 'document_page' => $page,
]) . '#client-document-library';
?>

<section class="card mb-4" id="client-document-library" aria-labelledby="document-library-title">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h5 mb-0" id="document-library-title">Document library <span class="badge badge-light"><?= $document_total ?></span></h2>
        <?php if (lookupUserPermission('module_support') >= 2) { ?>
            <div class="btn-group">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-size="lg"
                    data-modal-url="modals/document/document_add.php?client_id=<?= $client_id ?>">New document</button>
                <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="More document creation options"></button>
                <div class="dropdown-menu dropdown-menu-end">
                    <button type="button" class="dropdown-item ajax-modal" data-modal-url="modals/document/document_add_from_template.php?client_id=<?= $client_id ?>">Create from template</button>
                    <button type="button" class="dropdown-item ajax-modal" data-modal-url="modals/file/file_upload.php?client_id=<?= $client_id ?>">Upload a file</button>
                </div>
            </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <form method="get" role="search" aria-label="Search client documents">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <label class="sr-only" for="client-document-search">Search documents</label>
                <div class="input-group">
                    <input type="search" class="form-control" id="client-document-search" name="document_q" maxlength="200" value="<?= escapeHtml($document_search) ?>" placeholder="Search documents">
                    <button class="btn btn-primary" type="submit">Search</button>
                </div>
            </form>
            <div class="d-flex flex-wrap gap-3">
                <a href="files.php?client_id=<?= $client_id ?>">All files &amp; folders</a>
                <a href="business_reviews.php?client_id=<?= $client_id ?>">Business reviews</a>
            </div>
        </div>
        <?php if (!$document_total) { ?>
            <div class="text-center py-4">
                <h3 class="h6"><?= $document_search !== '' ? 'No matching documents' : 'Start with the onboarding essentials' ?></h3>
                <p class="text-muted mb-2"><?= $document_search !== '' ? 'Try a different name or clear the search.' : 'Create a document or use a template for this client. Uploaded attachments remain in All files & folders.' ?></p>
                <?php if ($document_search !== '') { ?><a href="documentation.php?client_id=<?= $client_id ?>#client-document-library">Clear document search</a><?php } ?>
            </div>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th scope="col">Document</th><th scope="col">Updated</th><th scope="col">Visibility</th><th scope="col"><span class="sr-only">Open document</span></th></tr></thead>
                    <tbody>
                    <?php while ($document = mysqli_fetch_assoc($client_documents)) { ?>
                        <tr>
                            <td><a class="font-weight-bold" href="document.php?client_id=<?= $client_id ?>&document_id=<?= intval($document['document_id']) ?>"><?= escapeHtml($document['document_name']) ?></a>
                                <?php if (!empty($document['document_description'])) { ?><div class="small text-muted"><?= escapeHtml(truncate((string) $document['document_description'], 140)) ?></div><?php } ?></td>
                            <td class="text-nowrap"><?= empty($document['changed_at']) ? 'Not recorded' : escapeHtml(substr($document['changed_at'], 0, 10)) ?></td>
                            <td><?= intval($document['document_client_visible']) ? 'Portal visible' : 'Internal' ?></td>
                            <td class="text-right"><a class="btn btn-light" href="document.php?client_id=<?= $client_id ?>&document_id=<?= intval($document['document_id']) ?>" aria-label="Open <?= escapeHtml($document['document_name']) ?>">Open</a></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($document_pages > 1) { ?>
                <nav class="d-flex align-items-center justify-content-between mt-3" aria-label="Document library pages">
                    <?php if ($document_page > 1) { ?><a class="btn btn-light" href="<?= escapeHtml($document_page_url($document_page - 1)) ?>">Previous</a><?php } else { ?><span></span><?php } ?>
                    <span class="small">Page <?= $document_page ?> of <?= $document_pages ?></span>
                    <?php if ($document_page < $document_pages) { ?><a class="btn btn-light" href="<?= escapeHtml($document_page_url($document_page + 1)) ?>">Next</a><?php } else { ?><span></span><?php } ?>
                </nav>
            <?php } ?>
        <?php } ?>
    </div>
</section>
