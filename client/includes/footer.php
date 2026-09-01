<?php
/*
 * Client Portal
 * HTML Footer
 */
?>

            </div>
        </main>

        <footer class="n45-portal-footer">
            <span><?= escapeHtml($session_company_name) ?> client portal</span>
            <?php if (!$config_whitelabel_enabled) { ?>
                <small>Powered by ITFlow</small>
            <?php } ?>
        </footer>
    </div>
</div>

<button class="n45-portal-scrim" type="button" aria-label="Close navigation" tabindex="-1"></button>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_confirm_modal.php'; ?>

<script src="/libs/jquery/jquery.min.js"></script>
<script src="/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/libs/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
<script src="/js/client_portal.js"></script>
<script src="/js/pretty_content.js"></script>
<script src="/js/confirm_modal.js"></script>
<script src="/js/keepalive.js"></script>

<script>
    tinymce.init({
        selector: '.tinymce',
        browser_spellcheck: true,
        resize: true,
        min_height: 300,
        max_height: 600,
        promotion: false,
        branding: false,
        menubar: false,
        statusbar: false,
        license_key: 'gpl',
        toolbar: [
            { name: 'styles', items: [ 'styles' ] },
            { name: 'formatting', items: [ 'bold', 'italic', 'forecolor' ] },
            { name: 'lists', items: [ 'bullist', 'numlist' ] },
            { name: 'alignment', items: [ 'alignleft', 'aligncenter', 'alignright', 'alignjustify' ] },
            { name: 'indentation', items: [ 'outdent', 'indent' ] },
            { name: 'table', items: [ 'table' ] },
            { name: 'extra', items: [ 'fullscreen' ] }
        ],
        mobile: {
            menubar: false,
            plugins: 'autosave lists autolink',
            toolbar: 'undo bold italic styles'
        },
        plugins: 'link image lists table code codesample fullscreen autoresize'
    });
</script>

</body>
</html>
