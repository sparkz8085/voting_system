<?php if (!defined('APP_NAME')) { http_response_code(403); exit('Forbidden.'); } ?>
            </main>

            <footer class="app-footer">
                <span>© <?= e(date('Y')) ?> <?= e(APP_NAME) ?> · Election office</span>
                <span class="app-footer__note">Academic voting demonstration · Actions are logged</span>
            </footer>
        </div>
    </div>

    <?php require __DIR__ . '/confirm_modal.php'; ?>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
