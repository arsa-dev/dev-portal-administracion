<div id="notification-info-modal" data-action="" class="modal">
    <div class="modal-body w-full md:w-[600px]">
        <div class="text-center">
            <h2 class="modal-title" id="notification-title">
            </h2>
            <p id="notification-description">
            </p>
        </div>

        <div class="btn-block">
            <div id="entity-btn-container" class="hidden">
                <a id="entity-url" href="javascript:void(0)">
                    <button class="btn btn-primary min-w-[200px]">
                        <span id="entity-btn-label"></span>
                        {{ e_heroicon('arrow-up-right', 'outline') }}
                    </button>
                </a>
            </div>
            <div>
                <button data-modal-id="notification-info-modal"
                    class="btn btn-secondary w-[200px] close-modal-btn">Cerrar
                    {{ e_heroicon('x-mark', 'outline') }}</button>
            </div>
        </div>

    </div>
</div>
