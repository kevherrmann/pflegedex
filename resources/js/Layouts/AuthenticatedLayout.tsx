import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link, router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';

type NavItem = { label: string; href: string; active: string; icon: string };
type NavGroup = { label: string; items: NavItem[] };

// Heroicons-Outline-Pfade (24x24, single path).
const ICON_PATHS: Record<string, string> = {
    dashboard:
        'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
    calendar:
        'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 00-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
    sun: 'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z',
    star: 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z',
    users: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    residents:
        'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    document:
        'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    building:
        'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    key: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z',
    audit: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
    profile:
        'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
    logout: 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H2.25',
    menu: 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
    chevronLeft: 'M15.75 19.5L8.25 12l7.5-7.5',
    chevronRight: 'M8.25 4.5l7.5 7.5-7.5 7.5',
};

function NavIcon({ name }: { name: string }) {
    return (
        <svg
            className="h-5 w-5 shrink-0"
            fill="none"
            viewBox="0 0 24 24"
            strokeWidth={1.7}
            stroke="currentColor"
        >
            <path strokeLinecap="round" strokeLinejoin="round" d={ICON_PATHS[name] ?? ''} />
        </svg>
    );
}

function NavRow({
    item,
    collapsed,
    onNavigate,
}: {
    item: NavItem;
    collapsed: boolean;
    onNavigate: () => void;
}) {
    const active = route().current(item.active);

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            title={collapsed ? item.label : undefined}
            className={
                'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition ' +
                (collapsed ? 'justify-center ' : '') +
                (active
                    ? 'bg-[#9B1C3B]/10 text-[#9B1C3B]'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900')
            }
        >
            <NavIcon name={item.icon} />
            {!collapsed && <span className="truncate">{item.label}</span>}
        </Link>
    );
}

