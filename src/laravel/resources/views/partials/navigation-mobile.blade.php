<div class="layout-nav-mobile">
    <ul class="app-nav nav">
        @foreach (\SteelAnts\LaravelBoilerplate\Facades\Menu::get('main-menu')?->items() ?? [] as $item)
            <li class="nav-item nav-item-mobile {{ ($item->isActive() || $item->isUse()) ? 'is-active' : '' }}">
                <a class="nav-link" href="{{ route($item->route) }}">
                    <i class="nav-link-ico {{ $item->icon }}"></i>
                    <span class="nav-link-content">{{ __($item->title) }}</span>
                </a>
            </li>
        @endforeach
        <li class="nav-item nav-item-mobile {{ request()->routeIs('profile.*') ? 'is-active' : '' }}">
            <a class="nav-link" href="{{ route('profile.index') }}">
                <i class="nav-link-ico fas fa-user"></i>
                <span class="nav-link-content">{{ __('Profile') }}</span>
            </a>
        </li>
    </ul>
</div>
