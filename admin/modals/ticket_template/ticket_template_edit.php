<div class="modal" id="editTicketTemplateModal" tabindex="-1">

    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark">
                <h5 class="modal-title"><i class="fa fa-fw fa-life-ring me-2"></i>Editing Ticket Template: <?= $ticket_template_name ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="ticket_template_id" value="<?= $ticket_template_id ?>">

                <div class="modal-body">

                    <div class="mb-3">
                        <label>Template Name <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                                <span class="input-group-text"><i class="fa fa-fw fa-life-ring"></i></span>
                            <input type="text" class="form-control" name="name" maxlength="200" value="<?= $ticket_template_name ?>" placeholder="Template name" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Subject</label>
                        <div class="input-group">
                                <span class="input-group-text"><i class="fa fa-fw fa-angle-right"></i></span>
                            <input type="text" class="form-control" name="subject" maxlength="500" value="<?= $ticket_template_subject ?>" placeholder="Subject">
                        </div>
                    </div>

                    <div class="mb-3">
                        <textarea class="form-control tinymceTicket" name="details"><?= $ticket_template_details ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label>Description</label>
                        <div class="input-group">
                                <span class="input-group-text"><i class="fa fa-fw fa-angle-right"></i></span>
                            <input type="text" class="form-control" name="description" value="<?= $ticket_template_description ?>" placeholder="Short description">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Runbook Key</label>
                            <input type="text" class="form-control" name="runbook_key" maxlength="100" value="<?= $ticket_template_runbook_key ?>" required <?= mysqli_num_rows($sql_version_history) ? 'readonly' : '' ?>>
                            <?php if (mysqli_num_rows($sql_version_history)) { ?>
                                <small class="form-text text-muted">The stable key is locked after the first publication.</small>
                            <?php } ?>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Workflow Type</label>
                            <select class="form-control" name="runbook_type">
                                <option value="standard" <?= $ticket_template_runbook_type === 'standard' ? 'selected' : '' ?>>Standard</option>
                                <option value="onboarding" <?= $ticket_template_runbook_type === 'onboarding' ? 'selected' : '' ?>>Onboarding</option>
                                <option value="offboarding" <?= $ticket_template_runbook_type === 'offboarding' ? 'selected' : '' ?>>Offboarding</option>
                            </select>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="submit" name="edit_ticket_template" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
