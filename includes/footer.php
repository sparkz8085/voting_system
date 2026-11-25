<?php if (!defined('APP_NAME')) { http_response_code(403); exit('Forbidden.'); } ?>
    <footer class="site-footer">
        <div class="container">
            <div class="site-footer__top">
                <div class="site-footer__brand">
                    <a class="brand brand--footer" href="<?= e(url()) ?>">
                        <img src="<?= e(asset('img/logo-mark.svg')) ?>" alt="" width="38" height="38">
                        <span><?= e(APP_NAME) ?></span>
                    </a>
                    <p>A clear, accountable way for campus communities to choose their representatives.</p>
                    <span class="site-footer__tag"><i class="bi bi-mortarboard" aria-hidden="true"></i> Academic project</span>
                </div>
                <div class="site-footer__links">
                    <div>
                        <h2>For voters</h2>
                        <a href="<?= e(url('auth/register.php')) ?>">Create an account</a>
                        <a href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                        <a href="<?= e(url('voter/results.php')) ?>">Published results</a>
                    </div>
                    <div>
                        <h2>For officials</h2>
                        <a href="<?= e(url('auth/admin-login.php')) ?>">Administrator portal</a>
                        <a href="<?= e(url('auth/admin-login.php')) ?>">Election management</a>
                        <a href="mailto:<?= e(APP_SUPPORT_EMAIL) ?>">Election support</a>
                    </div>
                    <div>
                        <h2>About</h2>
                        <a href="#how-it-works">How it works</a>
                        <a href="#integrity">Integrity notes</a>
                        <a href="#elections">Current elections</a>
                    </div>
                </div>
            </div>
            <div class="site-footer__bottom">
                <p>© <?= e(date('Y')) ?> <?= e(APP_NAME) ?>. Built with core PHP, MySQL, and Bootstrap.</p>
                <p class="site-footer__disclaimer">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    An academic demonstration. It does not provide independently verified anonymity or end-to-end verifiability, and is not suitable for governmental elections.
                </p>
            </div>
        </div>
    </footer>

    <script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