function SidebarContent({
    collapsed,
    onNavigate,
    showToggle,
    onToggle,
    dashboardItem,
    navGroups,
    userName,
    userEmail,
}: {
    collapsed: boolean;
    onNavigate: () => void;
    showToggle: boolean;
    onToggle: () => void;
    dashboardItem: NavItem;
    navGroups: NavGroup[];
    userName: string;
    userEmail: string;
}) {
    return (
        <div className="flex h-full flex-col">
            <div
                className={
                    'flex h-16 shrink-0 items-center border-b border-gray-100 px-4 ' +
                    (collapsed ? 'justify-center' : 'gap-2')
                }
            >
                <Link href="/" onClick={onNavigate} className="flex items-center gap-2">
                    <ApplicationLogo className="block h-8 w-auto fill-current text-gray-800" />
                    {!collapsed && (
                        <span className="text-lg font-semibold text-gray-800">Pflegedex</span>
                    )}
                </Link>
            </div>

            <nav className="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
                <NavRow item={dashboardItem} collapsed={collapsed} onNavigate={onNavigate} />

                {navGroups.map((group) => (
                    <div key={group.label}>
                        {collapsed ? (
                            <div className="my-2 border-t border-gray-100" />
                        ) : (
                            <p className="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {group.label}
                            </p>
                        )}
                        {group.items.map((item) => (
                            <NavRow
                                key={item.href}
                                item={item}
                                collapsed={collapsed}
                                onNavigate={onNavigate}
                            />
                        ))}
                    </div>
                ))}
            </nav>

            {showToggle && (
                <button
                    type="button"
                    onClick={onToggle}
                    title={collapsed ? 'Ausklappen' : 'Einklappen'}
                    className={
                        'hidden items-center gap-3 border-t border-gray-100 px-3 py-2 text-sm font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 lg:flex ' +
                        (collapsed ? 'justify-center' : '')
                    }
                >
                    <NavIcon name={collapsed ? 'chevronRight' : 'chevronLeft'} />
                    {!collapsed && <span>Einklappen</span>}
                </button>
            )}

            <div className="border-t border-gray-100 p-2">
                {!collapsed && (
                    <div className="px-2 pb-2">
                        <div className="truncate text-sm font-medium text-gray-800">{userName}</div>
                        <div className="truncate text-xs text-gray-500">{userEmail}</div>
                    </div>
                )}
                <NavRow
                    item={{ label: 'Profil', href: route('profile.edit'), active: 'profile.edit', icon: 'profile' }}
                    collapsed={collapsed}
                    onNavigate={onNavigate}
                />
                <button
                    type="button"
                    onClick={() => {
                        onNavigate();
                        router.post(route('logout'));
                    }}
                    title={collapsed ? 'Abmelden' : undefined}
                    className={
                        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 hover:text-red-700 ' +
                        (collapsed ? 'justify-center' : '')
                    }
                >
                    <NavIcon name="logout" />
                    {!collapsed && <span>Abmelden</span>}
                </button>
            </div>
        </div>
    );
}

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth } = usePage().props;
    const user = auth.user;

    const canUseAbsenceRequests =
        auth.permissions.canViewAbsenceRequests ||
        auth.permissions.canManageAbsenceRequests;
    const absenceRequestsRoute = auth.permissions.canManageAbsenceRequests
        ? 'absence-requests.manage'
        : 'absence-requests.index';

    const [collapsed, setCollapsed] = useState<boolean>(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem('sidebar-collapsed') === '1';
    });
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        window.localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0');
    }, [collapsed]);

    const dashboardItem: NavItem = {
        label: 'Dashboard',
        href: route('dashboard'),
        active: 'dashboard',
        icon: 'dashboard',
    };

    const dienstplan: NavItem[] = [];
    if (auth.permissions.canManageAbsenceRequests) {
        dienstplan.push(
            { label: 'Dienstpläne', href: route('rosters.index'), active: 'rosters.*', icon: 'calendar' },
            { label: 'Schichten', href: route('shift-templates.index'), active: 'shift-templates.*', icon: 'clock' },
            { label: 'Urlaubssperren', href: route('roster-blackout-days.index'), active: 'roster-blackout-days.*', icon: 'lock' },
            { label: 'Wunschdienste', href: route('shift-wishes.index'), active: 'shift-wishes.*', icon: 'star' },
        );
    }
    if (canUseAbsenceRequests) {
        dienstplan.push({ label: 'Urlaub', href: route(absenceRequestsRoute), active: 'absence-requests.*', icon: 'sun' });
    }
    if (auth.permissions.manageStaff) {
        dienstplan.push({ label: 'Mitarbeiter', href: route('staff.index'), active: 'staff.index', icon: 'users' });
    }

    const pflege: NavItem[] = [];
    if (auth.permissions.viewResidents) {
        pflege.push({ label: 'Bewohner', href: route('residents.index'), active: 'residents.index', icon: 'residents' });
    }
    if (auth.permissions.manageCareReports) {
        pflege.push({ label: 'Pflegeberichte', href: route('care-reports.index'), active: 'care-reports.index', icon: 'document' });
    }

    const verwaltung: NavItem[] = [];
    if (auth.permissions.manageLocations) {
        verwaltung.push({ label: 'Wohnbereiche', href: route('locations.index'), active: 'locations.index', icon: 'building' });
    }
    if (auth.permissions.managePdlAccounts) {
        verwaltung.push({ label: 'PDL-Konten', href: route('users.index'), active: 'users.index', icon: 'key' });
    }
    if (auth.permissions.viewAuditLog) {
        verwaltung.push({ label: 'Audit-Log', href: route('audit.index'), active: 'audit.index', icon: 'audit' });
    }

    const navGroups: NavGroup[] = [
        { label: 'Dienstplan', items: dienstplan },
        { label: 'Pflege', items: pflege },
        { label: 'Verwaltung', items: verwaltung },
    ].filter((group) => group.items.length > 0);

    const closeMobile = () => setMobileOpen(false);

    return (
        <div className="min-h-screen bg-gray-100">
            {/* Desktop-Seitenleiste */}
            <aside
                className={
                    'fixed inset-y-0 left-0 z-40 hidden border-r border-gray-200 bg-white transition-[width] duration-200 lg:flex lg:flex-col ' +
                    (collapsed ? 'lg:w-16' : 'lg:w-64')
                }
            >
                <SidebarContent
                    collapsed={collapsed}
                    onNavigate={() => {}}
                    showToggle
                    onToggle={() => setCollapsed((previous) => !previous)}
                    dashboardItem={dashboardItem}
                    navGroups={navGroups}
                    userName={user.name}
                    userEmail={user.email}
                />
            </aside>

            {/* Mobiles Off-Canvas-Menü */}
            {mobileOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="fixed inset-0 bg-black/40"
                        onClick={closeMobile}
                        aria-hidden
                    />
                    <aside className="fixed inset-y-0 left-0 w-64 bg-white shadow-xl">
                        <SidebarContent
                            collapsed={false}
                            onNavigate={closeMobile}
                            showToggle={false}
                            onToggle={() => {}}
                            dashboardItem={dashboardItem}
                            navGroups={navGroups}
                            userName={user.name}
                            userEmail={user.email}
                        />
                    </aside>
                </div>
            )}

            {/* Inhaltsspalte */}
            <div
                className={
                    'transition-[padding] duration-200 ' +
                    (collapsed ? 'lg:pl-16' : 'lg:pl-64')
                }
            >
                {/* Mobile Topbar */}
                <div className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-gray-200 bg-white px-4 lg:hidden">
                    <button
                        type="button"
                        onClick={() => setMobileOpen(true)}
                        aria-label="Menü öffnen"
                        className="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                    >
                        <NavIcon name="menu" />
                    </button>
                    <Link href="/" className="flex items-center gap-2">
                        <ApplicationLogo className="block h-7 w-auto fill-current text-gray-800" />
                        <span className="font-semibold text-gray-800">Pflegedex</span>
                    </Link>
                </div>

                {header && (
                    <header className="bg-white shadow">
                        <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {header}
                        </div>
                    </header>
                )}

                <main>{children}</main>
            </div>
        </div>
    );
}
