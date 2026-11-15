
@php
    $panelUser = auth()->user();
    $panelProfile = $panelUser?->adminProfile;

    $panelRoleLabel = \App\Support\PanelAccess::roleLabel($panelUser);

    $panelDisplayName = $panelProfile?->display_title ?: $panelRoleLabel;

    $panelAvatarVersion = $panelProfile?->avatarVersion();
    $panelAvatarUrl = $panelAvatarVersion === null
        ? null
        : route('admin.profile.avatar', ['v' => $panelAvatarVersion]);

    $panelCardLinksToProfile = $panelCardLinksToProfile ?? true;
@endphp

<div class="sidebar-foot">
    <{{ $panelCardLinksToProfile ? 'a' : 'div' }}
        class="user-card"
        @if ($panelCardLinksToProfile) href="{{ route('admin.profile') }}" title="Open your profile" @endif
    >

        <div class="avatar" id="nav-avatar">
            @if ($panelAvatarUrl)

                <img src="{{ $panelAvatarUrl }}" alt="" id="nav-avatar-img">
            @else
                {{ strtoupper(substr($panelUser->name ?? 'A', 0, 1)) }}
            @endif
        </div>
        <div class="meta">
            <div class="nm" id="nav-user-name">{{ $panelUser->name }}</div>

            <div class="rl" id="nav-user-role" title="{{ $panelUser->email }}">{{ $panelDisplayName }}</div>
        </div>
    </{{ $panelCardLinksToProfile ? 'a' : 'div' }}>
</div>
