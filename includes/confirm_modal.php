<?php if (!defined('APP_NAME')) { http_response_code(403); exit('Forbidden.'); } ?>
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content app-modal">
            <div class="modal-body">
                <span class="modal-icon modal-icon--warning" aria-hidden="true">
                    <i class="bi bi-exclamation-triangle"></i>
                </span>
                <h2 class="modal-title" id="confirmModalTitle">Confirm action</h2>
                <p class="modal-copy" data-confirm-message>Please confirm that you want to continue.</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" data-confirm-accept>Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>
