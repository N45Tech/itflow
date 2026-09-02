<div class="modal" id="deleteClientModal<?= $client_id ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-body">
                <div class="mb-4" style="text-align: center;">
                    <i class="fas fa-8x fa-shield-alt text-warning mb-3 mt-3"></i>
                    <h2>Client deletion is retention-locked</h2>
                    <p class="mb-4 text-secondary">Archive <strong><?= escapeHtml($client_name) ?></strong> for offboarding. Permanent client teardown is disabled because it would bypass operational, evidence, approval, financial, and audit retention.</p>
                    <button type="button" class="btn btn-outline-secondary btn-lg px-5 mr-3" data-dismiss="modal">Close</button>
                    <?php if (!empty($session_is_admin)) { ?><a class="btn btn-warning btn-lg px-5" href="/admin/retention.php">Retention Center</a><?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>
