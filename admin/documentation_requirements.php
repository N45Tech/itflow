<?php

require_once 'includes/inc_all_admin.php';

$sql_requirements = mysqli_query($mysqli, "SELECT r.*,
    v.documentation_requirement_version_number,
    v.documentation_requirement_version_definition_hash,
    v.documentation_requirement_version_name,
    v.documentation_requirement_version_record_type,
    v.documentation_requirement_version_review_cadence_days,
    v.documentation_requirement_version_blocks_readiness,
    v.documentation_requirement_version_blocks_ticket_resolution,
    v.documentation_requirement_version_created_at,
    u.user_name AS published_by_name,
    (SELECT COUNT(*) FROM client_documentation_obligations o
        WHERE o.documentation_obligation_requirement_id = r.documentation_requirement_id) AS obligation_count
    FROM documentation_requirements r
    LEFT JOIN documentation_requirement_versions v
        ON v.documentation_requirement_version_id = r.documentation_requirement_published_version_id
    LEFT JOIN users u ON u.user_id = v.documentation_requirement_version_created_by
    ORDER BY r.documentation_requirement_archived_at IS NOT NULL,
        COALESCE(v.documentation_requirement_version_name, r.documentation_requirement_key)");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-book-medical mr-2"></i>Documentation Requirements</h3>
        <div class="card-tools"><button type="button" class="btn btn-primary ajax-modal" data-modal-size="lg" data-modal-url="modals/documentation_requirement/documentation_requirement.php"><i class="fas fa-plus mr-1"></i>New Requirement</button></div>
    </div>
    <div class="card-body">
        <div class="alert alert-info"><i class="fas fa-info-circle mr-2"></i>Drafts remain editable. Publishing creates an immutable version; existing client obligations keep their historical version until reconciliation deliberately advances them.</div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Requirement</th><th>Lifecycle</th><th>Policy</th><th>Published</th><th>Coverage</th><th class="text-right">Action</th></tr></thead>
                <tbody>
                <?php while ($requirement = mysqli_fetch_assoc($sql_requirements)) {
                    $requirement_id = intval($requirement['documentation_requirement_id']);
                    $revision = intval($requirement['documentation_requirement_revision']);
                    $draft = json_decode((string) $requirement['documentation_requirement_draft_definition'], true) ?: [];
                    $name = $requirement['documentation_requirement_version_name'] ?: ($draft['name'] ?? $requirement['documentation_requirement_key']);
                    $is_archived = !empty($requirement['documentation_requirement_archived_at']);
                    $draft_hash = $draft && function_exists('documentationRequirementDefinitionHash')
                        ? documentationRequirementDefinitionHash($draft)
                        : '';
                    $has_unpublished_changes = !$is_archived && intval($requirement['documentation_requirement_version_number']) > 0
                        && $draft_hash !== ''
                        && !hash_equals((string) $requirement['documentation_requirement_version_definition_hash'], $draft_hash);
                    ?>
                    <tr>
                        <td><strong><?= escapeHtml($name) ?></strong><div class="small text-muted"><code><?= escapeHtml($requirement['documentation_requirement_key']) ?></code> · revision <?= $revision ?></div></td>
                        <td><span class="badge badge-<?= $is_archived ? 'secondary' : ($requirement['documentation_requirement_lifecycle'] === 'Active' ? 'success' : 'warning') ?>"><?= escapeHtml($is_archived ? 'Archived' : $requirement['documentation_requirement_lifecycle']) ?></span><?php if ($has_unpublished_changes) { ?><div><span class="badge badge-warning mt-1">Draft changes</span></div><?php } ?></td>
                        <td><?= escapeHtml($requirement['documentation_requirement_version_record_type'] ?: ($draft['record_type'] ?? '—')) ?><div class="small text-muted"><?= intval($requirement['documentation_requirement_version_review_cadence_days'] ?: ($draft['review_cadence_days'] ?? 0)) ?> day review</div></td>
                        <td><?php if (intval($requirement['documentation_requirement_version_number'])) { ?>v<?= intval($requirement['documentation_requirement_version_number']) ?><div class="small text-muted"><?= escapeHtml($requirement['documentation_requirement_version_created_at']) ?></div><?php } else { ?><span class="text-muted">Draft only</span><?php } ?></td>
                        <td><?= intval($requirement['obligation_count']) ?> client obligation<?= intval($requirement['obligation_count']) === 1 ? '' : 's' ?></td>
                        <td class="text-right">
                            <button class="btn btn-sm btn-outline-primary ajax-modal" data-modal-size="lg" data-modal-url="modals/documentation_requirement/documentation_requirement.php?id=<?= $requirement_id ?>"><i class="fas fa-edit"></i></button>
                            <?php if (!$is_archived) { ?>
                                <form action="post.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="requirement_id" value="<?= $requirement_id ?>"><input type="hidden" name="expected_revision" value="<?= $revision ?>">
                                    <button class="btn btn-sm btn-success confirm-link" name="publish_documentation_requirement" title="Publish immutable version"><i class="fas fa-upload"></i></button>
                                    <button class="btn btn-sm btn-outline-warning confirm-link" name="archive_documentation_requirement" title="Archive requirement"><i class="fas fa-archive"></i></button>
                                </form>
                            <?php } else { ?>
                                <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="requirement_id" value="<?= $requirement_id ?>"><input type="hidden" name="expected_revision" value="<?= $revision ?>"><button class="btn btn-sm btn-outline-success" name="restore_documentation_requirement" title="Restore requirement"><i class="fas fa-undo"></i></button></form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!mysqli_num_rows($sql_requirements)) { ?><tr><td colspan="6" class="text-center text-muted py-5">No documentation requirements have been authored.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
