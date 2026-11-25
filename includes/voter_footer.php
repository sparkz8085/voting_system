<?php if (!defined('APP_NAME')) { http_response_code(403); exit('Forbidden.'); } ?>
            </main>

            <footer class="app-footer app-footer--voter">
                <span>© <?= e(date('Y')) ?> <?= e(APP_NAME) ?> · Voter portal</span>
                <span class="app-footer__note">One voter, one ballot · Results are published after certification</span>
            </footer>
        </div>
    </div>

    <?php require __DIR__ . '/confirm_modal.php'; ?>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
