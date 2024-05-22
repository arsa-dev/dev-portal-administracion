@extends('layouts.app')
@section('content')
    <div class="poa-container">

        <h2>Listado de centros</h2>

        <div class="table-control-header">
            @include('partials.table-search', ['table' => 'centers-table'])

            <div class="flex gap-1">
                <div>
                    <button type="button" id="new-center-btn" class="btn-icon">
                        {{ e_heroicon('plus', 'outline') }}
                    </button>
                </div>
                <div>
                    <button type="button" class="btn-icon" id="btn-delete-center">
                        {{ e_heroicon('trash', 'outline') }}
                    </button>
                </div>
                <div>
                    <button type="button" class="btn-icon" id="btn-reload-table">
                        {{ e_heroicon('arrow-path', 'outline') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div id="centers-table"></div>
        </div>


        @include('partials.table-pagination', ['table' => 'centers-table'])

    </div>

    @include('administration.centers.center_modal')
    @include('partials.modal-confirmation')
@endsection
