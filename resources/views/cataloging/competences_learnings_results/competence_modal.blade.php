<div id="competence-modal" class="modal">

    <div class="modal-body w-full md:max-w-[900px]">

        <div class="modal-header">
            <div>
                <h2 class="modal-title">Nueva competencia</h2>
            </div>

            <div>
                <button data-modal-id="competence-modal" class="modal-close-modal-btn close-modal-btn">
                    <?php e_heroicon('x-mark', 'solid'); ?>
                </button>
            </div>
        </div>

        <div class="poa-form mt-6">

            <form id="competence-form">
                <div class="field">
                    <div class="label-container label-center">
                        <label maxlength="255" for="name">Nombre <span class="text-danger">*</span></label>
                    </div>
                    <div class="content-container">
                        <input placeholder="Competencia" type="text" id="name" name="name"
                            class="poa-input" />
                    </div>
                </div>

                <div class="field">
                    <div class="label-container">
                        <label for="description">Descripción</label>
                    </div>

                    <div class="content-container">
                        <textarea placeholder="Descripción" rows="4" id="description" name="description" class="poa-input"></textarea>
                    </div>
                </div>

                <div class="btn-block">
                    <button type="submit" class="btn btn-primary">Guardar
                        {{ e_heroicon('paper-airplane', 'outline') }}</button>

                    <button data-modal-id="competence-modal" type="button" class="btn btn-secondary close-modal-btn">
                        Cancelar {{ e_heroicon('x-mark', 'outline') }}</button>
                </div>

                <input type="hidden" id="competence_uid" name="competence_uid" value="" />
                <input type="hidden" id="parent_competence_uid" name="parent_competence_uid" value="" />

            </form>
        </div>
    </div>

</div>
