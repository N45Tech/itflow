<script src="js/file_delete_modal.js"></script>
<div class="modal" id="deleteFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body">
                <div class="mb-4" style="text-align: center;">
                    <i class="far fa-10x fa-times-circle text-danger mb-3 mt-3"></i>
                    <h2>Move file to Deleted Records?</h2>
                    <h6 class="mb-4 text-secondary">The file bytes will be quarantined; no linked or evidence file can enter this workflow.</h6>
                    <h5 class="mb-4 text-secondary text-bold" id="file_delete_name">Name</h5>
                    <form action="post.php" method="POST">
                        <input type="hidden" name="file_id" id="file_delete_id" value="id">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="form-group text-left">
                            <label for="file_delete_reason">Deletion reason</label>
                            <textarea class="form-control" id="file_delete_reason" name="delete_reason" minlength="10" maxlength="500" required placeholder="Owner-approved reason for moving this file to recoverable deletion"></textarea>
                            <small class="text-muted">The file is quarantined and remains recoverable during the configured restore window.</small>
                        </div>
                        <button type="button" name="cancel" class="btn btn-outline-secondary btn-lg px-5 mr-4" data-dismiss="modal">Cancel</button>
                        <input type="submit" name="delete_file" class="btn btn-danger btn-lg px-5" value="Move to Deleted Records">
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
