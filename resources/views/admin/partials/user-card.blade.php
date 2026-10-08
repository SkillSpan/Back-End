{{--
  Sidebar footer — the signed-in panel user's identity card.

  Extracted because it was byte-identical in organizations, projects and
  support, and because it is the one part of the sidebar that has to stay in
  step with the profile screen: the photo, the display name and the role all
  come from the same row the profile editor writes.

  WHY IT QUERIES THE PROFILE ITSELF
  ---------------------------------
  There is no shared layout in this panel — every page carries its own inline
  sidebar — so there is no controller to hand this partial a variable. It reads
  the relation directly instead, which is one indexed lookup on a unique column.
  A view composer would move the query without removing it, and would add a
  provider for one small card.

  `$panelCardLinksToProfile` is false on the profile page itself, where a link
  to the page you are already on is only an accessibility nuisance.

  Everything here is presentation. Nothing in this file decides what the
  account may open — that is {@see \App\Support\PanelAccess} and the route
  middleware.
--}}
@php
    $panelUser = auth()->user();
    $panelProfile = $panelUser?->adminProfile;

    $panelRoleLabel = \App\Support\PanelAccess::roleLabel($panelUser);

    // The person's own title if they set one, otherwise the role. Derived here
    // rather than stored so it can never disagree with the profile screen.
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
        {{-- The ids are what let the profile page update this card in place after
             a save, so changing your name or photo does not need a page reload.
             One sidebar per page, so they are unique. --}}
        <div class="avatar" id="nav-avatar">
            @if ($panelAvatarUrl)
                {{-- `alt` is empty on purpose: the name sits right beside it, and
                     a screen reader announcing the photo as well would just
                     repeat it. --}}
                <img src="{{ $panelAvatarUrl }}" alt="" id="nav-avatar-img">
            @else
                {{ strtoupper(substr($panelUser->name ?? 'A', 0, 1)) }}
            @endif
        </div>
        <div class="meta">
            <div class="nm" id="nav-user-name">{{ $panelUser->name }}</div>
            {{-- The role replaces the truncated email that used to sit here —
                 it is what a person wants to see at a glance, and the email is
                 still one hover away and in full on the profile page. --}}
            <div class="rl" id="nav-user-role" title="{{ $panelUser->email }}">{{ $panelDisplayName }}</div>
        </div>
    </{{ $panelCardLinksToProfile ? 'a' : 'div' }}>
</div>
