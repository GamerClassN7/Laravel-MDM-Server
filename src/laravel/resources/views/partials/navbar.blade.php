<nav class="navbar navbar-main navbar-expand" style="z-index: 100;">
    <div class="container-xl">
        <a class="navbar-brand me-auto" href="{{ url('/') }}">
            <i class="fas fa-desktop me-2"></i>
            <span class="fw-semibold">{{ config('app.name', 'Laravel') }}</span>
        </a>

        {{-- <div>
            <div class="app-nav-profile random-bg-1">
                U
            </div>
        </div> --}}

        <button aria-expanded="false" aria-label="{{ __('Toggle navigation') }}"
            class="nav-toggler btn btn-light ml-2 d-xl-none" onclick="$('.layout-nav').toggleClass('layout-nav-open');"
            type="button"
        >
            <i class="fas fa-bars"></i>
        </button>
    </div>
</nav>
